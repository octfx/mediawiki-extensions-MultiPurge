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
use MediaWiki\Extension\MultiPurge\Services\StreamingPurgeService;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use Throwable;
use Wikimedia\Http\MultiHttpClient;

class MultiPurgeJob extends Job implements GenericParameterJob {

	/**
	 * This is the hard ceiling of request maps handed to one MultiHttpClient::runMulti() call.
	 * The one size bounds request generation, submission, and response accumulation.
	 * Each batch is consumed and checked before the job asks the service for more requests.
	 */
	public const MAX_REQUESTS_PER_RUN_MULTI = 100;

	/**
	 * @var Config Extension config passed to each service
	 */
	private $extensionConfig;

	/**
	 * @var array<string,StreamingPurgeService>
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
	 * These are the services the last run() skipped because they could not be instantiated, such as unknown names.
	 *
	 * @var string[]
	 */
	private array $invalidServices = [];

	/**
	 * Map of normalized service name to whether one of its batches failed in the last run.
	 * An accepted Cloudflare replacement handoff removes its entry, because the queue then owns that work.
	 *
	 * @var bool[]
	 */
	private array $failedServices = [];

	/**
	 * Targets of failed Cloudflare requests from the last run, the only chunks a replacement may carry.
	 *
	 * @var array<string,true>
	 */
	private array $failedCloudflareTargets = [];

	/**
	 * Largest valid Retry-After value, in seconds as received, from a failed Cloudflare request.
	 * Missing or malformed headers stay null so they introduce no backoff of their own.
	 *
	 * @var string|null
	 */
	private ?string $cloudflareRetryAfter = null;

	/**
	 * False once a failed Cloudflare request's targets could not be derived, which blocks a replacement.
	 *
	 * @var bool
	 */
	private bool $cloudflareTargetsKnown = true;

	private bool $finishedRequests = false;

	/**
	 * Whether the last run() handed off a replacement job that the queue accepted.
	 *
	 * @var bool
	 */
	private bool $retryHandedOff = false;

	/**
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

		$configuredOrder = $extensionConfig->get( 'MultiPurgeServiceOrder' );
		$serviceOrder = $configuredOrder ?? $services;

		wfDebugLog( 'MultiPurge', sprintf( 'Service Order: %s', json_encode( $serviceOrder ) ) );
		$serviceOrder = array_map( [ __CLASS__, 'normalizeServiceName' ], $serviceOrder );

		// The intersection silently drops invalid order entries, so unknown names are warned before it runs.
		// An unset order copies the enabled list, so it is not warned about twice.
		self::warnUnknownServiceNames( $services, 'MultiPurgeEnabledServices' );
		if ( $configuredOrder !== null ) {
			self::warnUnknownServiceNames( $serviceOrder, 'MultiPurgeServiceOrder' );
		}

		$enabled = array_intersect( $serviceOrder, $services );

		wfDebugLog( 'MultiPurge', sprintf( 'Enabled Services in Order: %s', json_encode( $enabled ) ) );

		return $enabled;
	}

	/**
	 * Warns about configured names that match no purge service, naming the setting they came from.
	 * The warning repeats on every evaluation because a misconfigured service name stays unusable until it is fixed.
	 * Once-per-process suppression could go stale after a configuration change, so none is used.
	 *
	 * @param string[] $names Normalized configured names
	 * @param string $setting Configuration variable the names came from
	 * @return void
	 */
	private static function warnUnknownServiceNames( array $names, string $setting ): void {
		foreach ( array_unique( $names ) as $name ) {
			if ( $name !== Cloudflare::class && $name !== Varnish::class ) {
				LoggerFactory::getInstance( 'MultiPurge' )->warning(
					'Unknown purge service "{service}" in {setting}; known services are Cloudflare and Varnish',
					[ 'service' => $name, 'setting' => $setting ]
				);
			}
		}
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
	 * MediaWiki normally ignores release times when deduplicating jobs.
	 * An earlier replacement cannot satisfy a later Retry-After for the same targets.
	 *
	 * @return array
	 */
	public function getDeduplicationInfo() {
		$info = parent::getDeduplicationInfo();
		if ( !empty( $this->params['replacement'] ) && isset( $this->params['jobReleaseTimestamp'] ) ) {
			$info['params']['jobReleaseTimestamp'] = $this->params['jobReleaseTimestamp'];
		}
		return $info;
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
	 * If no service is explicitly set, the purge runs against all enabled services.
	 *
	 * @param bool $handOffRetry False when the caller must wait for page invalidation.
	 * @return bool
	 */
	public function run( bool $handOffRetry = true ): bool {
		// A reused instance must report the state of the run just executed, never the previous one.
		$this->sentPurgeRequests = false;
		$this->droppedTargets = [];
		$this->invalidServices = [];
		$this->failedServices = [];
		$this->failedCloudflareTargets = [];
		$this->cloudflareRetryAfter = null;
		$this->cloudflareTargetsKnown = true;
		$this->finishedRequests = false;
		$this->retryHandedOff = false;

		if ( !isset( $this->params['service'] ) ) {
			$enabled = self::getServiceOrder();
		} else {
			$enabled = [ $this->params['service'] ];
		}

		wfDebugLog( 'MultiPurge', sprintf( 'Enabled Services in Order: %s', json_encode( $enabled ) ) );

		$http = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->createMultiClient( [ 'maxConnsPerHost' => 8, 'usePipelining' => true ] );

		// Failures aggregate across batches and services instead of aborting at the first one.
		$submitted = 0;

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
				$purgeService = $this->getPurgeService( $service );
			} catch ( InvalidArgumentException $e ) {
				// Retrying cannot fix an unknown service name.
				LoggerFactory::getInstance( 'MultiPurge' )->warning(
					'Could not instantiate purge service {service}: {error}',
					[ 'service' => $service, 'error' => $e->getMessage() ]
				);
				$this->invalidServices[] = $service;
				continue;
			}

			$batch = [];
			foreach ( $purgeService->yieldPurgeRequests( $urls ) as $request ) {
				$batch[] = $request;
				if ( count( $batch ) < self::MAX_REQUESTS_PER_RUN_MULTI ) {
					continue;
				}
				$submitted += $this->submitBatch( $http, $batch, $submitted, $purgeService );
				$batch = [];
			}
			if ( $batch !== [] ) {
				$submitted += $this->submitBatch( $http, $batch, $submitted, $purgeService );
			}
		}

		$this->finishedRequests = true;
		if ( $handOffRetry ) {
			$this->tryHandOffRetry();
		}

		return $this->failedServices === [];
	}

	/**
	 * Sends one bounded batch of requests and checks its responses.
	 * The batch is discarded once checked, which is what keeps response accumulation bounded.
	 * Failed Cloudflare requests are mapped back to their targets for the replacement handoff.
	 *
	 * @param MultiHttpClient $http
	 * @param array[] $requests At most MAX_REQUESTS_PER_RUN_MULTI request maps
	 * @param int $firstRequestIndex Number of requests already submitted in this run
	 * @param PurgeServiceInterface $service Service the batch belongs to
	 * @return int Number of requests in this batch
	 */
	private function submitBatch(
		MultiHttpClient $http,
		array $requests,
		int $firstRequestIndex,
		PurgeServiceInterface $service
	): int {
		// Distinguishes dispatched HTTP work from runs that returned success while sending nothing.
		$this->sentPurgeRequests = true;

		wfDebugLog( 'MultiPurge', sprintf( 'Calling %d purge urls', count( $requests ) ) );

		try {
			// An exception here fails only this batch; the remaining batches and services still get their chance.
			$statuses = $http->runMulti( $requests );
		} catch ( Exception $e ) {
			wfLogWarning( sprintf( '[MultiPurge]: %s', $e->getMessage() ) );
			// Every request of the call failed at transport level, with no response to consult.
			$this->noteFailedRequests( $service, $requests, [], array_keys( $requests ) );
			return count( $requests );
		}

		$failedIndexes = [];
		foreach ( $statuses as $index => $data ) {
			[ $code, , , $body, $error ] = $data['response'];
			if ( $code >= 200 && $code <= 299 ) {
				continue;
			}

			// Any unsuccessful response, including 429, leaves its request for the retry handoff.
			$status = $body ?? $error;
			// The request is identified by its position in the whole run, never by URL.
			// The response body or transport error is logged verbatim as a diagnostic and may echo the target.
			wfDebugLog(
				'MultiPurge',
				sprintf( 'Result for request %d is: %s', $firstRequestIndex + $index + 1, $status )
			);
			$failedIndexes[] = $index;
		}

		if ( $failedIndexes !== [] ) {
			$this->noteFailedRequests( $service, $requests, $statuses, $failedIndexes );
		}

		return count( $requests );
	}

	/**
	 * Record only failed targets so the replacement need not repeat successful chunks.
	 *
	 * @param PurgeServiceInterface $service Service the batch belonged to
	 * @param array[] $requests The batch's request maps
	 * @param array[] $responses Response maps keyed like $requests, or [] after a transport failure
	 * @param (int|string)[] $failedIndexes Positions in $requests that failed
	 * @return void
	 */
	private function noteFailedRequests(
		PurgeServiceInterface $service,
		array $requests,
		array $responses,
		array $failedIndexes
	): void {
		$this->failedServices[self::normalizeServiceName( $service::class )] = true;

		if ( !$service instanceof Cloudflare || !empty( $this->params['replacement'] ) ) {
			return;
		}

		foreach ( $failedIndexes as $index ) {
			$targets = Cloudflare::getTargetsForRequest( $requests[$index] ?? [] );
			if ( $targets === [] ) {
				// Retry the original if a replacement cannot carry every failed target.
				$this->cloudflareTargetsKnown = false;
				LoggerFactory::getInstance( 'MultiPurge' )->warning(
					'The targets of a failed Cloudflare request could not be derived; ' .
					'the failed purge stays with MediaWiki\'s queue retry'
				);
				continue;
			}

			foreach ( $targets as $target ) {
				$this->failedCloudflareTargets[$target] = true;
			}

			$response = $responses[$index]['response'] ?? [];
			if ( ( $response[0] ?? null ) === 429 ) {
				$delay = self::retryAfterSeconds( $response[2] ?? null );
				if ( $delay !== null ) {
					$this->cloudflareRetryAfter = self::largerDelay( $this->cloudflareRetryAfter, $delay );
				}
			}
		}
	}

	/**
	 * Keep the delay as digits until the representability check to avoid integer overflow.
	 *
	 * @param mixed $headers Response header map
	 * @return string|null
	 */
	private static function retryAfterSeconds( $headers ): ?string {
		$value = is_array( $headers ) ? ( $headers['retry-after'] ?? null ) : null;
		if ( !is_string( $value ) || !ctype_digit( $value ) ) {
			return null;
		}

		return ltrim( $value, '0' ) ?: '0';
	}

	/**
	 * The larger of two delay digit strings; longer digit strings are larger, equal lengths compare lexically.
	 *
	 * @param string|null $current
	 * @param string $candidate
	 * @return string
	 */
	private static function largerDelay( ?string $current, string $candidate ): string {
		if ( $current === null ) {
			return $candidate;
		}

		$candidateLength = strlen( $candidate );
		$currentLength = strlen( $current );
		if ( $candidateLength !== $currentLength ) {
			return $candidateLength > $currentLength ? $candidate : $current;
		}

		return strcmp( $candidate, $current ) > 0 ? $candidate : $current;
	}

	/**
	 * The original keeps responsibility for failed work until the queue accepts the replacement.
	 *
	 * @return bool Whether Cloudflare failures were handed off.
	 */
	public function tryHandOffRetry(): bool {
		if ( $this->retryHandedOff ) {
			return true;
		}
		// A thrown run may leave requests unattempted and absent from the failed-target list.
		if ( !$this->finishedRequests || !$this->cloudflareTargetsKnown || $this->failedCloudflareTargets === [] ) {
			return false;
		}
		if ( !$this->pushReplacementJob() ) {
			return false;
		}

		unset( $this->failedServices[Cloudflare::class] );
		$this->retryHandedOff = true;
		return true;
	}

	/**
	 * @return bool Whether the queue accepted the replacement job
	 */
	private function pushReplacementJob(): bool {
		$targets = array_keys( $this->failedCloudflareTargets );
		$params = [
			'urls' => $targets,
			'service' => Cloudflare::class,
			'replacement' => true,
		];

		$services = MediaWikiServices::getInstance();
		try {
			$group = $services->getJobQueueGroupFactory()->makeJobQueueGroup();
			if ( $this->cloudflareRetryAfter !== null && $this->cloudflareRetryAfter !== '0' ) {
				$release = self::representableRelease( $this->cloudflareRetryAfter );
				if ( $release === null ) {
					LoggerFactory::getInstance( 'MultiPurge' )->warning(
						'Cloudflare Retry-After of {delay} second(s) cannot be represented as a release time; ' .
						'the failed purge stays with MediaWiki\'s queue retry',
						[ 'delay' => $this->cloudflareRetryAfter ]
					);
					return false;
				}

				// The group routes unconfigured job types through a shared queue when type-agnostic.
				$config = $services->getMainConfig()->get( MainConfigNames::JobTypeConf );
				$type = $this->getType();
				if ( !isset( $config[$type] ) && !empty( $config['default']['typeAgnostic'] ) ) {
					$type = 'default';
				}
				if ( $group->get( $type )->delayedJobsEnabled() ) {
					$params['jobReleaseTimestamp'] = $release;
				} else {
					wfDebugLog( 'MultiPurge', 'Cloudflare Retry-After not honored: the queue cannot delay jobs' );
				}
			}

			$group->push( new self( $params ) );
		} catch ( Throwable $e ) {
			// A queue that cannot even be consulted for the handoff counts as a rejected handoff.
			LoggerFactory::getInstance( 'MultiPurge' )->error(
				'Queueing the Cloudflare replacement job was rejected: {error}',
				[ 'error' => $e->getMessage() ]
			);
			return false;
		}

		// A handoff is queued work, not a completed purge; the replacement owns the outcome.
		wfDebugLog( 'MultiPurge', isset( $params['jobReleaseTimestamp'] )
			? sprintf(
					'Queued a delayed Cloudflare replacement job for %d failed target(s), eligible at Unix time %d',
					count( $targets ),
					$params['jobReleaseTimestamp']
				)
			: sprintf( 'Queued a Cloudflare replacement job for %d failed target(s)', count( $targets ) )
		);
		return true;
	}

	/**
	 * Reject values the job cannot read back as a release time, rather than silently dropping the delay.
	 *
	 * @param string $delay Delay in seconds as received
	 * @return int|null
	 */
	private static function representableRelease( string $delay ): ?int {
		// Round up because the queue stores whole seconds.
		$now = (int)ceil( microtime( true ) );
		$limit = (string)( PHP_INT_MAX - $now );
		if ( self::largerDelay( $limit, $delay ) !== $limit ) {
			return null;
		}

		$release = $now + (int)$delay;
		if ( (int)wfTimestampOrNull( TS_UNIX, $release ) !== $release ) {
			return null;
		}

		return $release;
	}

	/**
	 * Whether the last run() handed off a replacement job that the queue accepted synchronously.
	 * A handoff is queued work, not a completed purge: callers must not report it as a success.
	 * JobRunner commits queue writes before acknowledging the original.
	 *
	 * @return bool
	 */
	public function didHandOffRetry(): bool {
		return $this->retryHandedOff;
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
	 * These are the services the last run() skipped because they could not be instantiated, such as unknown names.
	 * Misconfiguration is permanent, so these runs never fail or trigger queue retries.
	 * Callers use this to report the configuration problem instead of a successful purge.
	 *
	 * @return string[] Skipped service names
	 */
	public function getInvalidServices(): array {
		return $this->invalidServices;
	}

	/**
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

	private function getPurgeService( string $class ): StreamingPurgeService {
		$class = self::normalizeServiceName( $class );
		if ( isset( $this->serviceContainer[$class] ) ) {
			return $this->serviceContainer[$class];
		}

		$instance = match ( $class ) {
			Cloudflare::class => new Cloudflare( $this->extensionConfig ),
			Varnish::class => new Varnish( $this->extensionConfig ),
			default => throw new InvalidArgumentException( sprintf( 'Service "%s" not recognized.', $class ) ),
		};
		$instance->setup();

		$this->serviceContainer[$class] = $instance;
		return $instance;
	}
}
