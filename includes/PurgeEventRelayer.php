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

		wfDebugLog( 'MultiPurge', 'Running Job' );

		// The relayer reports the synchronous handoff to the queue, not the purge outcome.
		// A late insert failure, such as SQLite inside an open transaction, surfaces at commit instead.
		$relayed = true;

		$urls = MultiPurgeJob::filterDispatchUrls( $urls );
		if ( !$urls ) {
			return true;
		}

		foreach ( MultiPurgeJob::getServiceOrder() as $service ) {
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

				if ( $status ) {
					wfDebugLog( 'MultiPurge', 'Job Status: success' );
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

		return $relayed;
	}
}
