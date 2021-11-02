<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge;

use EventRelayer;
use MediaWiki\MediaWikiServices;

class PurgeEventRelayer extends EventRelayer {

	protected function doNotify( $channel, array $events ) {
		if ( $channel !== 'cdn-url-purges' ) {
			return;
		}

		$job = new MultiPurgeJob( [
			'urls' => array_filter( array_map( static function ( array $purgeUrl ) {
				return $purgeUrl['url'] ?? null;
			}, $events ) ),
		] );

		MediaWikiServices::getInstance()->getJobRunner()->executeJob( $job );
	}
}
