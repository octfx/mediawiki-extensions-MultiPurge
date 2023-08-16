<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge;

use Config;
use Exception;
use GenericParameterJob;
use InvalidArgumentException;
use Job;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\PurgeServiceInterface;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\MediaWikiServices;
use ReflectionClass;
use ReflectionException;

class MultiPurgeJob extends Job implements GenericParameterJob {
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
	 * Returns all enabled services in order as an array
	 *
	 * @return PurgeServiceInterface[]
	 */
	public static function getServiceOrder(): array {
		$extensionConfig = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'MultiPurge' );

		$services = $extensionConfig->get( 'MultiPurgeEnabledServices' );
		wfDebugLog( 'MultiPurge', sprintf( 'Enabled Services: %s', json_encode( $services ) ) );

		if ( empty( $services ) ) {
			wfDebugLog( 'MultiPurge', 'Services empty' );
			return [];
		}

		$services = array_map( [ __CLASS__, 'normalizeServiceName' ], $services );

		$serviceOrder = $extensionConfig->get( 'MultiPurgeServiceOrder' );

		wfDebugLog( 'MultiPurge', sprintf( 'Service Order: %s', json_encode( $serviceOrder ) ) );
		if ( !empty( $serviceOrder ) ) {
			$serviceOrder = array_map( [ __CLASS__, 'normalizeServiceName' ], $serviceOrder );
		}

		$enabled = array_intersect( $serviceOrder, $services );

		wfDebugLog( 'MultiPurge', sprintf( 'Enabled Services in Order: %s', json_encode( $enabled ) ) );

		return $enabled;
	}

	public function __construct( array $params ) {
		parent::__construct( 'MultiPurgePages', $params );
		$this->removeDuplicates = true;

		$this->extensionConfig = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'MultiPurge' );
	}

	/**
	 * Run the purge job
	 * If no service is explicitly set, the purge is run against all enabled services
	 *
	 * @return bool
	 */
	public function run(): bool {
		if ( !isset( $this->params['service'] ) ) {
			$enabled = self::getServiceOrder();
		} else {
			$enabled = [ $this->params['service'] ];
		}

		wfDebugLog( 'MultiPurge', sprintf( 'Enabled Services in Order: %s', json_encode( $enabled ) ) );

		$http = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->createMultiClient( [ 'maxConnsPerHost' => 8, 'usePipelining' => true ] );

		$requests = [];

		foreach ( $enabled as $service ) {
			$urls = $this->params['urls'];

			$run = MediaWikiServices::getInstance()->getHookContainer()->run(
				'MultiPurgeOnPurgeService',
				[
					$service,
					&$urls,
				]
			);

			if ( !$run ) {
				continue;
			}

			try {
				$urls = $this->getPurgeService( $service )->getPurgeRequest( $urls );

				$requests = [ ...$requests, ...$urls ];
			} catch ( ReflectionException $e ) {
				wfDebugLog( 'MultiPurge', $e->getMessage() );
				wfLogWarning( sprintf( '[MultiPurge] Could not instantiate service "%s"', $service ) );
			}
		}

		wfDebugLog( 'MultiPurge', sprintf( 'Calling %d purge urls', count( $requests ) ) );

		try {
			$statuses = $http->runMulti( $requests );
		} catch ( Exception $e ) {
			wfLogWarning( sprintf( '[MultiPurge]: %s', $e->getMessage() ) );
			return false;
		}

		return array_reduce( $statuses, static function ( bool $carry, array $data ) {
			[ $code, $reason, $headers, $body, $error ] = $data['response'];
			$good = false;
			if ( $code >= 200 && $code <= 299 ) {
				$good = true;
			} else {
				$status = $body ?? $error;
				wfDebugLog( 'MultiPurge', sprintf( 'Result for request %s is: %s', $data['url'], $status ) );
			}

			return $carry && $good;
		}, true );
	}

	/**
	 * Delays CloudFlare purge jobs in order to mitigate hitting the rate limit
	 *
	 * @return float|int|null
	 */
	public function getReleaseTimestamp() {
		if ( isset( $this->params['service'] ) && self::normalizeServiceName( $this->params['service'] ) === Cloudflare::class ) {
			// Delay cloudflare jobs to not hit the 1000 urls/min purge limit
			$delay = (int)( count( $this->params['urls'] ) / 500 ) * 60;

			return time() + $delay;
		}

		return parent::getReleaseTimestamp();
	}

	/**
	 * Get a class string from name
	 *
	 * @param string $name
	 * @return string
	 */
	private static function normalizeServiceName( string $name ): string {
		$original = $name;
		return match ( strtolower( $name ) ) {
			Cloudflare::class, 'cloudflare' => Cloudflare::class,
			Varnish::class, 'varnish' => Varnish::class,
			default => $original,
		};
	}

	/**
	 * Returns a service by class
	 * Instantiates and sets up the service
	 *
	 * @param string $class
	 * @return PurgeServiceInterface
	 * @throws ReflectionException
	 */
	private function getPurgeService( string $class ): PurgeServiceInterface {
		$class = self::normalizeServiceName( $class );

		if ( !in_array( $class, $this->availableServices ) ) {
			throw new InvalidArgumentException( sprintf( 'Service "%s" not recognized.', $class ) );
		}

		if ( isset( $this->serviceContainer[$class] ) ) {
			return $this->serviceContainer[$class];
		}

		$ref = new ReflectionClass( $class );
		/** @var PurgeServiceInterface $instance */
		$instance = $ref->newInstanceArgs( [ $this->extensionConfig, MediaWikiServices::getInstance()->getHttpRequestFactory() ] );
		$instance->setup();

		$this->serviceContainer[$class] = $instance;

		return $instance;
	}
}
