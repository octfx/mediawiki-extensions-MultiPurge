<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Specials;

use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\PurgeUrlPolicy;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use OOUIHTMLForm;
use PermissionsError;
use Throwable;

class SpecialPurgeResources extends SpecialPage {
	public function __construct() {
		// MW < 1.46 reads the restriction from the constructor instead of getRestriction()
		if ( version_compare( MW_VERSION, '1.46', '<' ) ) {
			parent::__construct( 'PurgeResources', $this->getRestriction() );
		} else {
			parent::__construct( 'PurgeResources' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getRestriction(): string {
		return 'editinterface';
	}

	/**
	 * @param string $sub
	 * @throws PermissionsError
	 */
	public function execute( $sub ) {
		$this->checkPermissions();
		$out = $this->getOutput();

		$out->setPageTitleMsg( $this->msg( 'multipurge-form-title' ) );

		$formDescriptor = [
			'target' => [
				'section' => 'title',
				'class' => 'HTMLTextField',
				'required' => true,
				'default' => $sub ?: null,
			],
		];

		$showPurge = false;
		$selects = [];
		// A failed option rebuild must not turn a purge into a load redirect.
		$purgeSubmission = $this->getRequest()->wasPosted()
			&& $this->getRequest()->getVal( 'wpmode' ) === 'purge';

		if ( $sub ) {
			$content = $this->loadContent( $sub );

			if ( $content !== false ) {
				$selects = $this->makeSelects( $this->parseLoads( $content ) );
				if ( $selects === [] && !$purgeSubmission ) {
					$out->addWikiMsg( 'multipurge-special-no-resources' );
				}
			} else {
				$out->prependHTML( $this->msg( 'multipurge-special-invalid-title' )->escaped() );
			}
		}

		if ( $selects !== [] || $purgeSubmission ) {
			$formDescriptor = array_merge( $formDescriptor, $selects );
			// Marker separating the purge submission from a load submission, which redirects instead.
			$formDescriptor['mode'] = [
				'type' => 'hidden',
				'default' => 'purge',
			];
			$showPurge = true;
		}

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'purge-resources-form' );

		if ( $showPurge ) {
			// Post to the subpage so a submission is validated against the same groups it was offered.
			$htmlForm->setAction( $this->getPageTitle( $sub )->getFullURL() );
			$htmlForm->setSubmitText( $this->msg( 'multipurge-special-purge-submit' )->plain() );
		} else {
			$htmlForm->setSubmitText( $this->msg( 'multipurge-special-load-submit' )->plain() );
		}

		if ( $purgeSubmission && $selects !== [] && $this->hasStaleSelections( $selects ) ) {
			// MediaWiki ignores submitted fields absent from the rebuilt form.
			$htmlForm->prepareForm();
			$htmlForm->displayForm( Status::newFatal( 'multipurge-special-purge-stale-selection' ) );
			return;
		}

		$htmlForm->setSubmitCallback( [ __CLASS__, 'trySubmit' ] );

		$result = $htmlForm->show();

		if ( $result ) {
			// show() renders a good result as nothing, so the outcome text is added here.
			$out->addWikiMsg( 'multipurge-special-purge-success' );
		}
	}

	/**
	 * Whether the request carries selections for a group the rebuilt form no longer offers.
	 * Those values would never be loaded or validated, so the submission must be rejected outright.
	 *
	 * @param array $selects Rebuilt select descriptors, keyed by group
	 * @return bool
	 */
	private function hasStaleSelections( array $selects ): bool {
		foreach ( [ 'styles', 'scripts', 'thumbs', 'statics' ] as $group ) {
			$submitted = $this->getRequest()->getArray( 'wp' . $group, [] );
			if ( $submitted && !isset( $selects[$group] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $sub
	 * @return false|string
	 */
	private function loadContent( string $sub ) {
		if ( !$sub ) {
			return false;
		}

		$title = MediaWikiServices::getInstance()->getTitleFactory()->newFromText( $sub );

		if ( $title === null || !$title->exists() ) {
			return false;
		}

		$content = MediaWikiServices::getInstance()->getHttpRequestFactory()->get(
			$title->getFullURL( '', false, PROTO_HTTPS ),
			[],
			__METHOD__
		);

		return $content ?? false;
	}

	/**
	 * @param array $formData
	 * @param OOUIHTMLForm $form
	 * @return Status|void
	 */
	public static function trySubmit( $formData, $form ) {
		// Only the purge form carries the mode marker; a load submission redirects to the resource view.
		if ( ( $formData['mode'] ?? '' ) !== 'purge' ) {
			$form->getOutput()->redirect(
				MediaWikiServices::getInstance()->getTitleFactory()
					->makeTitle( NS_SPECIAL, sprintf( 'PurgeResources/%s', $formData['target'] ) )
					->getFullUrlForRedirect()
			);

			return;
		}

		// No select group could be rebuilt, so the submission has nothing validated to act on and must not redirect.
		if ( !array_key_exists( 'styles', $formData ) && !array_key_exists( 'scripts', $formData )
			&& !array_key_exists( 'thumbs', $formData ) && !array_key_exists( 'statics', $formData )
		) {
			return Status::newFatal( 'multipurge-special-purge-unavailable' );
		}

		$services = MediaWikiServices::getInstance();
		$policy = PurgeUrlPolicy::newFromConfigs(
			$services->getMainConfig(),
			$services->getConfigFactory()->makeConfig( 'MultiPurge' )
		);

		$selected = array_filter( array_merge(
			$formData['styles'] ?? [],
			$formData['scripts'] ?? [],
			$formData['thumbs'] ?? [],
			// Keep the legacy decoding for configured statics. Parsed HTML is already decoded.
			array_map( 'html_entity_decode', $formData['statics'] ?? [] ),
		) );
		$selected = array_map( [ self::class, 'rootResourcePath' ], $selected );

		// Count unique rejected selections so targets in several groups are not counted twice.
		$urls = $policy->resolveList( $selected, $rejectedCount );

		// This channel logs counts only; a target path or query can carry credentials.
		// MediaWiki's HTTP client still logs the request URLs itself, as documented.
		wfDebugLog(
				'MultiPurge',
				sprintf(
					'Purging %d urls from the special page (%d rejected by the host policy)',
					count( $urls ),
					$rejectedCount
				)
		);

		if ( !$urls ) {
			if ( $rejectedCount > 0 ) {
				// Every selection was rejected, which is a different outcome from an empty selection.
				return Status::newFatal( 'multipurge-special-purge-rejected', $rejectedCount );
			}
			return Status::newFatal( 'multipurge-special-no-urls' );
		}

		$serviceOrder = MultiPurgeJob::getServiceOrder();
		$group = MediaWikiServices::getInstance()->getJobQueueGroupFactory()->makeJobQueueGroup();
		$queuedForRetry = false;
		$retryLost = false;
		$idleServices = 0;
		$droppedTargets = [];

		foreach ( $serviceOrder as $service ) {
			$job = new MultiPurgeJob( [
				'urls' => $urls,
				'service' => $service,
			] );

			try {
				$status = $job->run();
			} catch ( Throwable $e ) {
				wfDebugLog( 'MultiPurge', sprintf( 'Job failed: %s', $e->getMessage() ) );
				$status = false;
			}

			if ( $status ) {
				// The replacement covers failed requests, not targets omitted by the original run.
				if ( $job->didHandOffRetry() ) {
					$queuedForRetry = true;
					$droppedTargets = array_merge( $droppedTargets, $job->getDroppedTargets() );
					continue;
				}

				// A returning-success run may have sent nothing at all: a hook veto or the post-hook policy emptied it.
				if ( $job->didSendPurgeRequests() ) {
					// A sent batch can still omit originals a hook removed or the policy rejected after the hook.
					$droppedTargets = array_merge( $droppedTargets, $job->getDroppedTargets() );
				} else {
					$idleServices++;
				}
				continue;
			}

			// A retry can drop the same targets, so report omissions even when the run fails.
			$droppedTargets = array_merge( $droppedTargets, $job->getDroppedTargets() );

			// An inline run gets no queue retry of its own, so queue just this failed service.
			// Services that succeeded are not re-run.
			try {
				$group->push( $job );
				$queuedForRetry = true;
			} catch ( Throwable $e ) {
				LoggerFactory::getInstance( 'MultiPurge' )->error(
					'Purge by {service} failed and queueing a retry was rejected: {error}',
					[ 'service' => $service, 'error' => $e->getMessage() ]
				);
				$retryLost = true;
			}
		}

		// Two services can omit the same selected url; the operator selected it once, so count distinct targets.
		$droppedCount = count( array_unique( $droppedTargets ) );

		if ( $retryLost ) {
			if ( $queuedForRetry ) {
				// Neither message describes a partial retry handoff.
				$status = Status::newFatal( 'multipurge-special-purge-retry-mixed' );
			} else {
				$status = Status::newFatal( 'multipurge-special-purge-error' );
			}
		} elseif ( $queuedForRetry ) {
			$status = Status::newFatal( 'multipurge-special-purge-retry' );
		} elseif ( !$serviceOrder ) {
			// Nothing ran, so neither success nor a purge count may be claimed.
			$status = Status::newFatal( 'multipurge-special-no-services' );
		} elseif ( $idleServices > 0 ) {
			// Claiming success would pretend a purge happened while no or not every service sent a request.
			// A veto or emptied run stays unqueued: retrying cannot change the hook or the policy.
			$status = Status::newFatal( 'multipurge-special-purge-no-work', $idleServices );
			$idleServices = 0;
		} elseif ( $droppedCount > 0 ) {
			// Part of the selection was silently removed after submission, so not all selected targets were sent.
			$status = Status::newFatal( 'multipurge-special-purge-omitted', $droppedCount );
			$droppedCount = 0;
		} elseif ( $rejectedCount > 0 ) {
			// Claiming plain success would pretend the rejected selections were purged too.
			// A policy rejection is permanent, so nothing was queued for it.
			return Status::newFatal( 'multipurge-special-purge-partial', count( $urls ), $rejectedCount );
		} else {
			// A good Status is what HTMLForm recognizes as success; the outcome text is shown by execute().
			return Status::newGood();
		}

		if ( $idleServices > 0 ) {
			// A retry or queue error must not read as if a service that sent nothing had been queued.
			$status->fatal( 'multipurge-special-purge-no-work', $idleServices );
		}
		if ( $droppedCount > 0 ) {
			// Stays worded as "was not sent": a queued retry may drop the same target again, so no resend is promised.
			$status->fatal( 'multipurge-special-purge-omitted', $droppedCount );
		}
		if ( $rejectedCount > 0 ) {
			// The permanent rejection stays visible next to the execution outcome above instead of being hidden by it.
			$status->fatal( 'multipurge-special-purge-rejected', $rejectedCount );
		}
		return $status;
	}

	/**
	 * @param string $content
	 * @return array
	 */
	private function parseLoads( string $content ): array {
		$loadScript = preg_quote( $this->getConfig()->get( MainConfigNames::LoadScript ), '~' );
		// Core emits linked stylesheets without a self-closing slash; legacy markup may still carry one.
		// Anchors can point at the load script too, so only a stylesheet link counts as a style resource.
		preg_match_all(
			'~<link\b[^>]*\srel="stylesheet"[^>]*\shref="(' . $loadScript . '\?[^"]*)"/?>~',
			$content,
			$styles
		);
		preg_match_all( '~src="(' . $loadScript . '\?[^"]*)"></script>~', $content, $scripts );
		// Actual parser output puts src first and may omit alt, so match src anywhere inside the img tag.
		preg_match_all( '/<img\b[^>]*\ssrc="([^"]+)"/', $content, $images );

		$decode = static fn ( string $url ): string => html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$data = [
			'scripts' => array_map( $decode, $scripts[1] ?? [] ),
			'styles' => array_map( $decode, $styles[1] ?? [] ),
			'thumbs' => array_map( $decode, $images[1] ?? [] ),
		];

		wfDebugLog(
			'MultiPurge',
			sprintf(
				'Parsed - Scripts: %d; Styles %d; Thumbs %d',
				count( $data['scripts'] ),
				count( $data['styles'] ),
				count( $data['thumbs'] )
			)
		);

		return $data;
	}

	private static function rootResourcePath( string $url ): string {
		// Preserve the special page's root-relative interpretation of bare paths.
		return str_starts_with( $url, '/' ) || preg_match( '/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $url ) ? $url : '/' . $url;
	}

	/**
	 * @param array $loads
	 * @return array[]
	 */
	private function makeSelects( array $loads ): array {
		$services = MediaWikiServices::getInstance();
		$mainConfig = $services->getMainConfig();
		if ( !empty( $loads['thumbs'] ) ) {
			$policy = PurgeUrlPolicy::newFromConfigs(
				$mainConfig,
				$services->getConfigFactory()->makeConfig( 'MultiPurge' )
			);
			$loads['thumbs'] = array_filter( $loads['thumbs'],
				static fn ( string $url ): bool => $policy->resolve( self::rootResourcePath( $url ) ) !== null
			);
		}

		$statics = $mainConfig->get( 'MultiPurgeStaticPurges' );
		if ( !is_array( $statics ) ) {
			$statics = [];
		}

		// A numeric entry has no label, so the url doubles as its own option label.
		foreach ( $statics as $label => $url ) {
			if ( is_int( $label ) ) {
				unset( $statics[$label] );
				$statics[$url] = $url;
			}
		}

		$selects = [];

		foreach ( [ 'styles', 'scripts', 'thumbs' ] as $group ) {
			if ( empty( $loads[$group] ) ) {
				continue;
			}

			$selects[$group] = [
				'section' => 'loads',
				'class' => 'HTMLMultiSelectField',
				'label-message' => sprintf( 'multipurge-%s-label', $group ),
				// HTMLForm treats option labels as HTML, while the values must keep the decoded URL.
				'options' => array_combine( array_map( 'htmlspecialchars', $loads[$group] ), $loads[$group] ),
			];
		}

		// Statics are operator targets, so they are offered even when the page itself has no loads.
		if ( $statics !== [] ) {
			$selects['statics'] = [
				'section' => 'loads',
				'class' => 'HTMLMultiSelectField',
				'label-message' => 'multipurge-static-label',
				'options' => $statics,
			];
		}

		return $selects;
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'other';
	}
}
