<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Service;

use Config;
use MediaWiki\MediaWikiServices;
use MWHttpRequest;

class Cloudflare implements PurgeServiceInterface {
	private $extensionConfig;

	/**
	 * @var MWHttpRequest
	 */
	private $request;

	public function __construct( Config $extensionConfig ) {
		$this->extensionConfig = $extensionConfig;
	}

	public function setup(): void {
		$zoneId = $this->extensionConfig->get( 'MultiPurgeCloudFlareZoneId' );
		$apiToken = $this->extensionConfig->get( 'MultiPurgeCloudFlareApiToken' );
		$accountId = $this->extensionConfig->get( 'MultiPurgeCloudFlareAccountId' );

		$this->request = MediaWikiServices::getInstance()->getHttpRequestFactory()->create(
			"https://api.cloudflare.com/client/v4/zones/$zoneId/purge_cache",
			[
				'method' => 'POST',
				'userAgent' => 'MediaWiki/ext-multipurge'
			]
		);

		$this->request->setHeader( 'X-Auth-Key', $accountId );
		$this->request->setHeader( 'Authorization', sprintf( 'Bearer %s', $apiToken ) );
		$this->request->setHeader( 'Content-Type', 'application/json' );
	}

	public function getPurgeRequest( $urls ): MWHttpRequest {
		if ( !is_array( $urls ) ) {
			$urls = [ $urls ];
		}
		$this->request->setData( [ 'files' => $urls ] );

		return $this->request;
	}
}
