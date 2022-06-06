<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Services;

use Config;
use MediaWiki\Http\HttpRequestFactory;
use MWHttpRequest;

class Cloudflare implements PurgeServiceInterface {
	private $extensionConfig;
	private $requestFactory;

	/**
	 * @var MWHttpRequest
	 */
	private $request;

	public function __construct( Config $extensionConfig, HttpRequestFactory $requestFactory ) {
		$this->extensionConfig = $extensionConfig;
		$this->requestFactory = $requestFactory;
	}

	public function setup(): void {
		wfDebugLog( 'MultiPurge', 'Setup Cloudflare' );
		$zoneId = $this->extensionConfig->get( 'MultiPurgeCloudFlareZoneId' );
		$apiToken = $this->extensionConfig->get( 'MultiPurgeCloudFlareApiToken' );

		$this->request = $this->requestFactory->create(
			"https://api.cloudflare.com/client/v4/zones/$zoneId/purge_cache",
			[
				'method' => 'POST',
				'userAgent' => 'MediaWiki/ext-multipurge'
			]
		);

		$this->request->setHeader( 'Authorization', sprintf( 'Bearer %s', $apiToken ) );
		$this->request->setHeader( 'Content-Type', 'application/json' );
	}

	public function getPurgeRequest( $urls ): array {
		if ( !is_array( $urls ) ) {
			$urls = [ $urls ];
		}
		wfDebugLog( 'MultiPurge', sprintf( 'Added %d files to Cloudflare request: %s', count( $urls ), json_encode( $urls ) ) );
		$this->request->setData( [ 'files' => $urls ] );

		return [ $this->request ];
	}
}
