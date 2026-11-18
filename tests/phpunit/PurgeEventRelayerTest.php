<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use ArrayObject;
use JobQueueError;
use JobQueueGroup;
use JobQueueMemory;
use MediaWiki\Cache\HTMLCacheUpdater;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\MultiPurge\PurgeEventRelayer;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;
use Wikimedia\Http\MultiHttpClient;

/**
 * @group MultiPurge
 * @group Database
 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer
 */
class PurgeEventRelayerTest extends MediaWikiIntegrationTestCase {

	public function testPurgesFileThumbnailsOncePerService(): void {
		// Keep the upload out of the wiki's own upload directory
		$dir = $this->getNewTempDirectory();
		$repo = $this->getConfVar( MainConfigNames::LocalFileRepo );
		$repo['directory'] = "$dir/images";
		$repo['thumbDir'] = "$dir/images/thumb";
		$repo['deletedDir'] = "$dir/deleted";
		$this->overrideConfigValues( [
			MainConfigNames::LocalFileRepo => $repo,
			MainConfigNames::UseInstantCommons => false,
			'MultiPurgeRunInQueue' => false,
			'MultiPurgeEnabledServices' => [ 'Varnish' ],
			'MultiPurgeVarnishServers' => [],
		] );
		// MW 1.46's test framework replaces the service with one that never reaches the relayer
		$services = $this->getServiceContainer();
		$this->setService(
			version_compare( MW_VERSION, '1.46', '<' ) ? 'HtmlCacheUpdater' : 'HTMLCacheUpdater',
			new HTMLCacheUpdater( $services->getHookContainer(), $services->getTitleFactory(), 0, false, 0 )
		);
		$purged = [];
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService', static function ( $service, $urls ) use ( &$purged ) {
			$purged[] = $urls;
		} );

		$source = MW_INSTALL_PATH . '/tests/phpunit/data/media/Png-native-test.png';
		$localRepo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$file = $localRepo->newFile( 'MultiPurgeThumbnailTest.png' );
		$this->assertStatusGood(
			$file->upload( $source, '', '', 0, false, false, $this->getTestSysop()->getUser() )
		);
		$thumbnails = [ '50px-MultiPurgeThumbnailTest.png', '100px-MultiPurgeThumbnailTest.png' ];
		foreach ( $thumbnails as $thumbnail ) {
			$this->assertStatusGood( $localRepo->quickImport( $source, $file->getThumbPath( $thumbnail ) ) );
		}
		DeferredUpdates::doUpdates();
		$purged = [];

		$file->purgeThumbnails();
		DeferredUpdates::doUpdates();

		$this->assertCount( 1, $purged );
		$this->assertEqualsCanonicalizing( array_map( [ $file, 'getThumbUrl' ], $thumbnails ), $purged[0] );
	}

	/**
	 * An inline run has no queue retry of its own, so a failed service job must be
	 * enqueued immediately for MediaWiki-managed retry instead of being lost.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @dataProvider provideInlineFailureResponses
	 * @param int $responseCode
	 * @return void
	 */
	public function testInlineFailureIsQueuedForRetry( int $responseCode ) {
		$this->enableVarnishInline();
		$this->mockServiceResponses( [ 'varnish.internal' => $responseCode ] );

		$relayer = new PurgeEventRelayer( [] );
		$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] );

		[ $job ] = $this->popQueuedMultiPurgeJobs();
		$this->assertNotNull( $job, 'The failed inline job was queued for retry' );
		$this->assertSame( Varnish::class, $job->getParams()['service'] );
		$this->assertSame( [ 'https://example.org/Foo' ], $job->getParams()['urls'] );
	}

	public static function provideInlineFailureResponses(): array {
		return [
			'server error' => [ 500 ],
			'rate limited' => [ 429 ],
		];
	}

	/**
	 * A batch where only some requests fail still fails the job and is queued for retry.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testPartiallyFailedBatchIsQueuedForRetry() {
		$this->enableVarnishInline();
		$this->mockServiceResponses( [ '/page-fail' => 500 ] );

		$relayer = new PurgeEventRelayer( [] );
		$relayer->notifyMulti( 'cdn-url-purges', [
			[ 'url' => 'https://example.org/page-ok' ],
			[ 'url' => 'https://example.org/page-fail' ],
		] );

		[ $job ] = $this->popQueuedMultiPurgeJobs();
		$this->assertNotNull( $job, 'The partially failed job was queued for retry' );
		$this->assertSame(
			[ 'https://example.org/page-ok', 'https://example.org/page-fail' ],
			$job->getParams()['urls']
		);
	}

	/**
	 * A failed service must not stop other services, and only the failed service is retried.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testFailedServiceIsQueuedAloneWhileOthersSucceed() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => false,
			'MultiPurgeEnabledServices' => [ Cloudflare::class, Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$sent = $this->mockServiceResponses( [ 'varnish.internal' => 500 ] );

		$relayer = new PurgeEventRelayer( [] );
		$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] );

		$this->assertNotEmpty(
			array_filter(
				$sent->getArrayCopy(),
				static fn ( array $request ): bool => str_contains( $request['url'], 'cloudflare' )
			),
			'The successful service was dispatched'
		);

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'Only the failed service was queued for retry' );
		$this->assertSame( Varnish::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * A service failing first must not stop the services configured after it.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testFailedServiceFirstStillDispatchesLaterServices() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => false,
			'MultiPurgeEnabledServices' => [ Varnish::class, Cloudflare::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$sent = $this->mockServiceResponses( [ 'varnish.internal' => 500 ] );

		$relayer = new PurgeEventRelayer( [] );
		$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] );

		$this->assertNotEmpty(
			array_filter(
				$sent->getArrayCopy(),
				static fn ( array $request ): bool => str_contains( $request['url'], 'cloudflare' )
			),
			'The service after the failing one was dispatched'
		);

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'Only the failed service was queued for retry' );
		$this->assertSame( Varnish::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * A thrown service failure is a failed job too, and throwing first must not stop
	 * the services configured after it.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testThrownServiceFailureIsQueuedForRetry() {
		$this->enableVarnishInline();
		$this->overrideConfigValue(
			'MultiPurgeEnabledServices',
			[ 'NoSuchService', Varnish::class ]
		);
		$sent = $this->mockServiceResponses( [] );

		$relayer = new PurgeEventRelayer( [] );
		$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] );

		$this->assertNotEmpty(
			array_filter(
				$sent->getArrayCopy(),
				static fn ( array $request ): bool => str_contains( $request['url'], 'varnish.internal' )
			),
			'The service after the throwing one was dispatched'
		);

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'Only the throwing service was queued for retry' );
		$this->assertSame( 'NoSuchService', $jobs[0]->getParams()['service'] );
	}

	/**
	 * If the queue rejects the retry job, the lost purge must be observable instead of silent.
	 * A purge that is neither executed nor scheduled fails the notification itself.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testRejectedRetryEnqueueIsObservable() {
		$this->enableVarnishInline();
		$this->mockServiceResponses( [ 'varnish.internal' => 500 ] );
		$this->installReadOnlyJobQueueGroupFactory();

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'error' );
		$this->setLogger( 'MultiPurge', $logger );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertFalse(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] ),
			'A lost purge must fail the notification'
		);
	}

	/**
	 * In queue mode a rejected enqueue must not escape the relayer, hide the failure,
	 * or stop the services configured after it. The read-only queue makes each real
	 * immediate enqueue reject the job.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testQueuedRejectedEnqueueIsLoggedAndFailsTheRelay() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'MultiPurgeEnabledServices' => [ Varnish::class, Cloudflare::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$this->installReadOnlyJobQueueGroupFactory();

		$logger = $this->createMock( LoggerInterface::class );
		// Both services must be attempted: the first rejection must not end the loop.
		$logger->expects( $this->exactly( 2 ) )->method( 'error' );
		$this->setLogger( 'MultiPurge', $logger );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertFalse(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] ),
			'A purge no service could keep must fail the notification'
		);
	}

	/**
	 * A rejected enqueue for one queued service must not lose the services after it.
	 *
	 * Queued dispatch must enqueue immediately: lazyPush defers the enqueue past the
	 * response in web mode, where a rejection can no longer reach this caller.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testQueuedRejectedEnqueueStillDispatchesLaterServices() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'MultiPurgeEnabledServices' => [ Varnish::class, Cloudflare::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );

		$workingGroup = $this->getServiceContainer()->getJobQueueGroup();
		$rejectingGroup = $this->getMockBuilder( JobQueueGroup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'push', 'lazyPush' ] )
			->getMock();
		// lazyPush cannot report a web rejection to the relayer, so it must not be used here.
		$rejectingGroup->expects( $this->never() )->method( 'lazyPush' );
		$rejectingGroup->method( 'push' )->willReturnCallback(
			static function ( $job ) use ( $workingGroup ) {
				if ( ( $job->getParams()['service'] ?? '' ) === Varnish::class ) {
					throw new JobQueueError( 'test queue rejected the varnish job' );
				}
				$workingGroup->push( $job );
			}
		);

		$factory = $this->createMock( JobQueueGroupFactory::class );
		$factory->method( 'makeJobQueueGroup' )->willReturn( $rejectingGroup );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );
		$this->getServiceContainer()->redefineService( 'JobQueueGroupFactory', static fn () => $factory );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertFalse(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] ),
			'The rejected service failed the notification'
		);

		[ $job ] = $this->popQueuedMultiPurgeJobs();
		$this->assertNotNull( $job, 'The service after the rejected one was still dispatched' );
		$this->assertSame( Cloudflare::class, $job->getParams()['service'] );
	}

	/**
	 * A scheduled retry is not a confirmed execution, but the handoff succeeded:
	 * the notification counts as relayed while the queue owns the outcome.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testInlineFailureQueuedForRetryStillRelays() {
		$this->enableVarnishInline();
		$this->mockServiceResponses( [ 'varnish.internal' => 500 ] );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertTrue(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] ),
			'The purge was handed to the queue, which owns the retry'
		);

		[ $job ] = $this->popQueuedMultiPurgeJobs();
		$this->assertNotNull( $job, 'The failed service was queued for retry' );
	}

	private function enableVarnishInline(): void {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => false,
			'MultiPurgeEnabledServices' => [ Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
		] );
	}

	/**
	 * @param array<string, int> $responsesByUrl Response code for requests whose URL contains the key
	 * @return ArrayObject Sent requests
	 */
	private function mockServiceResponses( array $responsesByUrl ): ArrayObject {
		$sent = new ArrayObject();
		$multiMock = $this->getMockBuilder( MultiHttpClient::class )
			->disableOriginalConstructor()
			->getMock();
		$multiMock->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) use ( $responsesByUrl, $sent ) {
				foreach ( $requests as $i => $request ) {
					$sent->append( $request );
					$code = 200;
					foreach ( $responsesByUrl as $needle => $responseCode ) {
						if ( str_contains( $request['url'], $needle ) ) {
							$code = $responseCode;
							break;
						}
					}
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

		return $sent;
	}

	/**
	 * @return array Remaining MultiPurge jobs in the real queue
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

	/**
	 * Real factories whose queues reject pushes, so a rejected retry enqueue can be
	 * exercised. The factory itself is rebuilt through core's service wiring, which
	 * supplies the stats service matching the running MediaWiki version.
	 *
	 * @return void
	 */
	private function installReadOnlyJobQueueGroupFactory(): void {
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [
			'default' => [ 'class' => JobQueueMemory::class, 'readOnlyReason' => 'test queue read-only' ],
		] );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );
	}
}
