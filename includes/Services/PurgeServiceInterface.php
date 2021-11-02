<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Service;

use Config;
use MWHttpRequest;

interface PurgeServiceInterface {
	/**
	 * The config object containing the extension specific settings
	 *
	 * @param Config $extensionConfig
	 */
	public function __construct( Config $extensionConfig );

	/**
	 * This is called once by the factory after instantiating the purge service.
	 * Usually this is used to set up anything required for making the service specific purge request
	 */
	public function setup(): void;

	/**
	 * Returns one or multiple MWHttpRequests used to execute the actual purge request
	 *
	 * @param string|array $urls
	 * @return MWHttpRequest|MWHttpRequest[]
	 */
	public function getPurgeRequest( $urls );
}
