<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Services;

use MediaWiki\Config\Config;
use MediaWiki\Extension\MultiPurge\PurgeUrlPolicy;
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

		if ( !$varnishServers ) {
			return [];
		}

		if ( !is_array( $urls ) ) {
			$urls = [ $urls ];
		}

		if ( !is_array( $varnishServers ) ) {
			$varnishServers = [ $varnishServers ];
		}

		$policy = PurgeUrlPolicy::newFromConfigs(
			MediaWikiServices::getInstance()->getMainConfig(),
			$this->extensionConfig
		);

		$requests = [];

		foreach ( $urls as $url ) {
			$target = $policy->resolve( $url, $reason );
			if ( $target === null ) {
				// A rejected target is permanent, so it is logged and skipped while valid targets still run.
				$policy->logRejection( $url, $reason ?? 'invalid URL' );
				continue;
			}

			$parts = parse_url( $target );
			'@phan-var array{scheme:string,host:string,port?:int,path?:string,query?:string,fragment?:string} $parts';
			// Varnish keys on the target Host header; include non-default ports.
			$hostHeader = $parts['host'];
			$defaultPort = strtolower( $parts['scheme'] ) === 'https' ? 443 : 80;
			if ( isset( $parts['port'] ) && $parts['port'] !== $defaultPort ) {
				$hostHeader .= ':' . $parts['port'];
			}

			foreach ( $varnishServers as $varnishServer ) {
				$components = $parts;
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
						'Host' => $hostHeader,
						'Connection' => 'Keep-Alive',
						'Proxy-Connection' => 'Keep-Alive',
						'User-Agent' => 'MediaWiki/ext-multipurge-' . MW_VERSION . ' ' . __CLASS__,
					]
				];
				// This channel logs the validated Host header only; the request URL carries the target path.
				// MediaWiki's HTTP client logs the URL itself, as documented.
				wfDebugLog(
					'MultiPurge',
					sprintf(
						'Adding a Varnish purge request with Host Header "%s".',
						$hostHeader
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
