<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Services;

use JsonException;
use MediaWiki\Config\Config;

class Cloudflare implements PurgeServiceInterface {
	private $extensionConfig;

	/**
	 * @param Config $extensionConfig
	 */
	public function __construct( Config $extensionConfig ) {
		$this->extensionConfig = $extensionConfig;
	}

	/**
	 * @return void
	 */
	public function setup(): void {
		wfDebugLog( 'MultiPurge', 'Setup Cloudflare' );
	}

	/**
	 * Returns as array of purge requests
	 * Chunks the request by $wgMultiPurgeCloudFlareUrlsPerRequest
	 *
	 * @param string|array $urls
	 * @return array
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

		$chunkSize = max( 1, (int)$this->extensionConfig->get( 'MultiPurgeCloudFlareUrlsPerRequest' ) );
		// Cache by device type sends every URL twice, once per device type
		if ( $this->extensionConfig->get( 'MultiPurgeCloudFlareCacheByDeviceType' ) ) {
			$chunkSize = max( 1, intdiv( $chunkSize, 2 ) );
		}

		foreach ( array_chunk( $urls, $chunkSize ) as $chunk ) {
			try {
				$requests[] = $this->makeRequest( $chunk );
			} catch ( JsonException ) {
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
	 * @return array
	 * @throws JsonException
	 */
	private function makeRequest( array $urls ): array {
		$zoneId = $this->extensionConfig->get( 'MultiPurgeCloudFlareZoneId' );
		$apiToken = $this->extensionConfig->get( 'MultiPurgeCloudFlareApiToken' );
		wfDebugLog(
			'MultiPurge',
			sprintf(
				'Added %d files to Cloudflare request: %s',
				count( $urls ),
				json_encode( $urls, JSON_THROW_ON_ERROR )
			)
		);

		$postData = [ 'files' => $urls ];

		if ( $this->extensionConfig->get( 'MultiPurgeCloudFlareCacheByDeviceType' ) ) {
			foreach ( $urls as $url ) {
				$postData['files'][] = [
					'url' => $url,
					'headers' => [ 'CF-Device-Type' => 'mobile' ]
				];
			}
		}

		$postData = json_encode( $postData, JSON_THROW_ON_ERROR );

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
			'postData' => $postData,
			// Body in case of curl
			'body' => $postData,
			// Lets MultiPurgeJob retry just this request if it is rate-limited
			'purgeUrls' => $urls,
		];
	}
}
