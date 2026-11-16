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
	 * Whether the last run() submitted at least one purge request to the HTTP client.
	 * Set false by a hook veto or a post-hook policy check that leaves no targets, which still return success.
	 *
	 * @var bool
	 */
	private bool $sentPurgeRequests = false;

	/**
	 * Original targets the service hook or the post-hook policy removed from the last run.
	 *
	 * @var string[]
	 */
	private array $droppedTargets = [];

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
	 * Filter before dispatch because failed enqueues can echo serialized job parameters.
	 *
	 * @param string[] $urls
	 * @return string[]
	 */
	public static function filterDispatchUrls( array $urls ): array {
		$services = MediaWikiServices::getInstance();
		return PurgeUrlPolicy::newFromConfigs(
			$services->getMainConfig(),
			$services->getConfigFactory()->makeConfig( 'MultiPurge' )
		)->resolveList( $urls );
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

		// Enforced here so every caller, including jobs queued earlier, filters with the policy in force at run time.
		$policy = PurgeUrlPolicy::newFromConfigs(
			MediaWikiServices::getInstance()->getMainConfig(),
			$this->extensionConfig
		);

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

			// Hook modifications are validated too.
			// A rejected target is a permanent policy violation: it is logged and skipped, never retried or fatal here.
			$urls = $policy->resolveList( $urls );

			// A hook that trims or replaces targets sends partial work, which callers must be able to distinguish.
			$this->droppedTargets = array_merge( $this->droppedTargets, array_diff( $this->params['urls'], $urls ) );

			if ( !$urls ) {
				continue;
			}

			try {
				foreach ( $this->getPurgeService( $service )->getPurgeRequest( $urls ) as $request ) {
					$requests[] = $request;
				}
			} catch ( ReflectionException $e ) {
				wfDebugLog( 'MultiPurge', $e->getMessage() );
				wfLogWarning( sprintf( '[MultiPurge] Could not instantiate service "%s"', $service ) );
			}
		}

		wfDebugLog( 'MultiPurge', sprintf( 'Calling %d purge urls', count( $requests ) ) );

		// Distinguishes dispatched HTTP work from runs that returned success while sending nothing.
		$this->sentPurgeRequests = $requests !== [];

		try {
			// An empty batch means every target was rejected or every service vetoed; nothing to send or fail on.
			$statuses = $requests ? $http->runMulti( $requests ) : [];
		} catch ( Exception $e ) {
			wfLogWarning( sprintf( '[MultiPurge]: %s', $e->getMessage() ) );
			return false;
		}

		$good = true;
		foreach ( $statuses as $index => $data ) {
			[ $code, , , $body, $error ] = $data['response'];
			if ( $code >= 200 && $code <= 299 ) {
				continue;
			}

			// Any unsuccessful response, including 429, fails the job so the configured queue retries it.
			// Queues without delayed-job support reject jobs that carry a custom release timestamp.
			$status = $body ?? $error;
			// The request is identified by position, never by URL.
			// The response body or transport error is logged verbatim as a diagnostic and may echo the target.
			wfDebugLog(
				'MultiPurge',
				sprintf( 'Result for request %d is: %s', $index + 1, $status )
			);
			$good = false;
		}

		return $good;
	}

	/**
	 * Whether run() submitted at least one purge request to the HTTP client.
	 * Lets callers tell a vetoed or fully filtered run, which still returns true, from real HTTP work.
	 * Queue semantics are unchanged: no-work runs keep returning success and are never retried for this.
	 *
	 * @return bool
	 */
	public function didSendPurgeRequests(): bool {
		return $this->sentPurgeRequests;
	}

	/**
	 * Original targets the service hook or the post-hook policy removed from the last run, also after a failed run.
	 * Purely informational: it never changes the run's boolean result or queue behavior.
	 *
	 * @return string[] Dropped original targets
	 */
	public function getDroppedTargets(): array {
		return $this->droppedTargets;
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
