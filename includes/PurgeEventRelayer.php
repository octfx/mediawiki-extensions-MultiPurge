<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge;

use EventRelayer;
use Exception;
use MediaWiki\MediaWikiServices;

class PurgeEventRelayer extends EventRelayer {

	protected function doNotify( $channel, array $events ) {
		if ( $channel !== 'cdn-url-purges' ) {
			return;
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
			return;
		}

		wfDebugLog( 'MultiPurge', 'Running Job' );

		foreach ( MultiPurgeJob::getServiceOrder() as $service ) {
			$job = new MultiPurgeJob( [
				'urls' => array_unique( $urls ),
				'service' => $service,
			] );

			if ( MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'MultiPurge' )->get( 'MultiPurgeRunInQueue' ) === true ) {
				MediaWikiServices::getInstance()->getJobQueueGroupFactory()->makeJobQueueGroup()->lazyPush( $job );
			} else {
				try {
					$status = $job->run();
				} catch ( Exception $e ) {
					$status = false;
				}
				wfDebugLog(
					'MultiPurge',
					sprintf(
						'Job Status: %s',
						( $status === true ? 'success' : 'error' )
					)
				);
			}
		}
	}
}
