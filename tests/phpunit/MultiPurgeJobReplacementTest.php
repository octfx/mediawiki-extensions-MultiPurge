<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use ArrayObject;
use JobQueueMemory;
use JobQueueRedis;
use JobSpecification;
use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use TestLogger;
use Wikimedia\Http\MultiHttpClient;

require_once __DIR__ . '/BatchRecorder.php';

/**
 * @group MultiPurge
 * @group Database
 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob
 */
class MultiPurgeJobReplacementTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
	}

	/** @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run */
	public function testSuccessfulCloudflareResponseWithEmptyTransportErrorDoesNotRetry(): void {
		$this->installRecordingClient( new BatchRecorder( static fn (): array => [ 200, 'OK', [], 'ok', '' ] ) );
		$job = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/1' ],
			'service' => Cloudflare::class,
		] );

		$this->assertTrue( $job->run() );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs() );
	}

	/** @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run */
	public function testRateLimitLogIdentifiesRequestWithoutUpstreamText(): void {
		$logger = new TestLogger( true, null, true );
		$this->setLogger( 'MultiPurge', $logger );
		$this->installRecordingClient( new BatchRecorder(
			static fn (): array => [ 429, 'Too Many Requests', [], 'upstream-private-marker', '' ]
		) );
		$job = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/1' ],
			'service' => Cloudflare::class,
		] );

		$this->assertTrue( $job->run() );

		$this->assertContains( 'Result for request 1 is: HTTP 429', array_column( $logger->getBuffer(), 1 ) );
		$this->assertStringNotContainsString( 'upstream-private-marker', (string)json_encode( $logger->getBuffer() ) );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @dataProvider provideCompletedHttpFailureErrorSlots
	 * @param string $errorSlot Error string core leaves in the response for a completed HTTP failure
	 * @return void
	 */
	public function testCompletedHttpFailureLogNamesRequestAndStatusWithoutUpstreamText( string $errorSlot ) {
		$logger = new TestLogger( true, null, true );
		$this->setLogger( 'MultiPurge', $logger );
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 1 );
		$this->installRecordingClient( new BatchRecorder(
			static fn ( int $call, int|string $index, array $request ): array => $index === 1
				// Both slots describe the same completed HTTP failure; only the transport differs.
				? [ 500, 'Internal Server Error', [], 'upstream-private-marker', $errorSlot ]
				: [ 200, 'OK', [], 'ok', '' ]
		) );
		$job = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/1', 'https://example.org/2' ],
			'service' => Cloudflare::class,
		] );

		$this->assertTrue( $job->run(), 'The handoff still covers the failed chunk' );

		$this->assertContains(
			'Result for request 2 is: HTTP 500',
			array_column( $logger->getBuffer(), 1 ),
			'The diagnostic names the request index and the HTTP status the error slot hides'
		);
		$this->assertStringNotContainsString( 'upstream-private-marker', (string)json_encode( $logger->getBuffer() ) );
		$this->assertStringNotContainsString( 'http-bad-status', (string)json_encode( $logger->getBuffer() ),
			'The core fallback\'s message key is not a diagnostic either' );
	}

	public static function provideCompletedHttpFailureErrorSlots(): array {
		return [
			// Core's cURL path leaves the error string empty on a completed HTTP failure.
			'cURL leaves the error slot empty' => [ '' ],
			// Core's non-cURL fallback reports the http-bad-status message key instead.
			'non-cURL fallback reports http-bad-status' => [ 'http-bad-status' ],
		];
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testFailedCloudflareChunksAreHandedOffWithoutSuccessfulChunks() {
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 2 );
		$recorder = new BatchRecorder(
			static fn ( int $call, int|string $index, array $request ): array => $index === 0
				? [ 429, 'Too Many Requests', [ 'retry-after' => '42' ], null, null ]
				: [ 200, 'OK', [], 'ok', null ]
		);
		$this->installRecordingClient( $recorder );

		// Jobs queued before upgrade must still work without a replacement flag.
		$job = new MultiPurgeJob( [
			'urls' => [
				'https://example.org/1',
				'https://example.org/2',
				'https://example.org/3',
			],
			'service' => Cloudflare::class,
		] );

		$this->assertTrue(
			$job->run(),
			'The accepted handoff covers the failed chunk, so the original work needs no queue retry'
		);
		$this->assertTrue( $job->didHandOffRetry(), 'The handoff is observable on the job' );
		$this->assertTrue( $job->didSendPurgeRequests(), 'The original still dispatched its requests' );

		$queued = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $queued, 'Exactly one replacement job is handed off' );
		[ 'urls' => $urls, 'service' => $service, 'replacement' => $replacement ] = $queued[0]->getParams();
		$this->assertSame(
			[ 'https://example.org/1', 'https://example.org/2' ],
			$urls,
			'Only the failed chunk\'s targets are retried'
		);
		$this->assertSame( Cloudflare::class, $service );
		$this->assertTrue( $replacement, 'The replacement flag survives the handoff' );
	}

	/**
	 * @param BatchRecorder $recorder
	 * @return void
	 */
	/** @var BatchRecorder|null Recorder of the last installed client */
	private ?BatchRecorder $lastRecorder = null;

	private function installRecordingClient( BatchRecorder $recorder ): void {
		$this->lastRecorder = $recorder;
		$multiMock = $this->getMockBuilder( MultiHttpClient::class )
			->disableOriginalConstructor()
			->getMock();
		$multiMock->method( 'runMulti' )->willReturnCallback(
			static fn ( array $requests ) => $recorder->recordBatch( $requests )
		);

		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )
			->disableOriginalConstructor()
			->getMock();
		$httpMock->method( 'createMultiClient' )->willReturn( $multiMock );

		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @dataProvider provideReplacementFailureResponses
	 * @param array $failureResponse
	 * @return void
	 */
	public function testFailingReplacementReturnsFailureAndCreatesNoFurtherReplacement( array $failureResponse ) {
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 1 );
		$calls = 0;
		$recorder = new BatchRecorder(
			static function ( int $call, int|string $index, array $request ) use ( &$calls, $failureResponse ): array {
				$calls++;
				// The original and its replacement both fail; a later attempt succeeds again.
				return $calls <= 2 ? $failureResponse : [ 200, 'OK', [], 'ok', null ];
			}
		);
		$this->installRecordingClient( $recorder );

		$original = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/1' ],
			'service' => Cloudflare::class,
		] );
		$this->assertTrue( $original->run() );
		[ $replacement ] = $this->popQueuedMultiPurgeJobs();
		$this->assertInstanceOf( MultiPurgeJob::class, $replacement );

		$this->assertFalse( $replacement->run(), 'A failing replacement returns failure to the queue' );
		$this->assertFalse( $replacement->didHandOffRetry(), 'A replacement never hands off again' );

		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(),
			'No replacement of the replacement is queued, so no unbounded chain can form' );
	}

	public static function provideReplacementFailureResponses(): array {
		return [
			'rate limited again' => [ [ 429, null, [ 'retry-after' => '99' ], null, null ] ],
			'server error' => [ [ 500, null, [], 'failed', null ] ],
			'transport failed after success headers' => [ [ 200, 'OK', [], '', '(curl error: 28)' ] ],
		];
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testValidRetryAfterDelaysReplacementUntilRequestedTime() {
		$this->routeGroupTo( JobQueueMemoryDelayed::class );
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 1 );
		$this->installRecordingClient( new BatchRecorder(
			static fn (): array => [ 429, null, [ 'retry-after' => '000000000050' ], null, null ]
		) );

		$before = time();
		$job = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/1', 'https://example.org/2' ],
			'service' => Cloudflare::class,
		] );
		$this->assertTrue( $job->run() );

		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( 'MultiPurgePages' );
		$this->assertTrue( $queue->delayedJobsEnabled(), 'The test setup routes onto a delayed-capable queue' );

		$this->assertFalse( $queue->pop(), 'The delayed replacement is not eligible before its release time' );
		$this->assertSame( 1, $queue->getSize(), 'The delayed replacement stays queued' );

		$delayed = iterator_to_array( $queue->getAllQueuedJobs(), false );
		$this->assertCount( 1, $delayed );
		$release = $delayed[0]->getReleaseTimestamp();
		$this->assertGreaterThanOrEqual( $before + 50, $release, 'Never eligible before the requested time' );
		$this->assertLessThanOrEqual( time() + 51, $release, 'Only whole-second rounding is added' );

		// Simulating the release time passing, the same job becomes eligible and runs.
		$queue->backdateDelayedJobs();
		$popped = $queue->pop();
		$this->assertInstanceOf( MultiPurgeJob::class, $popped );
		$this->assertTrue( $popped->getParams()['replacement'] );
		$this->assertSame(
			[ 'https://example.org/1', 'https://example.org/2' ],
			$popped->getParams()['urls']
		);
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @dataProvider provideUnusableRetryAfter
	 * @param array $response
	 * @return void
	 */
	public function testUnusableRetryAfterUsesQueueTiming( array $response ) {
		$this->routeGroupTo( JobQueueMemoryDelayed::class );
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 1 );
		$this->installRecordingClient( new BatchRecorder( static fn (): array => $response ) );

		$job = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/1' ],
			'service' => Cloudflare::class,
		] );
		$this->assertTrue( $job->run() );

		[ $replacement ] = $this->popQueuedMultiPurgeJobs();
		$this->assertInstanceOf( MultiPurgeJob::class, $replacement );
		$this->assertNull(
			$replacement->getReleaseTimestamp(),
			'No custom delay is imposed when the header is unusable'
		);
	}

	public function testOverlappingReplacementsPreserveDifferentReleaseTimes(): void {
		$this->routeGroupTo( JobQueueMemoryDelayed::class );
		$responses = [ '10', '60' ];
		$this->installRecordingClient( new BatchRecorder(
			static function () use ( &$responses ): array {
				return [ 429, null, [ 'retry-after' => array_shift( $responses ) ], null, null ];
			}
		) );

		$params = [ 'urls' => [ 'https://example.org/1' ], 'service' => Cloudflare::class ];
		$this->assertTrue( ( new MultiPurgeJob( $params ) )->run() );
		$this->assertTrue( ( new MultiPurgeJob( $params ) )->run() );

		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( 'MultiPurgePages' );
		$this->assertSame( 2, $queue->getSize(), 'The earlier release cannot satisfy the later handoff' );
	}

	public static function provideUnusableRetryAfter(): array {
		return [
			'no header' => [ [ 429, null, [], null, null ] ],
			'non-429 header' => [ [ 503, null, [ 'retry-after' => '50' ], null, null ] ],
			'not numeric' => [ [ 429, null, [ 'retry-after' => 'soon' ], null, null ] ],
			'fractional seconds' => [ [ 429, null, [ 'retry-after' => '1.5' ], null, null ] ],
			'http date form' => [ [ 429, null, [ 'retry-after' => 'Wed, 21 Oct 2015 07:28:00 GMT' ], null, null ] ],
			'negative' => [ [ 429, null, [ 'retry-after' => '-1' ], null, null ] ],
		];
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testUnrepresentableRetryAfterFallsBackToMediaWikiRetry() {
		$this->routeGroupTo( JobQueueMemoryDelayed::class );
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 1 );
		$this->installRecordingClient( new BatchRecorder(
			static fn (): array => [ 429, null, [ 'retry-after' => '99999999999999999999' ], null, null ]
		) );

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$level|$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$job = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/1' ],
			'service' => Cloudflare::class,
		] );
		$this->assertFalse(
			$job->run(),
			'The original stays failed, so the queue retries the original work'
		);
		$this->assertFalse( $job->didHandOffRetry() );

		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'No replacement is queued' );

		$log = implode( "\n", $entries->getArrayCopy() );
		$this->assertStringContainsString( 'warning|Cloudflare Retry-After', $log );
		$this->assertStringContainsString( 'cannot be represented', $log,
			'The unrepresentable delay is reported, never silently shortened' );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testLargestRequestedDelayWinsAcrossChunks() {
		$this->routeGroupTo( JobQueueMemoryDelayed::class );
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 1 );
		$delays = [ '30', '60', '10' ];
		$this->installRecordingClient( new BatchRecorder(
			static fn ( int $call, int|string $index, array $request ): array =>
				[ 429, null, [ 'retry-after' => $delays[$index] ?? '30' ], null, null ]
		) );

		$before = time();
		$job = new MultiPurgeJob( [
			'urls' => $this->makeUrls( 3 ),
			'service' => Cloudflare::class,
		] );
		$this->assertTrue( $job->run() );

		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( 'MultiPurgePages' );
		$this->assertFalse( $queue->pop(), 'Not eligible before the largest requested delay' );
		[ $delayed ] = iterator_to_array( $queue->getAllQueuedJobs(), false );
		$this->assertGreaterThanOrEqual( $before + 60, $delayed->getReleaseTimestamp(),
			'The release time respects every included response'
		);
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @dataProvider provideJobTypeRouting
	 * @param array $jobTypeConf
	 * @param bool $expectDelayed
	 * @return void
	 */
	public function testDelayedHandoffFollowsJobTypeRouting( array $jobTypeConf, bool $expectDelayed ) {
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, $jobTypeConf );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 1 );
		$this->installRecordingClient( new BatchRecorder(
			static fn (): array => [ 429, null, [ 'retry-after' => '50' ], null, null ]
		) );

		$job = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/1' ],
			'service' => Cloudflare::class,
		] );
		$this->assertTrue( $job->run() );

		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( 'MultiPurgePages' );
		[ $replacement ] = iterator_to_array( $queue->getAllQueuedJobs(), false );
		$this->assertInstanceOf( MultiPurgeJob::class, $replacement );
		if ( $expectDelayed ) {
			$this->assertNotNull( $replacement->getReleaseTimestamp(), 'The delayed job carries its release time' );
		} else {
			$this->assertNull( $replacement->getReleaseTimestamp(),
				'The queue for this type gets no delayed job' );
		}
	}

	public static function provideJobTypeRouting(): array {
		return [
			'delayed default queue' => [
				[ 'default' => [ 'class' => JobQueueMemoryDelayed::class ] ],
				true,
			],
			'type-agnostic delayed default' => [
				[
					'default' => [ 'class' => JobQueueMemoryDelayed::class, 'typeAgnostic' => true ],
				],
				true,
			],
			'non-delayed queue for this type beside a delayed default' => [
				[
					'default' => [ 'class' => JobQueueMemoryDelayed::class ],
					'MultiPurgePages' => [ 'class' => JobQueueMemory::class ],
				],
				false,
			],
		];
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testTransportFailedChunksAreHandedOff() {
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 1 );
		$recorder = new BatchRecorder(
			static function ( int $call ): array {
				if ( $call === 1 ) {
					throw new RuntimeException( 'transport exploded' );
				}
				return [ 200, 'OK', [], 'ok', null ];
			}
		);
		$this->installRecordingClient( $recorder );

		$urls = $this->makeUrls( 4 );
		$job = new MultiPurgeJob( [ 'urls' => $urls, 'service' => Cloudflare::class ] );

		// The transport failure surfaces as wfLogWarning, which PHPUnit would turn into a test error.
		set_error_handler( static function ( int $errno, string $errstr ): bool {
			return $errno === E_USER_WARNING;
		} );
		try {
			$result = $job->run();
		} finally {
			restore_error_handler();
		}

		$this->assertTrue( $result, 'The accepted handoff covers the transport-failed chunk' );
		$this->assertTrue( $job->didHandOffRetry() );
		$this->assertSame( [ 4 ], $recorder->callSizes, 'All four requests went out in one bounded call' );

		[ $replacement ] = $this->popQueuedMultiPurgeJobs();
		$this->assertInstanceOf( MultiPurgeJob::class, $replacement );
		$this->assertSame( $urls, $replacement->getParams()['urls'],
			'Every request of the transport-failed call is failed work' );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testRejectedHandoffLeavesOriginalWorkForQueueRetry() {
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [ 'default' => [
			'class' => JobQueueMemory::class,
			'readOnlyReason' => 'test queue read-only',
		] ] );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 1 );
		$this->installRecordingClient( new BatchRecorder(
			static fn (): array => [ 429, null, [ 'retry-after' => '42' ], null, null ]
		) );

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$level|$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$job = new MultiPurgeJob( [
			'urls' => $this->makeUrls( 2 ),
			'service' => Cloudflare::class,
		] );
		$this->assertFalse( $job->run(), 'The rejected handoff keeps the original work failed' );
		$this->assertFalse( $job->didHandOffRetry() );

		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( 'MultiPurgePages' );
		$queue->flushCaches();
		$this->assertSame( 0, $queue->getSize(), 'Nothing was accepted' );
		$this->assertStringContainsString(
			'error|Queueing the Cloudflare replacement job was rejected',
			implode( "\n", $entries->getArrayCopy() )
		);
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testVarnishFailureBesideCloudflareHandoffKeepsRunFailed() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Varnish::class, Cloudflare::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
			'MultiPurgeCloudFlareUrlsPerRequest' => 1,
		] );
		$this->installRecordingClient( new BatchRecorder(
			static fn ( int $call, int|string $index, array $request ): array =>
				$request['method'] === 'PURGE'
					? [ 500, null, [], 'failed', null ]
					: [ 429, null, [ 'retry-after' => '5' ], null, null ]
		) );

			$job = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/1', 'https://example.org/2' ],
			] );
		$this->assertFalse( $job->run(), 'The Varnish failure is not covered by the Cloudflare handoff' );
		$this->assertTrue( $job->didHandOffRetry() );

		$queued = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $queued, 'Only the Cloudflare replacement is queued' );
		$this->assertSame( Cloudflare::class, $queued[0]->getParams()['service'] );
		$this->assertSame( [ 'https://example.org/1', 'https://example.org/2' ], $queued[0]->getParams()['urls'] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testFailuresAcrossBoundedBatchesCombineIntoOneReplacement() {
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 1 );
		$this->installRecordingClient( new BatchRecorder(
			static fn ( int $call, int|string $index, array $request ): array =>
				( $call === 1 && $index === 5 ) || ( $call === 2 && $index === 20 )
					? [ 429, null, [ 'retry-after' => '42' ], null, null ]
					: [ 200, 'OK', [], 'ok', null ]
		) );

		$urls = $this->makeUrls( 150 );
		$job = new MultiPurgeJob( [ 'urls' => $urls, 'service' => Cloudflare::class ] );

		$this->assertTrue( $job->run(), 'The accepted handoff covers both failed chunks' );
		$this->assertSame( [ 100, 50 ], $this->lastRecorder->callSizes,
			'The 100-request batch bound stays intact' );

		[ $replacement ] = $this->popQueuedMultiPurgeJobs();
		$this->assertInstanceOf( MultiPurgeJob::class, $replacement );
		$this->assertSame(
			[ $urls[5], $urls[120] ],
			$replacement->getParams()['urls'],
			'One replacement carries the failed targets from both batches'
		);
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testReplacementRunAppliesTheUrlPolicy() {
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 1 );
		$recorder = new BatchRecorder();
		$this->installRecordingClient( $recorder );

		$replacement = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/ok', 'https://evil.example/bad' ],
			'service' => Cloudflare::class,
			'replacement' => true,
		] );
		$this->assertTrue( $replacement->run(), 'A permanent policy rejection does not fail the job' );
		$this->assertCount( 1, $recorder->requests, 'Only the allowed target reached HTTP' );
		$this->assertSame(
			[ 'https://example.org/ok' ],
			json_decode( $recorder->requests[0]['postData'], true )['files']
		);
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs() );
	}

	/**
	 * Requires a dedicated Redis instance because the test deletes its queue data.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testRedisQueueStoresDelayedReplacement() {
		$server = getenv( 'MULTIPURGE_TEST_REDIS' );
		if ( !$server ) {
			$this->markTestSkipped( 'Set MULTIPURGE_TEST_REDIS to a disposable Redis instance' );
		}

		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [ 'default' => [
			'class' => JobQueueRedis::class,
			'redisServer' => $server,
			'redisConfig' => [ 'connectTimeout' => 1 ],
			'daemonized' => true,
			'claimTTL' => 3600,
		] ] );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );
		$this->overrideConfigValue( 'MultiPurgeCloudFlareUrlsPerRequest', 1 );
		$this->installRecordingClient( new BatchRecorder(
			static fn (): array => [ 429, null, [ 'retry-after' => '3600' ], null, null ]
		) );

		$before = time();
		$job = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/1' ],
			'service' => Cloudflare::class,
		] );
		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( 'MultiPurgePages' );
		try {
			$this->assertTrue( $job->run() );
			$this->assertTrue( $queue->delayedJobsEnabled() );
			$this->assertSame( 0, $queue->getSize(), 'A delayed job is not part of the unclaimed queue' );
			$this->assertSame( 1, $queue->getDelayedCount(), 'The replacement sits in the delayed set' );
			$this->assertFalse( $queue->pop(), 'The replacement is not offered before its release time' );

			[ $delayed ] = iterator_to_array( $queue->getAllDelayedJobs(), false );
			$this->assertTrue( $delayed->getParams()['replacement'] );
			$this->assertGreaterThanOrEqual( $before + 3600, $delayed->getReleaseTimestamp() );
		} finally {
			$queue->delete();
		}
	}

	/**
	 * Routes the MultiPurgePages queue type onto the given queue class.
	 *
	 * @param string $class
	 * @return void
	 */
	private function routeGroupTo( string $class ): void {
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [
			'default' => [ 'class' => $class ],
		] );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );
	}

	/**
	 * @param int $count
	 * @return string[]
	 */
	private function makeUrls( int $count ): array {
		return array_map(
			static fn ( int $i ): string => "https://example.org/$i",
			range( 1, $count )
		);
	}

	/**
	 * @return array Remaining MultiPurge jobs in the queue the job pushes to
	 */
	private function popQueuedMultiPurgeJobs(): array {
		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( 'MultiPurgePages' );
		$jobs = [];
		$job = $queue->pop();
		while ( $job ) {
			$jobs[] = $job;
			$job = $queue->pop();
		}
		return $jobs;
	}
}

/**
 * Use a queue with controllable time to test delayed eligibility without waiting.
 *
 * The suite loader only includes *Test.php files, so this helper cannot live in its own file.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class JobQueueMemoryDelayed extends JobQueueMemory {
	protected function supportsDelayedJobs(): bool {
		return true;
	}

	/**
	 * Lets JobQueueGroup route unconfigured job types onto a delayed default queue.
	 *
	 * @return bool
	 */
	protected function supportsTypeAgnostic(): bool {
		return true;
	}

	/**
	 * Offers only jobs whose release time has passed, like a real delayed-capable backend.
	 *
	 * @return RunnableJob|false
	 */
	protected function doPop() {
		$now = time();
		foreach ( self::$data[$this->type][$this->domain]['unclaimed'] ?? [] as $key => $spec ) {
			$release = $spec->getReleaseTimestamp();
			if ( $release !== null && $release > $now ) {
				continue;
			}

			unset( self::$data[$this->type][$this->domain]['unclaimed'][$key] );
			self::$data[$this->type][$this->domain]['claimed'][] = $spec;

			$job = $this->jobFromSpecInternal( $spec );
			$job->setMetadata( 'claimId', array_key_last( self::$data[$this->type][$this->domain]['claimed'] ) );
			return $job;
		}

		return false;
	}

	/**
	 * Simulates the release time passing, the way the queue tests backdate claim timestamps.
	 *
	 * @return void
	 */
	public function backdateDelayedJobs(): void {
		foreach ( self::$data[$this->type][$this->domain]['unclaimed'] ?? [] as $key => $spec ) {
			self::$data[$this->type][$this->domain]['unclaimed'][$key] = new JobSpecification(
				$spec->getType(),
				[ 'jobReleaseTimestamp' => time() - 1 ] + $spec->getParams()
			);
		}
	}
}
