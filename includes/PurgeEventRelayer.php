<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Throwable;
use Wikimedia\EventRelayer\EventRelayer;

class PurgeEventRelayer extends EventRelayer {
	/**
	 * @param string $channel
	 * @param array $events
	 * @return bool
	 */
	protected function doNotify( $channel, array $events ): bool {
		if ( $channel !== 'cdn-url-purges' ) {
			return true;
		}

		$urls = array_filter( array_map( static function ( array $purgeUrl ) {
			return $purgeUrl['url'] ?? null;
		}, $events ) );

		$run = MediaWikiServices::getInstance()->getHookContainer()->run(
			'MultiPurgeOnPurgeUrls',
			[
				&$urls,
			]
		);

		if ( !$run ) {
			return true;
		}

		if ( !$urls ) {
			// An empty post-hook URL list leaves no purge to lose, so the service selection is not evaluated.
			// A nonempty list against an unusable selection still errors below.
			return true;
		}

		wfDebugLog( 'MultiPurge', 'Running Job' );

		$serviceOrder = MultiPurgeJob::getServiceOrder();
		if ( !$serviceOrder ) {
			// Enabled services that select no usable service can never purge and are reported as lost purges.
			// An intentionally disabled extension stays silent.
			$enabled = MediaWikiServices::getInstance()->getMainConfig()->get( 'MultiPurgeEnabledServices' );
			if ( $enabled ) {
				LoggerFactory::getInstance( 'MultiPurge' )->error(
					'No purge service could run; nothing was purged or queued for: {services}',
					[ 'services' => implode( ', ', $enabled ) ]
				);
				return false;
			}
			return true;
		}

		$urls = MultiPurgeJob::filterDispatchUrls( $urls );
		if ( !$urls ) {
			return true;
		}

		// In queue mode the relayer reports the synchronous handoff, not the purge outcome.
		// Inline it runs the purges itself, so a notification that lost every purge is reported as a failure below.
		// A late insert failure, such as SQLite inside an open transaction, surfaces at commit instead.
		$relayed = true;
		$validServiceRan = false;
		$invalidServices = [];

		foreach ( $serviceOrder as $service ) {
			$job = new MultiPurgeJob( [ 'urls' => $urls, 'service' => $service ] );

			if ( MediaWikiServices::getInstance()->getMainConfig()->get( 'MultiPurgeRunInQueue' ) === true ) {
				// The relayer hands off immediately; core orders this context after the invalidation.
				try {
					MediaWikiServices::getInstance()->getJobQueueGroupFactory()->makeJobQueueGroup()->push( $job );
				} catch ( Throwable $e ) {
					LoggerFactory::getInstance( 'MultiPurge' )->error(
						'Queueing the {service} purge was rejected: {error}',
						[ 'service' => $service, 'error' => $e->getMessage() ]
					);
					$relayed = false;
				}
			} else {
				try {
					$status = $job->run();
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

				// An inline run gets no queue retry of its own, so queue the failed service job right away.
				// Retry timing and attempt limits stay with the configured queue backend.
				try {
					MediaWikiServices::getInstance()->getJobQueueGroupFactory()->makeJobQueueGroup()->push( $job );
					wfDebugLog( 'MultiPurge', 'Job Status: error, queued for retry' );
				} catch ( Throwable $e ) {
					LoggerFactory::getInstance( 'MultiPurge' )->error(
						'Purge by {service} failed and queueing a retry was rejected: {error}',
						[ 'service' => $service, 'error' => $e->getMessage() ]
					);
					$relayed = false;
				}
			}
		}

		// An inline notification where every dispatched service was unusable purged and queued nothing, permanently.
		// It must not claim success, and MediaWiki discards the relayer result.
		// The lost purge is therefore logged at error level as the operative signal.
		// A valid service that failed keeps its retry handoff and is never reported as an all-unusable run.
		if ( $relayed && $invalidServices !== [] && !$validServiceRan ) {
			LoggerFactory::getInstance( 'MultiPurge' )->error(
					'No purge service could run; nothing was purged or queued for: {services}',
					[ 'services' => implode( ', ', $invalidServices ) ]
				);
				$relayed = false;
		}

		return $relayed;
	}
}
