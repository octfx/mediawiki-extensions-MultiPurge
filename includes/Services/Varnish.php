<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Service;

use Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;

class Varnish implements PurgeServiceInterface {
	private $extensionConfig;
	/**
	 * @var HttpRequestFactory
	 */
	private $requestFactory;

	public function __construct( Config $extensionConfig ) {
		$this->extensionConfig = $extensionConfig;
	}

	public function setup(): void {
		$this->requestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
	}

	public function getPurgeRequest( $urls ): array {
		$varnishServers = $this->extensionConfig->get( 'MultiPurgeVarnishServers' );

		if ( !is_array( $urls ) ) {
			$urls = [ $urls ];
		}

		$requests = [];

		foreach ( $urls as $url ) {
			$parsedUrl = parse_url( $url );
			foreach ( $varnishServers as $varnishServer ) {
				if ( filter_var( $varnishServer, FILTER_VALIDATE_IP ) ) {
					$parsedUrl['scheme'] = 'http';
					$parsedUrl['host'] = $varnishServer;
				} else {
					$parsedVarnish = parse_url( $varnishServer );
					$parsedUrl['scheme'] = $parsedVarnish['scheme'];
					$parsedUrl['host'] = $parsedVarnish['host'];
				}

				$requests[] = $this->requestFactory->create(
					$this->buildUrl( $parsedUrl ),
					[
						'method' => 'PURGE',
						'userAgent' => 'MediaWiki/ext-multipurge'
					]
				);
			}
		}

		return $requests;
	}

	private function buildUrl( array $components ): string {
		$url = $components['scheme'] . '://';

		if ( !empty( $components['username'] ) && !empty( $components['password'] ) ) {
			$url .= $components['username'] . ':' . $components['password'] . '@';
		}

		$url .= $components['host'];

		if ( !empty( $components['port'] ) &&
			( ( $components['scheme'] === 'http' && $components['port'] !== 80 ) ||
				( $components['scheme'] === 'https' && $components['port'] !== 443 ) )
		) {
			$url .= ':' . $components['port'];
		}

		if ( !empty( $components['path'] ) ) {
			$url .= $components['path'];
		}

		if ( !empty( $components['query'] ) ) {
			$url .= '?' . http_build_query( $components['query'] );
		}

		if ( !empty( $components['fragment'] ) ) {
			$url .= '#' . $components['fragment'];
		}

		return $url;
	}
}
