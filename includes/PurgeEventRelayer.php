<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge;

use Exception;
use MediaWiki\MediaWikiServices;
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

		foreach ( MultiPurgeJob::getServiceOrder() as $service ) {
			$job = new MultiPurgeJob( [
				'urls' => array_unique( $urls ),
				'service' => $service,
			] );

			if ( MediaWikiServices::getInstance()->getMainConfig()->get( 'MultiPurgeRunInQueue' ) === true ) {
				MediaWikiServices::getInstance()->getJobQueueGroupFactory()->makeJobQueueGroup()->lazyPush( $job );
			} else {
				try {
					$status = $job->run();
				} catch ( Exception ) {
					$status = false;
				}
				wfDebugLog(
					'MultiPurge',
					sprintf(
						'Job Status: %s',
						( $status ? 'success' : 'error' )
					)
				);
			}
		}

		return true;
	}
}
