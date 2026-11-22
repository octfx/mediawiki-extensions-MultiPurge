<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Hooks;

use MediaWiki\Extension\MultiPurge\PurgeEventRelayer;
use MediaWiki\Logger\LoggerFactory;
use Wikimedia\EventRelayer\EventRelayerNull;

class MainHooks {

	public static function setup(): void {
		global $wgEventRelayerConfig;

		// Mirror EventRelayerGroup::getRelayer: an absent or null cdn-url-purges entry falls back to the default entry.
		// The stock no-op EventRelayerNull default displaces nothing; an opted-out channel entry stays a conflict.
		$explicit = $wgEventRelayerConfig['cdn-url-purges'] ?? null;
		$entry = $explicit ?? ( $wgEventRelayerConfig['default'] ?? null );
		$via = $explicit !== null ? 'cdn-url-purges' : 'default';
		$class = is_array( $entry ) && is_string( $entry['class'] ?? null ) ? $entry['class'] : null;
		$isStockNull = $explicit === null && $class === EventRelayerNull::class;
		if ( $entry !== null && $class !== PurgeEventRelayer::class && !$isStockNull ) {
			LoggerFactory::getInstance( 'MultiPurge' )->warning(
				'Replacing the {class} relayer that currently handles cdn-url-purges purges via the {via} config; '
				. 'that relayer will no longer receive MediaWiki cache purge events.',
				[ 'class' => $class ?? gettype( $entry ), 'via' => $via ]
			);
		}

		$wgEventRelayerConfig['cdn-url-purges'] = [
			'class' => PurgeEventRelayer::class,
		];
	}
}
