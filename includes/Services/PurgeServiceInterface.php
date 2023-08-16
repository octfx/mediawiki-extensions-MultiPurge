<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Services;

use Config;

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
	 * Returns one or multiple config array used to execute the actual purge request using MultiClient
	 *
	 * @param string|array $urls
	 * @return array
	 */
	public function getPurgeRequest( $urls ): array;
}
