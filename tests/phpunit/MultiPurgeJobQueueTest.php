<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use ArrayObject;
use HashBagOStuff;
use JobQueue;
use JobQueueDB;
use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use RuntimeException;
use WANObjectCache;
use Wikimedia\Http\MultiHttpClient;
use Wikimedia\Rdbms\IDatabase;

/**
 * Runs MultiPurgeJob against a real JobQueueDB to verify enqueue, retry, and
 * exhaustion behavior on the configured backend instead of mocked queue calls.
 *
 * @group MultiPurge
 * @group Database
 * @group medium
 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob
 */
class MultiPurgeJobQueueTest extends MediaWikiIntegrationTestCase {

	/** Matches the queue backend's default claim timeout in JobTypeConf */
	private const CLAIM_TTL = 3600;

	/** The queue's default total attempt limit */
	private const MAX_TRIES = 3;

	/** @var JobQueueDB */
	private $queue;

	/** @var ArrayObject<int, array> Every request handed to the mocked HTTP client */
	private $sentRequests;

	protected function setUp(): void {
		parent::setUp();

		// The jobs' urls point at example.org, which must be the allowed host.
		$this->overrideConfigValues( [ MainConfigNames::Server => 'https://example.org' ] );

		$services = $this->getServiceContainer();
		$this->queue = JobQueue::factory( [
			'class' => JobQueueDBSingle::class,
			'type' => 'MultiPurgePages',
			'domain' => WikiMap::getCurrentWikiDbDomain()->getId(),
			'stash' => new HashBagOStuff(),
			'wanCache' => new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ),
			'idGenerator' => $services->getGlobalIdGenerator(),
			'claimTTL' => self::CLAIM_TTL,
			'maxTries' => self::MAX_TRIES,
		] );
	}

	protected function tearDown(): void {
		if ( $this->queue ) {
			$this->queue->delete();
			$this->queue = null;
		}
		parent::tearDown();
	}

	/**
	 * Initial jobs must be immediately eligible: the DB queue rejects any job with a
	 * non-null release timestamp because it does not support delayed jobs.
	 *
	 * @dataProvider provideInitialBatches
	 * @param string $service
	 * @param int $urlCount
	 * @return void
	 */
	public function testInitialEnqueueIsImmediatelyEligible( string $service, int $urlCount ) {
		$job = new MultiPurgeJob( [
			'urls' => $this->makeUrls( $urlCount ),
			'service' => $service,
		] );

		$this->assertNull( $job->getReleaseTimestamp() );

		$this->queue->push( $job );

		$this->queue->flushCaches();
		$this->assertSame( 1, $this->queue->getSize() );

		$popped = $this->queue->pop();
		$this->assertInstanceOf( MultiPurgeJob::class, $popped );
		$this->assertNull( $popped->getReleaseTimestamp() );
		$this->assertSame( $service, $popped->getParams()['service'] );
	}

	public static function provideInitialBatches(): array {
		return [
			'Cloudflare with eight URLs' => [ Cloudflare::class, 8 ],
			'Cloudflare with nine URLs' => [ Cloudflare::class, 9 ],
			'Varnish with nine URLs' => [ Varnish::class, 9 ],
		];
	}

	/**
	 * A rate-limited original hands its failed chunk to a replacement job in the same queue.
	 * The runner acknowledges the original once the handoff is accepted, and the replacement
	 * carries the same work, succeeds, and is acknowledged in turn.
	 *
	 * @return void
	 */
	public function testRateLimitedJobHandsOffReplacementThatCompletes() {
		$this->routeGroupToThisQueue();
		$this->mockHttpResponses( [ 429, 200 ] );

		$this->queue->push( new MultiPurgeJob( [
			'urls' => $this->makeUrls( 9 ),
			'service' => Cloudflare::class,
		] ) );

		$original = $this->queue->pop();
		$this->assertInstanceOf( MultiPurgeJob::class, $original );
		$this->assertTrue(
			$original->run(),
			'The accepted handoff covers the failed chunk, so the original needs no queue retry'
		);
		$this->assertTrue( $original->didHandOffRetry() );

		$this->queue->ack( $original );

		$this->queue->flushCaches();
		$this->assertSame( 1, $this->queue->getSize(), 'Exactly one replacement job was enqueued' );

		$replacement = $this->queue->pop();
		$this->assertInstanceOf( MultiPurgeJob::class, $replacement );
		$this->assertSame( $this->makeUrls( 9 ), $replacement->getParams()['urls'],
			'The single failed chunk covered every target' );
		$this->assertTrue( $replacement->getParams()['replacement'],
			'The replacement flag survives queue serialization' );
		$this->assertNull( $replacement->getReleaseTimestamp(),
			'The database queue supports no delayed job, so none was requested' );

		$this->assertTrue( $replacement->run() );
		$this->queue->ack( $replacement );

		$this->queue->flushCaches();
		$this->assertSame( 0, $this->queue->getSize() );
		$this->assertSame( 0, $this->queue->getAcquiredCount() );
		$this->assertSame( 0, $this->queue->getAbandonedCount() );
	}

	/**
	 * The replacement's own failures return failure to the queue, which recycles the same
	 * claimed job until its attempt limit and then abandons it. No further replacement is
	 * ever created, and the job parameters stay identical across every attempt.
	 *
	 * @return void
	 */
	public function testReplacementJobRetriesAreExhaustedAndAbandoned() {
		$this->routeGroupToThisQueue();
		$this->mockHttpResponses( array_fill( 0, self::MAX_TRIES + 1, 429 ) );

		$this->queue->push( new MultiPurgeJob( [
			'urls' => $this->makeUrls( 9 ),
			'service' => Cloudflare::class,
		] ) );

		$original = $this->queue->pop();
		$this->assertTrue( $original->run(), 'The rate-limited original hands off its failed chunk' );
		$this->queue->ack( $original );

		$this->queue->flushCaches();
		$this->assertSame( 1, $this->queue->getSize(), 'The replacement was enqueued' );
		$replacement = $this->queue->pop();
		$this->assertInstanceOf( MultiPurgeJob::class, $replacement );
		$expectedParams = $replacement->getParams();

		for ( $attempt = 1; $attempt <= self::MAX_TRIES; $attempt++ ) {
			$job = $attempt === 1 ? $replacement : $this->queue->pop();
			$this->assertInstanceOf( MultiPurgeJob::class, $job, "Attempt $attempt was offered" );
			$this->assertSame( $expectedParams, $job->getParams(),
				'MediaWiki retries the same replacement job, flag included' );
			$this->assertFalse( $job->run(),
				"Attempt $attempt must not report success while it keeps failing" );

			// A later 429 must not create another replacement.
			$this->queue->flushCaches();
			$this->assertSame( 0, $this->queue->getSize(), "No replacement of the replacement after attempt $attempt" );

			$this->expireClaimAndRecycle();
		}

		$this->assertFalse( $this->queue->pop(), 'The exhausted replacement is not recycled again' );

		$this->queue->flushCaches();
		$this->assertSame( 1, $this->queue->getAbandonedCount() );
	}

	/**
	 * Non-rate-limit errors hand off their chunk too: the replacement is enqueued without a
	 * delay and the queue owns its retry timing, exactly as for a 429 without a usable header.
	 *
	 * @return void
	 */
	public function testNonRateLimitFailureHandsOffReplacementWithoutDelay() {
		$this->routeGroupToThisQueue();
		$this->mockHttpResponses( [ 500, 200 ] );

		$this->queue->push( new MultiPurgeJob( [
			'urls' => $this->makeUrls( 9 ),
			'service' => Cloudflare::class,
		] ) );

		$original = $this->queue->pop();
		$this->assertTrue( $original->run(), 'The handoff covers the failed chunk' );
		$this->queue->ack( $original );

		$this->queue->flushCaches();
		$this->assertSame( 1, $this->queue->getSize() );

		$replacement = $this->queue->pop();
		$this->assertNull( $replacement->getReleaseTimestamp(), 'No delay was requested' );
		$this->assertTrue( $replacement->run() );
		$this->queue->ack( $replacement );

		$this->queue->flushCaches();
		$this->assertSame( 0, $this->queue->getSize() );
		$this->assertSame( 0, $this->queue->getAcquiredCount() );
		$this->assertSame( 0, $this->queue->getAbandonedCount() );
	}

	/**
	 * A successful job is acknowledged and removed, without enqueueing anything else.
	 *
	 * @return void
	 */
	public function testSuccessfulJobCompletesAndIsRemoved() {
		$this->mockHttpResponses( [ 200 ] );

		$this->queue->push( new MultiPurgeJob( [
			'urls' => $this->makeUrls( 9 ),
			'service' => Cloudflare::class,
		] ) );

		$job = $this->queue->pop();
		$this->assertTrue( $job->run() );
		$this->assertNotEmpty( $this->sentRequests->getArrayCopy() );

		$this->queue->ack( $job );
		$this->queue->flushCaches();
		$this->assertSame( 0, $this->queue->getSize() );
		$this->assertSame( 0, $this->queue->getAcquiredCount() );
		$this->assertSame( 0, $this->queue->getAbandonedCount() );
	}

	/**
	 * Overlapping but distinct jobs run separately; identical unclaimed jobs are
	 * deduplicated by the queue.
	 *
	 * @return void
	 */
	public function testOverlappingJobsRunIndependently() {
		$this->mockHttpResponses( [ 200, 200 ] );

		$overlap = $this->makeUrls( 3 );
		$firstUrls = array_slice( $overlap, 0, 2 );
		$secondUrls = array_slice( $overlap, 1, 2 );

		$this->queue->push( new MultiPurgeJob( [ 'urls' => $firstUrls, 'service' => Cloudflare::class ] ) );
		$this->queue->push( new MultiPurgeJob( [ 'urls' => $secondUrls, 'service' => Cloudflare::class ] ) );

		$this->queue->flushCaches();
		$this->assertSame( 2, $this->queue->getSize(), 'Distinct overlapping jobs are both queued' );

		$this->queue->push( new MultiPurgeJob( [ 'urls' => $firstUrls, 'service' => Cloudflare::class ] ) );
		$this->queue->flushCaches();
		$this->assertSame( 2, $this->queue->getSize(), 'Identical unclaimed jobs are deduplicated' );

		$purged = [];
		$job = $this->queue->pop();
		while ( $job ) {
			$this->assertTrue( $job->run() );
			$this->queue->ack( $job );
			$purged[] = $job->getParams()['urls'];
			$job = $this->queue->pop();
		}

		$this->assertEqualsCanonicalizing( [ $firstUrls, $secondUrls ], $purged );
	}

	/**
	 * The real JobRunner executes a queued original whose run hands off its failed chunk,
	 * acknowledges the original, and then executes the handed-off replacement.
	 * Only the claim timeout itself is simulated, by backdating the claim timestamp.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testJobRunnerExecutesHandedOffReplacementJob() {
		$this->mockHttpResponses( [ 500, 200 ] );

		$this->routeGroupToThisQueue();

		$group = $this->getServiceContainer()->getJobQueueGroupFactory()->makeJobQueueGroup();
		$group->push( new MultiPurgeJob( [
			'urls' => $this->makeUrls( 9 ),
			'service' => Cloudflare::class,
		] ) );

		$runner = $this->getServiceContainer()->getJobRunner();

		$first = $runner->run( [ 'maxJobs' => 1 ] );
		$this->assertSame( 'job-limit', $first['reached'] );
		$this->assertCount( 1, $first['jobs'] );
		$this->assertSame( 'ok', $first['jobs'][0]['status'],
			'The accepted handoff lets the original complete from the queue\'s point of view' );

		$dbw = $this->getDb( DB_PRIMARY );
		$remaining = $dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'job' )
			->where( [ 'job_cmd' => 'MultiPurgePages' ] )
			->caller( __METHOD__ )->fetchField();
		$this->assertSame( 1, (int)$remaining, 'Only the unclaimed replacement remains in the queue' );

		$second = $runner->run( [ 'maxJobs' => 1 ] );
		$this->assertSame( 'job-limit', $second['reached'] );
		$this->assertCount( 1, $second['jobs'] );
		$this->assertSame( 'ok', $second['jobs'][0]['status'] );

		$this->queue->flushCaches();
		$this->assertSame( 0, $this->queue->getSize() );
		$this->assertSame( 0, $this->queue->getAcquiredCount() );
		$this->assertSame( 0, $this->queue->getAbandonedCount() );
	}

	/**
	 * The runner must not acknowledge the original if the replacement insert fails at commit.
	 */
	public function testDeferredReplacementInsertFailurePreservesOriginal(): void {
		$this->routeGroupToThisQueue( JobQueueDBRejectReplacement::class );
		$this->mockHttpResponses( [ 429 ] );
		$urls = $this->makeUrls( 9 );
		$this->queue->push( new MultiPurgeJob( [ 'urls' => $urls, 'service' => Cloudflare::class ] ) );

		$result = $this->getServiceContainer()->getJobRunner()->run( [ 'maxJobs' => 1 ] );
		$this->assertSame( 'failed', $result['jobs'][0]['status'] );
		$this->assertStringContainsString( 'Replacement insert failed', $result['jobs'][0]['error'] );
		$this->queue->flushCaches();
		$this->assertSame( 1, $this->queue->getAcquiredCount(), 'The original is still claimed' );
		$this->assertFalse( $this->queue->pop(), 'The replacement was not inserted' );

		$this->expireClaimAndRecycle();
		$recycled = $this->queue->pop();
		$this->assertInstanceOf( MultiPurgeJob::class, $recycled );
		$this->assertArrayNotHasKey( 'replacement', $recycled->getParams() );
		$this->assertSame( $urls, $recycled->getParams()['urls'] );
	}

	/**
	 * With SQLite and an open transaction, the database queue defers the actual insert
	 * to the commit, so a return from push() is a synchronous handoff, not a committed
	 * insert. This is why MultiPurge reports enqueue results as handoff only.
	 *
	 * @return void
	 */
	public function testSqliteInsertDefersUntilCommitInActiveTransaction() {
		$dbw = $this->getDb( DB_PRIMARY );
		if ( $dbw->getType() !== 'sqlite' ) {
			$this->markTestSkipped( 'Covers the SQLite branch of JobQueueDB::getDB' );
		}

		$jobCount = static fn (): int => (int)$dbw->selectField(
			'job', 'COUNT(*)', [ 'job_cmd' => 'MultiPurgePages' ], __METHOD__
		);

		$dbw->startAtomic( __METHOD__ );
		try {
			$this->queue->push( new MultiPurgeJob( [
				'urls' => $this->makeUrls( 1 ),
				'service' => Cloudflare::class,
			] ) );

			$this->assertSame(
				0,
				$jobCount(),
				'push() returned while the insert was still deferred to the commit'
			);
		} finally {
			$dbw->endAtomic( __METHOD__ );
		}

		$this->assertSame( 1, $jobCount(), 'The deferred insert ran at the commit' );
	}

	/**
	 * @param int[] $codes One response code per runMulti call, repeated after the list ends
	 * @return void
	 */
	private function mockHttpResponses( array $codes ): void {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );

		$this->sentRequests = new ArrayObject();
		$sent = $this->sentRequests;
		$calls = 0;
		$multiMock = $this->getMockBuilder( MultiHttpClient::class )
			->disableOriginalConstructor()
			->getMock();
		$multiMock->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) use ( $codes, $sent, &$calls ) {
				$code = $codes[ min( $calls, count( $codes ) - 1 ) ];
				$calls++;
				foreach ( $requests as $i => $request ) {
					$sent->append( $request );
					$request['response'] = [ $code, null, [], null, null ];
					$requests[$i] = $request;
				}
				return $requests;
			}
		);

		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )
			->disableOriginalConstructor()
			->getMock();
		$httpMock->method( 'createMultiClient' )->willReturn( $multiMock );
		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );
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
	 * Simulates the claim timeout passing without waiting: the queue's own recycle
	 * logic compares job_token_timestamp against wall-clock time, so backdate the
	 * claim and let the queue decide what happens to the job.
	 *
	 * @return void
	 */
	private function expireClaimAndRecycle(): void {
		$dbw = $this->getDb( DB_PRIMARY );
		$dbw->newUpdateQueryBuilder()
			->update( 'job' )
			->set( [ 'job_token_timestamp' => $dbw->timestamp( time() - self::CLAIM_TTL - 1 ) ] )
			->where( [
				'job_cmd' => 'MultiPurgePages',
				$dbw->expr( 'job_token', '!=', '' ),
			] )
			->caller( __METHOD__ )->execute();

		$this->queue->recycleAndDeleteStaleJobs();
	}

	/**
	 * Routes the job type the job pushes to onto this test's database queue, so handoff
	 * inserts land in the same real table the test observes.
	 *
	 * @param class-string<JobQueueDBSingle> $queueClass
	 * @return void
	 */
	private function routeGroupToThisQueue( string $queueClass = JobQueueDBSingle::class ): void {
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [
			'default' => [
				'class' => $queueClass,
				'claimTTL' => self::CLAIM_TTL,
			],
		] );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );
	}
}

/**
 * Binds the queue to the test's primary connection so it sees the same
 * temporary job table the test framework created.
 *
 * The suite loader only includes *Test.php files, so this helper cannot live in its own file.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class JobQueueDBSingle extends JobQueueDB {
	protected function getDB( $index ) {
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		return $lb->getConnection( $index, [], $this->domain );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class JobQueueDBRejectReplacement extends JobQueueDBSingle {
	public function doBatchPushInternal( IDatabase $dbw, array $jobs, $flags, $method ) {
		throw new RuntimeException( 'Replacement insert failed' );
	}
}
