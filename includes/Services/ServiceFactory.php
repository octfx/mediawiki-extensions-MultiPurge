<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Service;

use Config;
use InvalidArgumentException;
use MediaWiki\MediaWikiServices;

class ServiceFactory {
	/**
	 * @var ServiceFactory the service factory instance
	 */
	private static $instance;

	/**
	 * @var Config Extension config passed to each service
	 */
	private $extensionConfig;

	/**
	 * @var array Map containing instantiated purge services
	 */
	private $serviceContainer = [];

	/**
	 * List of available purge services
	 *
	 * @var string[]
	 */
	private $availableServices = [
		Cloudflare::class,
		Varnish::class,
	];

	/**
	 * Private constructor to force ::getInstance access
	 *
	 * @param Config $extensionConfig
	 */
	private function __construct( Config $extensionConfig ) {
		$this->extensionConfig = $extensionConfig;
	}

	/**
	 * Ensures that only one factory is active
	 *
	 * @return ServiceFactory
	 */
	public static function getInstance(): ServiceFactory {
		if ( !isset( self::$instance ) ) {
			self::$instance = new ServiceFactory( MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'MultiPurge' )
			);
		}

		return self::$instance;
	}

	/**
	 * Returns a service by class
	 * Instantiates and sets up the service
	 *
	 * @param string $class
	 * @return PurgeServiceInterface
	 */
	public function getPurgeService( string $class ): PurgeServiceInterface {
		$class = self::normalizeServiceName( $class );

		if ( !in_array( $class, $this->availableServices ) ) {
			throw new InvalidArgumentException( sprintf( 'Service "%s" not recognized.', $class ) );
		}

		if ( isset( $this->serviceContainer[$class] ) ) {
			return $this->serviceContainer[$class];
		}

		/** @var PurgeServiceInterface $instance */
		$instance = new $class( $this->extensionConfig );
		$instance->setup();

		$this->serviceContainer[$class] = $instance;

		return $instance;
	}

	public static function normalizeServiceName( string $name ): string {
		switch ( strtolower( $name ) ) {
			case 'cloudflare':
				return Cloudflare::class;

			case 'varnish':
				return Varnish::class;

			default:
				return $name;
		}
	}
}
