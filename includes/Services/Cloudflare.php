<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Services;

use JsonException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\MultiPurge\PurgeUrlPolicy;
use MediaWiki\MediaWikiServices;

class Cloudflare implements StreamingPurgeService {
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
	 * @inheritDoc
	 * Array output stays for callers that want everything at once.
	 * The job reads yieldPurgeRequests() so a large batch never materializes.
	 */
	public function getPurgeRequest( $urls ): array {
		return iterator_to_array( $this->yieldPurgeRequests( $urls ), false );
	}

	/**
	 * @inheritDoc
	 */
	public function yieldPurgeRequests( $urls ): iterable {
		if ( !is_array( $urls ) ) {
			$urls = [ $urls ];
		}

		$policy = PurgeUrlPolicy::newFromConfigs(
			MediaWikiServices::getInstance()->getMainConfig(),
			$this->extensionConfig
		);

		// Resolve to absolute HTTP(S) URLs on allowed hosts before anything reaches HTTP.
		$urls = $policy->resolveList( $urls );

		// Cloudflare receives HTTPS targets; whether the schemes share a cache entry depends on the zone's cache key.
		$urls = array_map( static function ( string $url ): string {
			if ( strncasecmp( $url, 'http:', 5 ) === 0 ) {
				$url = sprintf( 'https:%s', substr( $url, 5 ) );
			}

			return $url;
		}, $urls );

		$chunkSize = max( 1, (int)$this->extensionConfig->get( 'MultiPurgeCloudFlareUrlsPerRequest' ) );
		if ( $this->extensionConfig->get( 'MultiPurgeCloudFlareCacheByDeviceType' ) ) {
			$chunkSize = max( 1, intdiv( $chunkSize, count( self::DEVICE_TYPES ) ) );
		}

		// Slicing by offset keeps only one chunk of URLs alive at a time instead of a list of every chunk request.
		$total = count( $urls );
		for ( $offset = 0; $offset < $total; $offset += $chunkSize ) {
			try {
				yield $this->makeRequest( array_slice( $urls, $offset, $chunkSize ) );
			} catch ( JsonException ) {
				continue;
			}
		}
	}

	/**
	 * Returns the target URLs one purge request map covers, so only failed requests are retried.
	 * An empty result means the caller cannot safely replace this request.
	 *
	 * @param array $request Request map as built by makeRequest()
	 * @return string[] Targets, deduplicated; cache-by-device-type variants fold onto their URL
	 */
	public static function getTargetsForRequest( array $request ): array {
		$postData = $request['postData'] ?? $request['body'] ?? null;
		if ( !is_string( $postData ) ) {
			return [];
		}

		try {
			$payload = json_decode( $postData, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return [];
		}

		$files = is_array( $payload ) ? ( $payload['files'] ?? null ) : null;
		if ( !is_array( $files ) ) {
			return [];
		}

		$targets = [];
		foreach ( $files as $file ) {
			if ( is_string( $file ) ) {
				$targets[] = $file;
			} elseif ( is_array( $file ) && is_string( $file['url'] ?? null ) ) {
				// Device-type variants repeat one URL per device key; a replacement regenerates them.
				$targets[] = $file['url'];
			}
		}

		return array_values( array_unique( $targets ) );
	}

	/**
	 * @param array $urls
	 * @return array
	 * @throws JsonException
	 */
	private function makeRequest( array $urls ): array {
		$zoneId = $this->extensionConfig->get( 'MultiPurgeCloudFlareZoneId' );
		$apiToken = $this->extensionConfig->get( 'MultiPurgeCloudFlareApiToken' );
		$baseUrl = $this->extensionConfig->get( 'MultiPurgeCloudFlareBaseUrl' );
		// This channel logs counts only; a target path or query can carry credentials.
		// MediaWiki's HTTP client still logs the request URLs itself, as documented.
		wfDebugLog(
			'MultiPurge',
			sprintf( 'Added %d files to a Cloudflare request', count( $urls ) )
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
		];
	}
}
