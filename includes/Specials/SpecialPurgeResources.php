<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Specials;

use MediaWiki\Config\ConfigException;
use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\PurgeUrlPolicy;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
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
	 * Show the page to the user
	 *
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

		if ( $sub ) {
			$content = $this->loadContent( $sub );

			if ( $content !== false ) {
				$formDescriptor = array_merge( $formDescriptor, $this->makeSelects( $this->parseLoads( $content ) ) );
				$showPurge = true;
			} else {
				$out->prependHTML( $this->msg( 'multipurge-special-invalid-title' )->escaped() );
			}
		}

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'purge-resources-form' );

		if ( $showPurge ) {
			$htmlForm->setSubmitText( $this->msg( 'multipurge-special-purge-submit' )->plain() );
		} else {
			$htmlForm->setSubmitText( $this->msg( 'multipurge-special-load-submit' )->plain() );
		}

		$htmlForm->setSubmitCallback( [ __CLASS__, 'trySubmit' ] );

		$htmlForm->show();
	}

	/**
	 * Load the actual raw page content for a given title
	 * Returns false on failure
	 *
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
	 * @return Message|Status|void
	 */
	public static function trySubmit( $formData, $form ) {
		if ( !isset( $formData['styles'] ) ) {
			$form->getOutput()->redirect(
				MediaWikiServices::getInstance()->getTitleFactory()
					->makeTitle( NS_SPECIAL, sprintf( 'PurgeResources/%s', $formData['target'] ) )
					->getFullUrlForRedirect()
			);

			return;
		}

		$services = MediaWikiServices::getInstance();
		$policy = PurgeUrlPolicy::newFromConfigs(
			$services->getMainConfig(),
			$services->getConfigFactory()->makeConfig( 'MultiPurge' )
		);

		$decode = static function ( string $url ): string {
			// Form values come from parsed HTML, where & is entity-encoded.
			$url = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			// Legacy static entries without a leading slash are root-relative.
			if ( !str_starts_with( $url, '/' ) && !preg_match( '/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $url ) ) {
				$url = '/' . $url;
			}
			return $url;
		};

		$selected = array_map( $decode, array_filter( array_merge(
			$formData['styles'] ?? [],
			$formData['scripts'] ?? [],
			$formData['thumbs'] ?? [],
			$formData['statics'] ?? [],
		) ) );

		// One pass: valid targets are canonicalized and deduplicated, rejections are logged, counts come back.
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
				continue;
			}

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

		if ( $retryLost ) {
			$status = Status::newFatal( 'multipurge-special-purge-error' );
		} elseif ( $queuedForRetry ) {
			$status = Status::newFatal( 'multipurge-special-purge-retry' );
		} elseif ( !$serviceOrder ) {
			// Nothing ran, so neither success nor a purge count may be claimed.
			$status = Status::newFatal( 'multipurge-special-no-services' );
		} elseif ( $rejectedCount > 0 ) {
			// Claiming plain success would pretend the rejected selections were purged too.
			// A policy rejection is permanent, so nothing was queued for it.
			return Status::newFatal( 'multipurge-special-purge-partial', count( $urls ), $rejectedCount );
		} else {
			return new Message( 'multipurge-special-purge-success' );
		}

		if ( $rejectedCount > 0 ) {
			// The permanent rejection stays visible next to the execution outcome above instead of being hidden by it.
			$status->fatal( 'multipurge-special-purge-rejected', $rejectedCount );
		}
		return $status;
	}

	/**
	 * Parse the page content and extract load.php calls and images
	 *
	 * @param string $content
	 * @return array
	 */
	private function parseLoads( string $content ): array {
		// Styles
		$styleCount = preg_match_all( '/href="(\/load.php\?.*)"\/>/U', $content, $styles );

		// Scripts
		$scriptCount = preg_match_all( '/src="(\/load.php\?.*)"><\/script>/U', $content, $scripts );

		// Thumbs
		$imageCount = preg_match_all( '/<img alt=".+" src="(.*)"/U', $content, $images );

		if ( $styleCount !== false && $styleCount > 0 ) {
			array_shift( $styles );
			$styles = array_filter( $styles[0] ?? [] );
		} else {
			$styles = [];
		}

		if ( $scriptCount !== false && $scriptCount > 0 ) {
			array_shift( $scripts );
			$scripts = array_filter( $scripts[0] ?? [] );
		} else {
			$scripts = [];
		}

		if ( $imageCount !== false && $imageCount > 0 ) {
			array_shift( $images );
			$images = array_filter( $images[0] ?? [] );
		} else {
			$images = [];
		}

		$data = [
			'scripts' => $scripts,
			'styles' => $styles,
			'images' => $images,
		];

		wfDebugLog(
			'MultiPurge',
			sprintf(
				'Parsed - Scripts: %d; Styles %d; Thumbs %d',
				count( $data['scripts'] ),
				count( $data['styles'] ),
				count( $data['images'] )
			)
		);

		return $data;
	}

	/**
	 * Map the loads to form select
	 *
	 * @param array $loads
	 * @return array[]
	 */
	private function makeSelects( array $loads ): array {
		try {
			$statics = MediaWikiServices::getInstance()->getMainConfig()->get( 'MultiPurgeStaticPurges' );
		} catch ( ConfigException ) {
			$statics = [];
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
				'options' => array_combine( $loads[$group], $loads[$group] ),
			];
		}

		if ( $selects ) {
			// Use path as key
			if ( !is_string( array_keys( $statics )[0] ) ) {
				$selects = array_combine( $statics, $statics );
			}

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
