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

		$job = new MultiPurgeJob( [
			'urls' => $urls,
		] );

		try {
			$job->run();
		} catch ( Exception $e ) {
			wfDebugLog( 'MultiPurge', $e->getMessage() );
		}

		// MediaWikiServices::getInstance()->getJobRunner()->executeJob( $job );
	}
}
