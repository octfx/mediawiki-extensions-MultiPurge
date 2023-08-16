<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Services;

use Config;
use JsonException;
use MWHttpRequest;

class Cloudflare implements PurgeServiceInterface {
	private $extensionConfig;

	public function __construct( Config $extensionConfig ) {
		$this->extensionConfig = $extensionConfig;
	}

	public function setup(): void {
		wfDebugLog( 'MultiPurge', 'Setup Cloudflare' );
	}

	/**
	 * Returns as array of purge requests
	 * Chunks the request of count($urls) > 30
	 *
	 * @param $urls
	 * @return MWHttpRequest[]
	 */
	public function getPurgeRequest( $urls ): array {
		if ( !is_array( $urls ) ) {
			$urls = [ $urls ];
		}

		// Protocolize urls
		$urls = array_map( static function ( string $url ) {
			if ( substr( $url, 0, 2 ) === '//' ) {
				$url = sprintf( 'https:%s', $url );
			}

			if ( substr( $url, 0, 5 ) === 'http:' ) {
				$url = sprintf( 'https:%s', substr( $url, 5 ) );
			}

			return $url;
		}, $urls );

		$requests = [];

		foreach ( array_chunk( $urls, 30 ) as $chunk ) {
			try {
				$requests[] = $this->makeRequest( $chunk );
			} catch ( JsonException $e ) {
				// Shouldn't really happen
				continue;
			}
		}

		return $requests;
	}

	/**
	 * Create the actual request for the given urls
	 *
	 * @param array $urls
	 * @return MWHttpRequest[]
	 * @throws JsonException
	 */
	private function makeRequest( array $urls ): array {
		$zoneId = $this->extensionConfig->get( 'MultiPurgeCloudFlareZoneId' );
		$apiToken = $this->extensionConfig->get( 'MultiPurgeCloudFlareApiToken' );
		wfDebugLog(
			'MultiPurge',
			sprintf( 'Added %d files to Cloudflare request: %s', count( $urls ), json_encode( $urls, JSON_THROW_ON_ERROR ) )
		);

		return [
			'method' => 'POST',
			'url' => "https://api.cloudflare.com/client/v4/zones/$zoneId/purge_cache",
			'headers' => [
				'Connection' => 'Keep-Alive',
				'Proxy-Connection' => 'Keep-Alive',
				'User-Agent' => 'MediaWiki/ext-multipurge-' . MW_VERSION . ' ' . __CLASS__,
				'Authorization' => sprintf( 'Bearer %s', $apiToken ),
				'Content-Type' => 'application/json',
			],
			'postData' => json_encode( [ 'files' => $urls ], JSON_THROW_ON_ERROR ),
			// Body in case of curl
			'body' => json_encode( [ 'files' => $urls ], JSON_THROW_ON_ERROR ),
		];
	}
}
