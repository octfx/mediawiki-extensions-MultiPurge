<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Services;

use JsonException;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;

class Cloudflare implements PurgeServiceInterface {
	/**
	 * Cloudflare classifies every request as one of these device types, and cache by device type
	 * keeps a separate entry for each.
	 *
	 * @see https://developers.cloudflare.com/cache/how-to/cache-keys/
	 */
	private const DEVICE_TYPES = [ 'desktop', 'mobile', 'tablet' ];

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

		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();

		// Cloudflare only purges absolute urls, but core passes file urls relative to $wgServer
		$urls = array_map( static function ( string $url ) use ( $urlUtils ) {
			$url = $urlUtils->expand( $url, PROTO_HTTPS ) ?? $url;

			if ( substr( $url, 0, 5 ) === 'http:' ) {
				$url = sprintf( 'https:%s', substr( $url, 5 ) );
			}

			return $url;
		}, $urls );

		$requests = [];

		$chunkSize = max( 1, (int)$this->extensionConfig->get( 'MultiPurgeCloudFlareUrlsPerRequest' ) );
		// Cache by device type sends every URL once per device type
		if ( $this->extensionConfig->get( 'MultiPurgeCloudFlareCacheByDeviceType' ) ) {
			$chunkSize = max( 1, intdiv( $chunkSize, count( self::DEVICE_TYPES ) ) );
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
		$baseUrl = $this->extensionConfig->get( 'MultiPurgeCloudFlareBaseUrl' );
		wfDebugLog(
			'MultiPurge',
			sprintf(
				'Added %d files to Cloudflare request: %s',
				count( $urls ),
				json_encode( $urls, JSON_THROW_ON_ERROR )
			)
		);

		if ( $this->extensionConfig->get( 'MultiPurgeCloudFlareCacheByDeviceType' ) ) {
			$files = [];
			foreach ( $urls as $url ) {
				foreach ( self::DEVICE_TYPES as $deviceType ) {
					$files[] = [
						'url' => $url,
						'headers' => [ 'CF-Device-Type' => $deviceType ]
					];
				}
			}
		} else {
			$files = $urls;
		}

		$postData = json_encode( [ 'files' => $files ], JSON_THROW_ON_ERROR );

		return [
			'method' => 'POST',
			'url' => rtrim( $baseUrl, '/' ) . "/client/v4/zones/$zoneId/purge_cache",
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
