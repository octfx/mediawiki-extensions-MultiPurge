<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests\Hooks;

use Wikimedia\EventRelayer\EventRelayer;

/**
 * Relayer a test installs as a foreign owner of cdn-url-purges, recording what core relays to it.
 */
class RecordingEventRelayer extends EventRelayer {

	public static ?RecordingEventRelayer $lastInstance = null;

	/** @var string[] */
	public array $notifiedUrls = [];

	/**
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( $params );
		self::$lastInstance = $this;
	}

	/**
	 * @param string $channel
	 * @param array $events
	 * @return bool
	 */
	protected function doNotify( $channel, array $events ): bool {
		foreach ( $events as $event ) {
			$this->notifiedUrls[] = (string)( $event['url'] ?? '' );
		}

		return true;
	}

}
