<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge;

use Exception;
use GenericParameterJob;
use InvalidArgumentException;
use Job;
use MediaWiki\Config\Config;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\PurgeServiceInterface;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use ReflectionClass;
use ReflectionException;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class MultiPurgeJob extends Job implements GenericParameterJob {
	private const MAX_RATE_LIMIT_RETRIES = 5;

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
	 * @return string[]
	 */
	public static function getServiceOrder(): array {
		$extensionConfig = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'MultiPurge' );

		$services = $extensionConfig->get( 'MultiPurgeEnabledServices' );
		wfDebugLog( 'MultiPurge', sprintf( 'Enabled Services: %s', json_encode( $services ) ) );

		if ( !$services ) {
			wfDebugLog( 'MultiPurge', 'Services empty' );
			return [];
		}

		$services = array_map( [ __CLASS__, 'normalizeServiceName' ], $services );

		$serviceOrder = $extensionConfig->get( 'MultiPurgeServiceOrder' ) ?? $services;

		wfDebugLog( 'MultiPurge', sprintf( 'Service Order: %s', json_encode( $serviceOrder ) ) );
		$serviceOrder = array_map( [ __CLASS__, 'normalizeServiceName' ], $serviceOrder );

		$enabled = array_intersect( $serviceOrder, $services );

		wfDebugLog( 'MultiPurge', sprintf( 'Enabled Services in Order: %s', json_encode( $enabled ) ) );

		return $enabled;
	}

	/**
	 * @param array $params
	 */
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
				foreach ( $this->getPurgeService( $service )->getPurgeRequest( $urls ) as $request ) {
					// Remembered so a rate-limited request can be retried on its own
					$request['purgeService'] = $service;
					$request['purgeUrls'] ??= $urls;
					$requests[] = $request;
				}
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

		$good = true;
		$rateLimited = [];
		foreach ( $statuses as $data ) {
			[ $code, , $headers, $body, $error ] = $data['response'];
			if ( $code >= 200 && $code <= 299 ) {
				continue;
			}

			if ( $code === 429 && isset( $data['purgeService'] ) ) {
				$service = $data['purgeService'];
				$rateLimited[$service]['urls'] = [ ...( $rateLimited[$service]['urls'] ?? [] ), ...$data['purgeUrls'] ];
				$rateLimited[$service]['retryAfter'] = max(
					$rateLimited[$service]['retryAfter'] ?? 0,
					(int)( $headers['retry-after'] ?? 0 )
				);
				continue;
			}

			$status = $body ?? $error;
			wfDebugLog(
				'MultiPurge',
				sprintf( 'Result for request %s is: %s', $data['url'] ?? '<invalid>', $status )
			);
			$good = false;
		}

		foreach ( $rateLimited as $service => $retry ) {
			$this->retryRateLimited( $service, $retry['urls'], $retry['retryAfter'] );
		}

		return $good;
	}

	/**
	 * Queue the URLs of rate-limited requests for another attempt, waiting as long as the
	 * service asked or backing off exponentially
	 *
	 * @param string $service
	 * @param string[] $urls
	 * @param int $retryAfter Seconds the service asked to wait, 0 if it did not say
	 */
	private function retryRateLimited( string $service, array $urls, int $retryAfter ): void {
		$retries = $this->params['rateLimitRetries'] ?? 0;
		if ( $retries >= self::MAX_RATE_LIMIT_RETRIES ) {
			LoggerFactory::getInstance( 'MultiPurge' )->error(
				'Dropping {count} URLs still rate-limited by {service} after {retries} retries',
				[ 'count' => count( $urls ), 'service' => $service, 'retries' => $retries ]
			);
			return;
		}

		// Cloudflare blocks an account for five minutes once its API rate limit is exceeded
		$delay = $retryAfter > 0 ? $retryAfter : min( 60 * 2 ** $retries, 300 );

		MediaWikiServices::getInstance()->getJobQueueGroupFactory()->makeJobQueueGroup()->lazyPush( new self( [
			'urls' => array_values( array_unique( $urls ) ),
			'service' => $service,
			'rateLimitRetries' => $retries + 1,
			'jobReleaseTimestamp' => (int)ConvertibleTimestamp::time() + $delay,
		] ) );

		wfDebugLog(
			'MultiPurge',
			sprintf( 'Rate-limited by %s, retrying %d URLs in %d seconds', $service, count( $urls ), $delay )
		);
	}

	/**
	 * Delays CloudFlare purge jobs in order to mitigate hitting the rate limit
	 *
	 * @return float|int|null
	 */
	public function getReleaseTimestamp() {
		if (
			isset( $this->params['service'] ) &&
			self::normalizeServiceName( $this->params['service'] ) === Cloudflare::class
		) {
			// Delay cloudflare jobs to not hit the 1000 urls/min purge limit
			$delay = (int)( ( count( $this->params['urls'] ) / 500 ) * 60 );

			return parent::getReleaseTimestamp() + $delay;
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
		$instance = $ref->newInstanceArgs( [
			$this->extensionConfig,
			MediaWikiServices::getInstance()->getHttpRequestFactory()
		] );
		$instance->setup();

		$this->serviceContainer[$class] = $instance;

		return $instance;
	}
}
