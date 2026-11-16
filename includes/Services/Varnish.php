<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Services;

use InvalidArgumentException;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;

class Varnish implements PurgeServiceInterface {
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
		wfDebugLog( 'MultiPurge', 'Setup Varnish' );
	}

	/**
	 * @inheritDoc
	 */
	public function getPurgeRequest( $urls ): array {
		$varnishServers = $this->extensionConfig->get( 'MultiPurgeVarnishServers' );
		$server = MediaWikiServices::getInstance()->getMainConfig()->get( 'Server' );
		$host = parse_url( $server, PHP_URL_HOST );

		if ( !$varnishServers ) {
			return [];
		}

		if ( !is_array( $urls ) ) {
			$urls = [ $urls ];
		}

		if ( !is_array( $varnishServers ) ) {
			$varnishServers = [ $varnishServers ];
		}

		$requests = [];

		foreach ( $urls as $url ) {
			$parsedUrl = parse_url( $url );
			if ( $parsedUrl === false ) {
				// Dropping the target silently would let the caller report a purge that never ran.
				throw new InvalidArgumentException( sprintf( 'Failed to parse purge target URL "%s".', $url ) );
			}

			foreach ( $varnishServers as $varnishServer ) {
				$components = $parsedUrl;
				// The destination port comes from the server config, not the wiki URL.
				unset( $components['port'] );

				if ( filter_var( $varnishServer, FILTER_VALIDATE_IP ) ) {
					$components['scheme'] = 'http';
					$components['host'] = $varnishServer;
				} else {
					$parsedVarnish = parse_url( $varnishServer );
					$components['scheme'] = $parsedVarnish['scheme'] ?? 'http';
					$components['host'] = $parsedVarnish['host'] ?? $varnishServer;
					if ( isset( $parsedVarnish['port'] ) ) {
						$components['port'] = $parsedVarnish['port'];
					}
				}

				$requestUrl = $this->buildUrl( $components );

				$requests[] = [
					'method' => 'PURGE',
					'url' => $requestUrl,
					'timeout' => 30,
					'connectTimeout' => 30,
					'headers' => [
						'Host' => $host,
						'Connection' => 'Keep-Alive',
						'Proxy-Connection' => 'Keep-Alive',
						'User-Agent' => 'MediaWiki/ext-multipurge-' . MW_VERSION . ' ' . __CLASS__,
					]
				];
				wfDebugLog(
					'MultiPurge',
					sprintf(
						'Adding "%s" to Varnish Purge Requests with Host Header "%s".',
						$requestUrl,
						$host
					)
				);
			}
		}

		wfDebugLog( 'MultiPurge', sprintf( 'Created %d Varnish Purge Requests', count( $requests ) ) );

		return $requests;
	}

	/**
	 * @param array $components
	 * @return string
	 */
	private function buildUrl( array $components ): string {
		$url = $components['scheme'] . '://' . $components['host'];

		if ( isset( $components['port'] ) &&
			( ( $components['scheme'] === 'http' && $components['port'] !== 80 ) ||
				( $components['scheme'] === 'https' && $components['port'] !== 443 ) )
		) {
			$url .= ':' . $components['port'];
		}

		if ( isset( $components['path'] ) ) {
			$url .= $components['path'];
		}

		if ( isset( $components['query'] ) ) {
			$url .= '?' . $components['query'];
		}

		if ( isset( $components['fragment'] ) ) {
			$url .= '#' . $components['fragment'];
		}

		return $url;
	}
}
