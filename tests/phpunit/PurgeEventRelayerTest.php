<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use ArrayObject;
use JobQueueError;
use JobQueueGroup;
use JobQueueMemory;
use MediaWiki\Cache\HTMLCacheUpdater;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\PurgeEventRelayer;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
		$server = $this->getConfVar( MainConfigNames::Server );
		$this->assertEqualsCanonicalizing(
			array_map(
				static fn ( string $thumbUrl ): string => $server . $thumbUrl,
				array_map( [ $file, 'getThumbUrl' ], $thumbnails )
			),
			$purged[0],
			'Queued job params carry the canonical absolute thumbnail URLs'
		);
	}

	/**
	 * An inline run has no queue retry of its own, so a failed service job must be enqueued immediately for retry.
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
			'Server' => 'https://example.org',
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
			'Server' => 'https://example.org',
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
	 * A thrown service failure is a failed job too.
	 * Throwing first must not stop the services configured after it.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testThrownServiceFailureIsQueuedForRetry() {
		$this->enableVarnishInline();
		$this->overrideConfigValue(
			'MultiPurgeEnabledServices',
			[ Cloudflare::class, Varnish::class ]
		);
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( $service, array &$urls ): void {
				if ( $service === Cloudflare::class ) {
					throw new RuntimeException( 'service exploded' );
				}
			}
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
		$this->assertSame( Cloudflare::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * An unknown service name is permanent misconfiguration, not a purge failure.
	 * The relayer must not queue a retry that can only fail again, while the valid service still dispatches.
	 * The misconfiguration stays observable in the log.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testUnknownServiceNameIsSkippedWithoutQueueingARetry() {
		$this->enableVarnishInline();
		$this->overrideConfigValue(
			'MultiPurgeEnabledServices',
			[ 'NoSuchService', Varnish::class ]
		);
		$sent = $this->mockServiceResponses( [] );

		$logger = $this->createMock( LoggerInterface::class );
		// The name produces one warning from getServiceOrder and one from the job that skipped it.
		$logger->expects( $this->exactly( 2 ) )->method( 'warning' )->with(
			$this->anything(),
			$this->callback( static fn ( array $context ): bool =>
				( $context['service'] ?? null ) === 'NoSuchService' )
		);
		$this->setLogger( 'MultiPurge', $logger );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertTrue(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] ),
			'The misconfiguration itself must not fail the notification'
		);

		$this->assertNotEmpty(
			array_filter(
				$sent->getArrayCopy(),
				static fn ( array $request ): bool => str_contains( $request['url'], 'varnish.internal' )
			),
			'The valid service was dispatched'
		);

		$this->assertSame(
			[],
			$this->popQueuedMultiPurgeJobs(),
			'No retry was queued for the unknown service'
		);
	}

	/**
	 * An all-invalid configuration inline purges nothing and queues nothing, permanently.
	 * The notification must report that failure instead of success, and the lost purge must surface at error level.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testAllInvalidServicesFailTheNotificationWithoutClaimingSuccess() {
		$this->enableVarnishInline();
		$this->overrideConfigValue(
			'MultiPurgeEnabledServices',
			[ 'NoSuchService', 'AlsoNoService' ]
		);
		$sent = $this->mockServiceResponses( [] );

		$logger = $this->createMock( LoggerInterface::class );
		// Every unknown name produces one getServiceOrder warning and one job warning.
		$logger->expects( $this->exactly( 4 ) )->method( 'warning' );
		$logger->expects( $this->once() )->method( 'error' )->with(
			$this->stringContains( 'No purge service could run' ),
			$this->callback( static fn ( array $context ): bool =>
				( $context['services'] ?? null ) === 'NoSuchService, AlsoNoService' )
		);
		$this->setLogger( 'MultiPurge', $logger );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertFalse(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] ),
			'An all-invalid configuration must not claim a successful purge notification'
		);

		$this->assertSame( [], $sent->getArrayCopy(), 'Nothing was sent' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'No permanent retry was queued' );
	}

	/**
	 * Enabled services whose order intersects to nothing can never purge.
	 * The notification must report the failure instead of success, the typo must be named, and nothing may be queued.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testEmptyServiceIntersectionFailsNotification() {
		$this->enableVarnishInline();
		$this->overrideConfigValue( 'MultiPurgeServiceOrder', [ 'Varis' ] );
		$sent = $this->mockServiceResponses( [] );

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$level|$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertFalse(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] ),
			'A configuration that can never purge must not claim a successful notification'
		);

		$log = implode( "\n", $entries->getArrayCopy() );
		$this->assertStringContainsString( 'Unknown purge service', $log );
		$this->assertStringContainsString( '"service":"Varis"', $log );
		$this->assertStringContainsString( 'error|No purge service could run', $log );
		// The log context is JSON-encoded, so the class string's backslashes are doubled there.
		$this->assertStringContainsString( addslashes( Varnish::class ), $log,
			'The enabled service that never ran is named' );

		$this->assertSame( [], $sent->getArrayCopy(), 'Nothing was sent' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
	}

	/**
	 * A valid service failing next to an invalid one is handed to the queue for retry.
	 * The handoff succeeded, so the relayer must report success and must not claim a lost purge.
	 * The invalid name keeps its own warning.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testInvalidPlusFailedValidWithQueuedRetryKeepsHandoffSuccess() {
		$this->enableVarnishInline();
		$this->overrideConfigValue( 'MultiPurgeEnabledServices', [ 'NoSuchService', Varnish::class ] );
		$sent = $this->mockServiceResponses( [ 'varnish.internal' => 500 ] );

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$level|$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertTrue(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] ),
			'The successful retry handoff must not be reported as a failed notification'
		);

		$log = implode( "\n", $entries->getArrayCopy() );
		$this->assertStringContainsString( '"service":"NoSuchService"', $log );
		$this->assertStringContainsString( 'Job Status: error, queued for retry', $log );
		$this->assertSame(
			0,
			substr_count( $log, 'No purge service could run' ),
			'A queued retry means the purge is not lost'
		);

		$this->assertNotEmpty(
			array_filter(
					$sent->getArrayCopy(),
					static fn ( array $request ): bool => str_contains( $request['url'], 'varnish.internal' )
				),
				'The valid service was dispatched'
		);
		[ $retry ] = $this->popQueuedMultiPurgeJobs();
		$this->assertInstanceOf( MultiPurgeJob::class, $retry, 'The failed valid service was queued for retry' );
		$this->assertSame( Varnish::class, $retry->getParams()['service'] );
	}

	/**
	 * When the queue rejects that retry, the handoff really did fail and the notification reports it.
	 * That report goes through the enqueue rejection error, not by claiming every service was unusable.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testInvalidPlusFailedValidWithRejectedRetryReportsHandoffFailure() {
		$this->enableVarnishInline();
		$this->overrideConfigValue( 'MultiPurgeEnabledServices', [ 'NoSuchService', Varnish::class ] );
		$sent = $this->mockServiceResponses( [ 'varnish.internal' => 500 ] );
		$this->installReadOnlyJobQueueGroupFactory();

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$level|$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertFalse(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] ),
			'The rejected handoff fails the notification'
		);

		$log = implode( "\n", $entries->getArrayCopy() );
		$this->assertStringContainsString( 'queueing a retry was rejected', $log,
			'The handoff failure is logged as such' );
		$this->assertSame(
			0,
			substr_count( $log, 'No purge service could run' ),
			'The valid service ran and was dispatched; it is not an all-unusable configuration'
		);
		$this->assertStringContainsString( '"service":"NoSuchService"', $log );
	}

	/**
	 * An intentionally disabled extension (no enabled services) purges nothing by choice.
	 * The notification stays a silent success with no warnings and no lost-purge error.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testDisabledConfigurationStaysSilentSuccess() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => false,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [],
		] );
		$this->mockServiceResponses( [] );

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$level|$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertTrue(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] )
		);

		$log = implode( "\n", $entries->getArrayCopy() );
		$this->assertStringNotContainsString( 'Unknown purge service', $log );
		$this->assertStringNotContainsString( 'No purge service could run', $log );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs() );
	}

	/**
	 * A notification with no URLs leaves nothing to purge.
	 * It must succeed without a lost-purge error even when enabled services select no usable service.
	 * No work was requested that could be lost.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testEmptyNotificationSucceedsWithoutLostPurgeError() {
		$this->enableVarnishInline();
		$this->overrideConfigValue( 'MultiPurgeServiceOrder', [ 'Varis' ] );
		$this->mockServiceResponses( [] );

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$level|$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertTrue(
			$relayer->notifyMulti( 'cdn-url-purges', [] ),
			'An empty notification has nothing to lose and must not fail'
		);

		$log = implode( "\n", $entries->getArrayCopy() );
		$this->assertStringNotContainsString(
			'No purge service could run',
			$log,
			'No lost purge may be claimed when no URL was requested'
		);
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
	}

	/**
	 * A MultiPurgeOnPurgeUrls subscriber that removes every URL also leaves nothing to purge.
	 * Letting the hook continue must not change that outcome.
	 * Success is required without a lost-purge error even with an enabled-but-unusable selection.
	 * A nonempty notification against the same configuration still errors.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testHookEmptiedUrlsSucceedWithoutLostPurgeError() {
		$this->enableVarnishInline();
		$this->overrideConfigValue( 'MultiPurgeServiceOrder', [ 'Varis' ] );
		$this->mockServiceResponses( [] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeUrls',
			static function ( array &$urls ): bool {
				$urls = [];
				return true;
			}
		);

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$level|$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertTrue(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] ),
			'The emptied list leaves nothing to purge and must not fail'
		);

		$log = implode( "\n", $entries->getArrayCopy() );
		$this->assertStringNotContainsString(
			'No purge service could run',
			$log,
			'No lost purge may be claimed when the subscriber removed every URL'
		);
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );

		// The same misconfigured selection still fails a notification that kept its URLs.
		$this->setTemporaryHook( 'MultiPurgeOnPurgeUrls',
			static function ( array &$urls ): bool {
				return true;
			}
		);
		$this->assertFalse(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] ),
			'A nonempty notification against an unusable selection still reports the lost purge'
		);
		$this->assertStringContainsString( 'No purge service could run',
			implode( "\n", $entries->getArrayCopy() ) );
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
	 * In queue mode a rejected enqueue must not escape the relayer, hide the failure, or stop later services.
	 * The read-only queue makes each real immediate enqueue reject the job.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testQueuedRejectedEnqueueIsLoggedAndFailsTheRelay() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'Server' => 'https://example.org',
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
	 * Queued dispatch must enqueue immediately: lazyPush defers the enqueue past the response in web mode.
	 * A rejection could then no longer reach this caller.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testQueuedRejectedEnqueueStillDispatchesLaterServices() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'Server' => 'https://example.org',
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

	/**
	 * A failed enqueue logs the queue's exception, and a DB queue error embeds the serialized job parameters.
	 * Rejected targets must never be in those parameters, or a credential-bearing rejected URL reaches the log.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::filterDispatchUrls
	 * @return void
	 */
	public function testQueuePushFailureNeverSerializesRejectedUrls() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Varnish::class, Cloudflare::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
		] );

		$captured = new ArrayObject();
		$group = $this->getMockBuilder( JobQueueGroup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'push' ] )
			->getMock();
		$group->method( 'push' )->willReturnCallback(
			static function ( $job ) use ( $captured ): void {
				$captured[] = $job;
				// A DBQueryError carries the failing statement with the serialized params.
				throw new JobQueueError(
					'DBQueryError: INSERT INTO job (job_params) VALUES ('
					. serialize( $job->getParams() ) . ')'
				);
			}
		);
		$factory = $this->createMock( JobQueueGroupFactory::class );
		$factory->method( 'makeJobQueueGroup' )->willReturn( $group );
		$this->getServiceContainer()->redefineService( 'JobQueueGroupFactory', static fn () => $factory );

		$entries = $this->installLogSpy();

		$relayer = new PurgeEventRelayer( [] );
		$this->assertFalse(
			$relayer->notifyMulti( 'cdn-url-purges', [
				[ 'url' => 'https://example.org/ok' ],
				[ 'url' => 'https://user:secret@evil.example/x' ],
			] ),
			'The failed enqueue still fails the notification'
		);

		$this->assertCount( 2, $captured, 'Both services received the valid target' );
		foreach ( $captured as $job ) {
			$this->assertSame( [ 'https://example.org/ok' ], $job->getParams()['urls'] );
		}
		foreach ( $entries as $entry ) {
			$this->assertStringNotContainsString( 'secret', $entry, 'Rejected input never reaches the log' );
		}
		$this->assertCount( 1, array_filter(
			$entries->getArrayCopy(),
			static fn ( string $entry ): bool => str_starts_with( $entry, 'Purge target rejected by the host policy' )
		), 'The rejected input is logged once, not once per service' );
	}

	/**
	 * The inline fallback queues the failed service's job for retry, which must also be free of rejected targets.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::filterDispatchUrls
	 * @return void
	 */
	public function testInlineRetryEnqueueNeverSerializesRejectedUrls() {
		$this->enableVarnishInline();
		$this->mockServiceResponses( [ 'varnish.internal' => 500 ] );

		$captured = new ArrayObject();
		$group = $this->getMockBuilder( JobQueueGroup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'push' ] )
			->getMock();
		$group->method( 'push' )->willReturnCallback(
			static function ( $job ) use ( $captured ): void {
				$captured[] = $job;
				throw new JobQueueError(
					'DBQueryError: INSERT INTO job (job_params) VALUES ('
					. serialize( $job->getParams() ) . ')'
				);
			}
		);
		$factory = $this->createMock( JobQueueGroupFactory::class );
		$factory->method( 'makeJobQueueGroup' )->willReturn( $group );
		$this->getServiceContainer()->redefineService( 'JobQueueGroupFactory', static fn () => $factory );

		$entries = $this->installLogSpy();

		$relayer = new PurgeEventRelayer( [] );
		$this->assertFalse(
			$relayer->notifyMulti( 'cdn-url-purges', [
				[ 'url' => 'https://example.org/ok' ],
				[ 'url' => 'https://user:secret@evil.example/x' ],
			] ),
			'The failed retry enqueue fails the notification'
		);

		$this->assertNotEmpty( $captured->getArrayCopy(), 'Only the failed service was queued' );
		foreach ( $captured as $job ) {
			$this->assertSame( [ 'https://example.org/ok' ], $job->getParams()['urls'] );
		}
		foreach ( $entries as $entry ) {
			$this->assertStringNotContainsString( 'secret', $entry );
		}
	}

	/**
	 * When every target is rejected there is nothing to hand off: no empty job is enqueued and nothing is retried.
	 * The secret-bearing rejected input never reaches job parameters or the log.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::filterDispatchUrls
	 * @return void
	 */
	public function testAllRejectedUrlsAreNeverEnqueued() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
		] );

		$captured = new ArrayObject();
		$group = $this->getMockBuilder( JobQueueGroup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'push' ] )
			->getMock();
		$group->method( 'push' )->willReturnCallback(
			static function ( $job ) use ( $captured ): void {
				$captured[] = $job;
			}
		);
		$factory = $this->createMock( JobQueueGroupFactory::class );
		$factory->method( 'makeJobQueueGroup' )->willReturn( $group );
		$this->getServiceContainer()->redefineService( 'JobQueueGroupFactory', static fn () => $factory );

		$entries = $this->installLogSpy();

		$relayer = new PurgeEventRelayer( [] );
		$this->assertTrue(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://user:secret@evil.example/x' ] ] ),
			'The notification is processed; the rejection is permanent and final'
		);

		$this->assertSame( [], $captured->getArrayCopy(), 'No empty job is enqueued' );
		foreach ( $entries as $entry ) {
			$this->assertStringNotContainsString( 'secret', $entry );
		}
	}

	/**
	 * An inline Cloudflare failure is handed off as a replacement job inside the run itself.
	 * The relayer reports the handoff, not a completed purge, and does not enqueue the whole
	 * original job a second time; only the failed chunk's targets are queued.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testInlineCloudflareFailureIsHandedOffAsReplacementJob(): void {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => false,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
			'MultiPurgeCloudFlareUrlsPerRequest' => 1,
		] );

		// One Cloudflare request per URL, so the first target fails while the second succeeds.
		$sent = new ArrayObject();
		$multiMock = $this->getMockBuilder( MultiHttpClient::class )
			->disableOriginalConstructor()
			->getMock();
		$multiMock->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) use ( $sent ) {
				foreach ( $requests as $i => $request ) {
					$sent->append( $request );
					$request['response'] = $i === 0
						? [ 429, null, [ 'retry-after' => '3' ], null, null ]
						: [ 200, null, [], null, null ];
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

		$entries = $this->installLogSpy();

		$relayer = new PurgeEventRelayer( [] );
		$this->assertTrue(
			$relayer->notifyMulti( 'cdn-url-purges', [
				[ 'url' => 'https://example.org/failed' ],
			[ 'url' => 'https://example.org/succeeded' ],
			] ),
			'The accepted handoff relays the purge request'
		);

		$log = implode( "\n", $entries->getArrayCopy() );
		$this->assertStringContainsString( 'Job Status: error, retry handed off', $log,
			'The handoff is reported as queued retry work' );
		$this->assertStringNotContainsString( 'Job Status: success', $log,
			'A handed-off retry is not a completed purge' );
		$this->assertStringNotContainsString( 'queued for retry', $log,
			'The relayer does not enqueue the whole original job on top of the replacement' );

		$this->assertCount( 2, $sent->getArrayCopy(), 'Both targets were dispatched' );
		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'Only the replacement job is queued' );
		$this->assertSame( [ 'https://example.org/failed' ], $jobs[0]->getParams()['urls'],
			'The replacement carries only the failed chunk\'s targets' );
		$this->assertTrue( $jobs[0]->getParams()['replacement'] );
		$this->assertNull( $jobs[0]->getReleaseTimestamp(),
			'The queue of this test setup takes no delayed job, so none is requested' );
	}

	/**
	 * When the queue rejects the replacement handoff, the inline relayer falls back to
	 * queueing the whole original job, so the work stays eligible for MediaWiki's retry.
	 * The rejection is logged as a handoff rejection, not as a lost purge.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testRejectedCloudflareHandoffLeavesOriginalWorkForQueueRetry(): void {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => false,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$this->mockServiceResponses( [ 'cloudflare' => 429 ] );

		$workingGroup = $this->getServiceContainer()->getJobQueueGroup();
		$pushes = 0;
		$group = $this->getMockBuilder( JobQueueGroup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'push' ] )
			->getMock();
		$group->method( 'push' )->willReturnCallback(
			static function ( $job ) use ( $workingGroup, &$pushes ) {
				if ( $pushes++ === 0 ) {
					// The replacement handoff is rejected; the fallback enqueue still works.
					throw new JobQueueError( 'test queue rejected the replacement' );
				}
				$workingGroup->push( $job );
			}
		);
		$factory = $this->createMock( JobQueueGroupFactory::class );
		$factory->method( 'makeJobQueueGroup' )->willReturn( $group );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );
		$this->getServiceContainer()->redefineService( 'JobQueueGroupFactory', static fn () => $factory );

		$entries = $this->installLogSpy();

		$relayer = new PurgeEventRelayer( [] );
		$this->assertTrue(
			$relayer->notifyMulti( 'cdn-url-purges', [ [ 'url' => 'https://example.org/Foo' ] ] ),
			'The accepted fallback enqueue still relays the purge request'
		);

		$log = implode( "\n", $entries->getArrayCopy() );
		$this->assertStringContainsString( 'Queueing the Cloudflare replacement job was rejected', $log );
		$this->assertStringContainsString( 'Job Status: error, queued for retry', $log );

		[ $job ] = $this->popQueuedMultiPurgeJobs();
		$this->assertInstanceOf( MultiPurgeJob::class, $job );
		$this->assertArrayNotHasKey( 'replacement', $job->getParams(),
			'It is the original job that is queued, not a replacement' );
		$this->assertSame( [ 'https://example.org/Foo' ], $job->getParams()['urls'],
			'The original work stays eligible for the queue\'s retry' );
	}

	private function enableVarnishInline(): void {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => false,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
		] );
	}

	/**
	 * Disallowed targets are filtered before any HTTP runs.
	 * The valid targets of the same relay still get purged and reported as relayed.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testDisallowedUrlsAreFilteredBeforeDispatch() {
		$this->enableVarnishInline();
		$sent = $this->mockServiceResponses( [] );

		$relayer = new PurgeEventRelayer( [] );
		$this->assertTrue( $relayer->notifyMulti( 'cdn-url-purges', [
			[ 'url' => 'https://example.org/ok' ],
			[ 'url' => 'https://evil.example/bad' ],
		] ) );

		$this->assertCount( 1, $sent->getArrayCopy() );
		$this->assertSame( 'http://varnish.internal/ok', $sent[0]['url'] );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing needs a retry' );
	}

	/**
	 * URLs a MultiPurgeOnPurgeUrls hook subscriber adds are validated too.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer::doNotify
	 * @return void
	 */
	public function testOnPurgeUrlsHookAdditionsAreValidated() {
		$this->enableVarnishInline();
		$sent = $this->mockServiceResponses( [] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeUrls',
			static function ( array &$urls ): void {
				$urls[] = 'https://evil.example/hook-added';
			}
		);

		$relayer = new PurgeEventRelayer( [] );
		$this->assertTrue( $relayer->notifyMulti( 'cdn-url-purges', [
			[ 'url' => 'https://example.org/ok' ],
		] ) );

		$this->assertCount( 1, $sent->getArrayCopy() );
		$this->assertSame( 'http://varnish.internal/ok', $sent[0]['url'] );
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
	 * Real factories whose queues reject pushes, so a rejected retry enqueue can be exercised.
	 * The factory itself is rebuilt through core's service wiring to match the running version.
	 *
	 * @return void
	 */
	private function installReadOnlyJobQueueGroupFactory(): void {
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [
			'default' => [ 'class' => JobQueueMemory::class, 'readOnlyReason' => 'test queue read-only' ],
		] );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );
	}

	/**
	 * Replaces the MultiPurge channel with a spy that records every entry.
	 * A test can then assert that no rejected input was logged at any level.
	 *
	 * @return ArrayObject Captured "message context" strings
	 */
	private function installLogSpy(): ArrayObject {
		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );
		return $entries;
	}
}
