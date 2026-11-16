<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use ArrayObject;
use HashBagOStuff;
use JobQueue;
use JobQueueDB;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use WANObjectCache;
use Wikimedia\Http\MultiHttpClient;

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
	 * A rate-limited attempt fails the job; the queue recycles the same claimed job
	 * once its claim expires, and the retried job then succeeds and is acknowledged.
	 *
	 * @return void
	 */
	public function testRateLimitedJobIsRetriedByQueueAndCompletes() {
		$this->mockHttpResponses( [ 429, 200 ] );

		$this->queue->push( new MultiPurgeJob( [
			'urls' => $this->makeUrls( 9 ),
			'service' => Cloudflare::class,
		] ) );

		$first = $this->queue->pop();
		$this->assertFalse( $first->run(), 'A 429 response must fail the job' );

		// The runner does not acknowledge a failed job, so it stays claimed
		$this->queue->flushCaches();
		$this->assertSame( 0, $this->queue->getSize() );
		$this->assertSame( 1, $this->queue->getAcquiredCount() );

		$this->expireClaimAndRecycle();

		$this->queue->flushCaches();
		$this->assertSame( 1, $this->queue->getSize(), 'The claimed job was recycled' );
		$this->assertSame( 0, $this->queue->getAcquiredCount() );

		$second = $this->queue->pop();
		$this->assertInstanceOf( MultiPurgeJob::class, $second );
		$this->assertSame( $first->getParams(), $second->getParams(), 'The same job was retried' );
		$this->assertTrue( $second->run() );

		$this->queue->ack( $second );
		$this->queue->flushCaches();
		$this->assertSame( 0, $this->queue->getSize() );
		$this->assertSame( 0, $this->queue->getAcquiredCount() );
		$this->assertSame( 0, $this->queue->getAbandonedCount() );

		$this->assertNoCustomRetryJobWasQueued();
	}

	/**
	 * The queue stops recycling a job after its attempt limit; the extension must not
	 * report success for any failed attempt or keep the job looping.
	 *
	 * @return void
	 */
	public function testFailedJobRetriesAreExhaustedAndAbandoned() {
		$this->mockHttpResponses( array_fill( 0, self::MAX_TRIES, 429 ) );

		$this->queue->push( new MultiPurgeJob( [
			'urls' => $this->makeUrls( 9 ),
			'service' => Cloudflare::class,
		] ) );

		for ( $attempt = 1; $attempt <= self::MAX_TRIES; $attempt++ ) {
			$job = $this->queue->pop();
			$this->assertInstanceOf( MultiPurgeJob::class, $job, "Attempt $attempt was offered" );
			$this->assertFalse( $job->run(), "Attempt $attempt must not report success" );

			$this->expireClaimAndRecycle();
		}

		$this->assertFalse( $this->queue->pop(), 'The exhausted job is not recycled again' );

		$this->queue->flushCaches();
		$this->assertSame( 1, $this->queue->getAbandonedCount() );
		$this->assertNoCustomRetryJobWasQueued();
	}

	/**
	 * Non-rate-limit errors fail the job the same way, so the queue can retry them.
	 *
	 * @return void
	 */
	public function testNonRateLimitErrorFailsTheJobForQueueRetry() {
		$this->mockHttpResponses( [ 500 ] );

		$this->queue->push( new MultiPurgeJob( [
			'urls' => $this->makeUrls( 9 ),
			'service' => Cloudflare::class,
		] ) );

		$job = $this->queue->pop();
		$this->assertFalse( $job->run() );

		// The failed job stays claimed until the backend recycles or abandons it
		$this->queue->flushCaches();
		$this->assertSame( 1, $this->queue->getAcquiredCount() );
		$this->assertSame( 0, $this->queue->getSize() );
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
	 * The real JobRunner leaves a failed retryable job unacknowledged; after the queue's
	 * claim timeout the same job is offered again and can then succeed and be removed.
	 * Only the claim timeout itself is simulated, by backdating the claim timestamp.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testJobRunnerRetriesFailedJobAfterClaimExpiry() {
		$this->mockHttpResponses( [ 500, 200 ] );

		// Route this job type's queue to the test-DB-backed JobQueueDB, built through core's service wiring.
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [
			'default' => [ 'class' => \JobQueueMemory::class ],
			'MultiPurgePages' => [
				'class' => JobQueueDBSingle::class,
				'claimTTL' => self::CLAIM_TTL,
			],
		] );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );

		$group = $this->getServiceContainer()->getJobQueueGroupFactory()->makeJobQueueGroup();
		$group->push( new MultiPurgeJob( [
			'urls' => $this->makeUrls( 9 ),
			'service' => Cloudflare::class,
		] ) );

		$runner = $this->getServiceContainer()->getJobRunner();

		$first = $runner->run( [ 'maxJobs' => 1 ] );
		$this->assertSame( 'job-limit', $first['reached'] );
		$this->assertCount( 1, $first['jobs'] );
		$this->assertSame( 'failed', $first['jobs'][0]['status'] );

		// The runner did not acknowledge the failed job, so it stays claimed
		$dbw = $this->getDb( DB_PRIMARY );
		$claimed = $dbw->newSelectQueryBuilder()
			->select( 'job_id' )
			->from( 'job' )
			->where( [ 'job_cmd' => 'MultiPurgePages', $dbw->expr( 'job_token', '!=', '' ) ] )
			->caller( __METHOD__ )->fetchField();
		$this->assertNotFalse( $claimed, 'The failed job was left unacknowledged and claimed' );

		$this->expireClaimAndRecycle();

		$second = $runner->run( [ 'maxJobs' => 1 ] );
		$this->assertSame( 'job-limit', $second['reached'] );
		$this->assertCount( 1, $second['jobs'] );
		$this->assertSame( 'ok', $second['jobs'][0]['status'] );

		// The acknowledged job is gone from the queue
		$this->queue->flushCaches();
		$this->assertSame( 0, $this->queue->getSize() );
		$this->assertSame( 0, $this->queue->getAcquiredCount() );
		$this->assertSame( 0, $this->queue->getAbandonedCount() );
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
	 * The extension must not schedule its own retry jobs; MediaWiki's queue owns retries.
	 *
	 * @return void
	 */
	private function assertNoCustomRetryJobWasQueued(): void {
		DeferredUpdates::doUpdates();

		$groupQueue = MediaWikiServices::getInstance()->getJobQueueGroupFactory()
			->makeJobQueueGroup()
			->get( 'MultiPurgePages' );
		$groupQueue->flushCaches();
		$this->assertSame( 0, $groupQueue->getSize(), 'No custom retry job was enqueued' );
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
