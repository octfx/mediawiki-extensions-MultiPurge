<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Hooks;

use File;
use JobQueueGroup;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\PurgeEventRelayer;
use MediaWiki\Hook\EditPage__attemptSave_afterHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\ArticlePurgeHook;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\DerivativeContext;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Utils\UrlUtils;
use ReflectionObject;
use Throwable;
use WikiFilePage;
use WikiPage;

/**
 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
 */
class PurgeHooks implements ArticlePurgeHook, EditPage__attemptSave_afterHook {

	private Config $config;
	private JobQueueGroup $group;
	private ResourceLoader $rl;
	private UrlUtils $utils;

	/**
	 * @param Config $config
	 * @param JobQueueGroup $group
	 * @param ResourceLoader $rl
	 * @param UrlUtils $utils
	 */
	public function __construct(
		Config $config,
		JobQueueGroup $group,
		ResourceLoader $rl,
		UrlUtils $utils
	) {
		$this->config = $config;
		$this->group = $group;
		$this->rl = $rl;
		$this->utils = $utils;
	}

	/**
	 * @param Config $config
	 * @param JobQueueGroup $group
	 * @param ResourceLoader $rl
	 * @param UrlUtils $utils
	 * @return self
	 */
	public static function factory(
		Config $config,
		JobQueueGroup $group,
		ResourceLoader $rl,
		UrlUtils $utils
	): self {
		return new self( $config, $group, $rl, $utils );
	}

	/**
	 * @param WikiPage $wikiPage
	 * @return void
	 */
	public function onArticlePurge( $wikiPage ): void {
		// Listing thumbnails scans the file backend, so with no enabled service it must not run at all.
		$serviceOrder = MultiPurgeJob::getServiceOrder();
		if ( !$serviceOrder ) {
			// Disabled stays silent; an enabled-but-unusable selection loses every purge and must be reported.
			$this->reportUnusableServiceConfig();
			return;
		}

		$urls = [];

		if ( $wikiPage instanceof WikiFilePage && $wikiPage->getTitle()->getNamespace() === NS_FILE ) {
			$file = $wikiPage->getFile();
			$files = $this->getThumbnails( $file );
			// LocalFile puts the backend directory before the thumbnail names.
			array_shift( $files );
			$urls = $this->linkThumbnails( $files, $file );
		} elseif ( !$this->coreRelaysTitleUrlsToMultiPurge() ) {
			// Core only relays title urls to the cdn-url-purges owner, so a foreign owner needs this direct dispatch.
			$urls = MediaWikiServices::getInstance()->getHtmlCacheUpdater()
				->getUrls( $wikiPage->getTitle() );
		}

		// When the check above passed, core relays the title urls through this extension's relayer after this hook.
		$this->buildSiteModuleUrl( $wikiPage->getTitle(), $urls );

		$this->runPurge( $urls, $serviceOrder );
	}

	/**
	 * Whether core relays title urls to this extension's cdn-url-purges relayer.
	 * EventRelayerGroup snapshots its channel config when first built, so a stale group silently bypasses MultiPurge.
	 * Asking the live service matches exactly what core later relays through.
	 *
	 * @return bool
	 */
	private function coreRelaysTitleUrlsToMultiPurge(): bool {
		try {
			$relayer = MediaWikiServices::getInstance()->getEventRelayerGroup()
				->getRelayer( 'cdn-url-purges' );
		} catch ( Throwable $e ) {
			// Core has not invalidated the page yet, so the lookup must not abort the purge.
			wfDebugLog( 'MultiPurge', 'Falling back to direct title purges: ' . $e->getMessage() );
			return false;
		}

		return $relayer instanceof PurgeEventRelayer;
	}

	/**
	 * This is only here to purge site styles
	 *
	 * @see PurgeEventRelayer
	 * @param EditPage $editpage_Obj
	 * @param Status $status
	 * @param array $resultDetails
	 * @return void
	 */
	public function onEditPage__attemptSave_after( $editpage_Obj, $status, $resultDetails ) {
		if ( $status->isGood() ) {
			$urls = [];
			$this->buildSiteModuleUrl( $editpage_Obj->getTitle(), $urls );

			$this->runPurge( $urls );
		}
	}

	private function getThumbnails( File $file ): array {
		// Core has no public method for listing existing thumbnails.
		return ( new ReflectionObject( $file ) )->getMethod( 'getThumbnails' )->invoke( $file );
	}

	/**
	 * Collects file targets while supporting repositories configured with bare paths.
	 *
	 * @param array $files
	 * @param File $baseFile
	 * @return array
	 */
	private function linkThumbnails( array $files, File $baseFile ): array {
		$url = $this->config->get( 'Server' );
		$uploadPath = $this->config->get( 'UploadPath' );
		$parsed = parse_url( $uploadPath );

		if ( $parsed !== false && isset( $parsed['host'] ) ) {
			$url = $uploadPath;
		}

		$urls = [];
		foreach ( $files as $thumb ) {
			$urls[] = $this->expandBareFilePath( $url, $baseFile->getThumbUrl( $thumb ) );
		}

		$urls[] = $this->expandBareFilePath( $url, $baseFile->getUrl() );

		return $urls;
	}

	/**
	 * Core can return bare file paths, which the purge policy cannot resolve on its own.
	 * Leave rooted and scheme-bearing URLs untouched so the policy can resolve or reject them.
	 *
	 * @param string $base
	 * @param string $url
	 * @return string
	 */
	private function expandBareFilePath( string $base, string $url ): string {
		if ( str_starts_with( $url, '/' ) || preg_match( '/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $url ) ) {
			return $url;
		}

		return "$base/$url";
	}

	/**
	 * @param array $urls
	 * @param string[]|null $serviceOrder
	 */
	private function runPurge( array $urls, ?array $serviceOrder = null ): void {
		if ( !$urls ) {
			return;
		}

		wfDebugLog( 'MultiPurge', 'Running Job from PurgeHooks' );

		$serviceOrder ??= MultiPurgeJob::getServiceOrder();
		if ( !$serviceOrder ) {
			$this->reportUnusableServiceConfig();
			return;
		}

		$urls = MultiPurgeJob::filterDispatchUrls( $urls );
		if ( !$urls ) {
			return;
		}

		$validServiceRan = false;
		$invalidServices = [];

		foreach ( $serviceOrder as $service ) {
			$job = new MultiPurgeJob( [ 'urls' => $urls, 'service' => $service ] );

			if ( $this->config->get( 'MultiPurgeRunInQueue' ) === true ) {
				$this->queueAfterInvalidation( $job, $service, 'Queueing the {service} purge was rejected: {error}' );
			} else {
				try {
					$status = $job->run( handOffRetry: false );
				} catch ( Throwable $e ) {
					wfDebugLog( 'MultiPurge', sprintf( 'Job failed: %s', $e->getMessage() ) );
					$status = false;
				}

				$jobInvalidServices = $job->getInvalidServices();
				$invalidServices = array_merge( $invalidServices, $jobInvalidServices );
				// A failed or thrown run dispatched a usable service; a success counts unless it skipped its service.
				$validServiceRan = $validServiceRan || ( $status ? $jobInvalidServices === [] : true );

				if ( $status ) {
					// A returning success may have sent nothing through a veto, an empty batch, or an unusable service.
					// Logging plain success would claim a purge that did not happen.
					wfDebugLog( 'MultiPurge', $job->didSendPurgeRequests()
						? 'Job Status: success'
						: 'Job Status: sent no purge requests'
					);
					continue;
				}

				// An inline run gets no queue retry of its own, so queue the failed service job for retry.
				// Retry timing and attempt limits stay with the configured queue backend.
				$this->queueAfterInvalidation(
					$job,
					$service,
					'Purge by {service} failed and queueing a retry was rejected: {error}',
					isRetry: true
				);
			}
		}

		// The lost-purge error is reserved for a configuration where every dispatched service was unusable.
		// A valid service that failed keeps its own retry handoff and must not be reported as lost.
		if ( $invalidServices !== [] && !$validServiceRan ) {
			LoggerFactory::getInstance( 'MultiPurge' )->error(
				'No purge service could run; nothing was purged or queued for: {services}',
				[ 'services' => implode( ', ', $invalidServices ) ]
			);
		}
	}

	/**
	 * Makes an enabled-but-unusable selection observable as a lost purge, because nothing can ever run or be queued.
	 * An intentionally disabled extension (no enabled services) logs nothing.
	 *
	 * @return void
	 */
	private function reportUnusableServiceConfig(): void {
		$enabled = $this->config->get( 'MultiPurgeEnabledServices' );
		if ( !$enabled ) {
			return;
		}

		LoggerFactory::getInstance( 'MultiPurge' )->error(
			'No purge service could run; nothing was purged or queued for: {services}',
			[ 'services' => implode( ', ', $enabled ) ]
		);
	}

	/**
	 * Enqueue a service job after the invalidation of the purge that triggered it, when possible.
	 * In web requests, deferred updates always wait until the end of the request, past the invalidation commit.
	 * In CLI with no open transaction, MediaWiki may run deferred updates immediately, so this order is not guaranteed.
	 * A job visible to workers before the invalidation can refill caches with the stale content being purged.
	 * DeferredUpdates is MediaWiki's own deferred mechanism, so no custom scheduling is added.
	 *
	 * @param MultiPurgeJob $job
	 * @param string $service
	 * @param string $message Log message with {service} and {error} placeholders
	 * @param bool $isRetry Whether the job already attempted the purge.
	 * @return void
	 */
	private function queueAfterInvalidation(
		MultiPurgeJob $job, string $service, string $message, bool $isRetry = false
	): void {
		DeferredUpdates::addCallableUpdate( function () use ( $job, $service, $message, $isRetry ): void {
			try {
				if ( $isRetry && $job->tryHandOffRetry() ) {
					wfDebugLog( 'MultiPurge', 'Job Status: error, retry handed off' );
					return;
				}
				$this->group->push( $job );
				if ( $isRetry ) {
					wfDebugLog( 'MultiPurge', 'Job Status: error, retry scheduled' );
				}
			} catch ( Throwable $e ) {
				LoggerFactory::getInstance( 'MultiPurge' )->error(
					$message,
					[ 'service' => $service, 'error' => $e->getMessage() ]
				);
			}
		} );
	}

	/**
	 * This manually builds the url for the site.styles module
	 * Gets called after an edit to a MediaWiki:*.css page
	 *
	 * @param Title $title
	 * @param array &$urls
	 * @return void
	 */
	private function buildSiteModuleUrl( Title $title, array &$urls ): void {
		if ( $title->getNamespace() !== NS_MEDIAWIKI || substr( $title->getText(), -4 ) !== '.css' ) {
			return;
		}

		$request = RequestContext::getMain();

		$rlContext = new Context(
			$this->rl,
			$request->getRequest()
		);

		$derive = new DerivativeContext( $rlContext );
		$derive->setModules( [ 'site.styles' ] );
		$derive->setLanguage( $this->config->get( 'LanguageCode' ) );
		$derive->setSkin( $request->getSkin()->getSkinName() );
		$derive->setOnly( 'styles' );

		$url = $this->rl->createLoaderURL( 'local', $derive );

		$urls[] = $this->utils->expand( $url );
	}
}
