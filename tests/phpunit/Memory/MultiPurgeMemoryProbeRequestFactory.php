<?php

declare( strict_types=1 );

use MediaWiki\Http\HttpRequestFactory;
use Wikimedia\Http\MultiHttpClient;

/**
 * Replaces the real factory so the memory probe never opens a network connection.
 * The probe loads this file only after MediaWiki booted.
 * It extends a core class that exists once the autoloader from Setup.php is active.
 */
class MultiPurgeMemoryProbeRequestFactory extends HttpRequestFactory {

	private MultiPurgeMemoryProbeClient $client;

	public function __construct( MultiPurgeMemoryProbeClient $client ) {
		$this->client = $client;
	}

	/**
	 * @param array $options
	 * @return MultiHttpClient
	 */
	public function createMultiClient( $options = [] ) {
		return $this->client;
	}
}
