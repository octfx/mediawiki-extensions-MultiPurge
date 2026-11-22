<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiIntegrationTestCase;
use RuntimeException;
use Wikimedia\Http\MultiHttpClient;

require_once __DIR__ . '/BatchRecorder.php';

/**
 * Every runMulti() call must stay bounded while no URL, server, or Cloudflare variant is lost.
 *
 * @group MultiPurge
 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob
 */
class MultiPurgeJobBatchTest extends MediaWikiIntegrationTestCase {

	private const SERVERS = [
		'varnish-a.internal',
		'http://varnish-b.internal:6081',
		'10.11.12.13',
		'https://varnish-d.internal:6082',
	];

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'Server' => 'https://example.org',
			'MultiPurgeVarnishServers' => self::SERVERS,
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
	}

	/**
	 * @param BatchRecorder $recorder
	 * @return void
	 */
	private function installRecordingClient( BatchRecorder $recorder ): void {
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
	 * @param int $count
	 * @return string[]
	 */
	private function makeUrls( int $count ): array {
		return array_map(
			static fn ( int $i ): string => "https://example.org/page-$i",
			range( 1, $count )
		);
	}

	/**
	 * Turns a request URL back into the "server-key path" pair the coverage assertions expect.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function serverAndTarget( string $url ): string {
		$parts = parse_url( $url );
		$server = $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$server .= ':' . $parts['port'];
		}
		$target = $parts['path'] ?? '';
		if ( isset( $parts['query'] ) ) {
			$target .= '?' . $parts['query'];
		}
		return "$server $target";
	}

	/**
	 * Batching must not lose any URL/server pair: every target reaches every configured server.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::yieldPurgeRequests
	 * @return void
	 */
	public function testEveryUrlServerPairIsPurgedAcrossBatches() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Varnish::class ],
		] );

		$recorder = new BatchRecorder();
		$this->installRecordingClient( $recorder );

		$urls = $this->makeUrls( 120 );
		$job = new MultiPurgeJob( [
			'urls' => $urls,
			'service' => Varnish::class,
		] );

		$this->assertTrue( $job->run() );
		$this->assertSame( [ 100, 100, 100, 100, 80 ], $recorder->callSizes );
		$this->assertCount( 480, $recorder->requests );

		$expected = [];
		foreach ( self::SERVERS as $server ) {
			$parts = parse_url( strpos( $server, '://' ) !== false ? $server : "http://$server" );
			$key = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
			foreach ( $urls as $url ) {
				$target = parse_url( $url, PHP_URL_PATH );
				$expected[] = "$key $target";
			}
		}
		$actual = array_map(
			static fn ( array $request ): string => self::serverAndTarget( $request['url'] ),
			$recorder->requests
		);

		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual, 'Every URL must reach every server exactly once' );

		foreach ( $recorder->requests as $request ) {
			$this->assertSame( 'example.org', $request['headers']['Host'] );
		}
	}

	/**
	 * A failure in the first batch fails the job while the remaining batches still run, so no later work is lost.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testFirstBatchFailureStillRunsLaterBatchesAndFailsJob() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish-a.internal' ],
		] );

		$recorder = new BatchRecorder(
			static fn ( int $call ): array => $call === 1
				? [ 500, 'Server Error', [], 'failed', null ]
				: [ 200, 'OK', [], 'ok', null ]
		);
		$this->installRecordingClient( $recorder );

		$job = new MultiPurgeJob( [
			'urls' => $this->makeUrls( 250 ),
			'service' => Varnish::class,
		] );

		$this->assertFalse( $job->run(), 'A failing batch must fail the run' );
		$this->assertSame( [ 100, 100, 50 ], $recorder->callSizes, 'Later batches must still run' );
		$this->assertCount( 250, $recorder->requests );
	}

	/**
	 * A failure confined to the last batch must still fail the whole run after every batch ran.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testLastBatchFailureFailsJobAfterAllBatchesRan() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish-a.internal' ],
		] );

		$recorder = new BatchRecorder(
			static fn ( int $call ): array => $call === 3
				? [ 500, 'Server Error', [], 'failed', null ]
				: [ 200, 'OK', [], 'ok', null ]
		);
		$this->installRecordingClient( $recorder );

		$job = new MultiPurgeJob( [
			'urls' => $this->makeUrls( 250 ),
			'service' => Varnish::class,
		] );

		$this->assertFalse( $job->run() );
		$this->assertSame( [ 100, 100, 50 ], $recorder->callSizes );
		$this->assertCount( 250, $recorder->requests );
	}

	/**
	 * A transport-level exception in one batch must neither abort the remaining batches nor the other service.
	 * Only that one call is lost for this run.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testTransportExceptionKeepsLaterBatchesAndServicesRunning() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Varnish::class, Cloudflare::class ],
			'MultiPurgeVarnishServers' => [ 'varnish-a.internal' ],
		] );

		$recorder = new BatchRecorder(
			static function ( int $call ): array {
				if ( $call === 1 ) {
					throw new RuntimeException( 'transport exploded' );
				}
				return [ 200, 'OK', [], 'ok', null ];
			}
		);
		$this->installRecordingClient( $recorder );

		$job = new MultiPurgeJob( [
			'urls' => $this->makeUrls( 150 ),
		] );

		// The transport failure surfaces as wfLogWarning, which PHPUnit would otherwise turn into a test error.
		$phpWarnings = [];
		set_error_handler( static function ( int $errno, string $errstr ) use ( &$phpWarnings ): bool {
			if ( $errno === E_USER_WARNING ) {
				$phpWarnings[] = $errstr;
				return true;
			}
			return false;
		} );
		try {
			$jobResult = $job->run();
		} finally {
			restore_error_handler();
		}

		$this->assertFalse( $jobResult, 'The failed call must fail the run' );
		$this->assertNotEmpty( $phpWarnings, 'The transport failure must be surfaced as a warning' );
		$this->assertSame(
			[ 100, 50, 2 ],
			$recorder->callSizes,
			'The second Varnish batch and both Cloudflare chunk requests must still run'
		);
		$this->assertCount( 52, $recorder->requests );
	}

	/**
	 * A service failure in one batch must not suppress the other service, even when it fails every batch.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testFailingServiceDoesNotSuppressTheOtherService() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Varnish::class, Cloudflare::class ],
			'MultiPurgeVarnishServers' => [ 'varnish-a.internal' ],
		] );

		$recorder = new BatchRecorder(
			static function ( int $call, int|string $index, array $request ): array {
				$varnish = $request['method'] === 'PURGE';
				return $varnish ? [ 500, 'Server Error', [], 'failed', null ] : [ 200, 'OK', [], 'ok', null ];
			}
		);
		$this->installRecordingClient( $recorder );

		$job = new MultiPurgeJob( [
			'urls' => $this->makeUrls( 150 ),
		] );

		$this->assertFalse( $job->run() );
		$this->assertSame( [ 100, 50, 2 ], $recorder->callSizes, 'Cloudflare must run after Varnish failed' );
		$this->assertCount( 152, $recorder->requests );
	}

	/**
	 * A retried failed run resubmits every batch, including the ones that already succeeded.
	 * The queue retries whole jobs and the extension keeps no per-batch checkpoint.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testRetriedFailedRunResubmitsEveryBatch() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish-a.internal' ],
		] );

		$recorder = new BatchRecorder(
			static fn ( int $call ): array => $call === 2
				? [ 500, 'Server Error', [], 'failed', null ]
				: [ 200, 'OK', [], 'ok', null ]
		);
		$this->installRecordingClient( $recorder );

		$job = new MultiPurgeJob( [
			'urls' => $this->makeUrls( 150 ),
			'service' => Varnish::class,
		] );

		$this->assertFalse( $job->run() );
		$this->assertSame( [ 100, 50 ], $recorder->callSizes );
		$this->assertTrue( $job->run(), 'A clean retry must succeed' );
		$this->assertSame( [ 100, 50, 100, 50 ], $recorder->callSizes );
		$this->assertCount( 300, $recorder->requests, 'The previously successful batch is purged again' );
	}

	/**
	 * Cloudflare's operator-configured URLs-per-request chunking must survive batched submission unchanged.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::yieldPurgeRequests
	 * @return void
	 */
	public function testCloudflareKeepsOperatorUrlsPerRequestAcrossBatches() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareUrlsPerRequest' => 40,
		] );

		$recorder = new BatchRecorder();
		$this->installRecordingClient( $recorder );

		$urls = $this->makeUrls( 130 );
		$job = new MultiPurgeJob( [
			'urls' => $urls,
			'service' => Cloudflare::class,
		] );

		$this->assertTrue( $job->run() );
		$this->assertSame( [ 4 ], $recorder->callSizes );

		$seen = [];
		$postSizes = [];
		foreach ( $recorder->requests as $request ) {
			$files = json_decode( $request['postData'], true, 512, JSON_THROW_ON_ERROR )['files'];
			$postSizes[] = count( $files );
			foreach ( $files as $file ) {
				$seen[] = $file;
			}
		}

		$this->assertSame( [ 40, 40, 40, 10 ], $postSizes );
		$this->assertSame(
			array_map( static fn ( string $url ): string => "https://example.org/page-" .
				substr( $url, strrpos( $url, '-' ) + 1 ), $urls ),
			$seen,
			'Every URL must be submitted exactly once, in order'
		);
	}

	/**
	 * Cache-by-device-type variants must stay intact when chunk requests are submitted in batches.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::yieldPurgeRequests
	 * @return void
	 */
	public function testCloudflareDeviceTypeVariantsSurviveBatching() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareUrlsPerRequest' => 30,
			'MultiPurgeCloudFlareCacheByDeviceType' => true,
		] );

		$recorder = new BatchRecorder();
		$this->installRecordingClient( $recorder );

		$urls = $this->makeUrls( 130 );
		$job = new MultiPurgeJob( [
			'urls' => $urls,
			'service' => Cloudflare::class,
		] );

		$this->assertTrue( $job->run() );
		$this->assertSame( [ 13 ], $recorder->callSizes );

		$seen = [];
		foreach ( $recorder->requests as $request ) {
			$files = json_decode( $request['postData'], true, 512, JSON_THROW_ON_ERROR )['files'];
			$this->assertCount( 30, $files, 'Ten URLs times three device types per request' );
			foreach ( $files as $file ) {
				$this->assertArrayHasKey( 'url', $file );
				$this->assertArrayHasKey( 'headers', $file );
				$seen[] = $file['url'] . ' ' . $file['headers']['CF-Device-Type'];
			}
		}

		$expected = [];
		foreach ( $urls as $url ) {
			foreach ( [ 'desktop', 'mobile', 'tablet' ] as $deviceType ) {
				$expected[] = "$url $deviceType";
			}
		}
		sort( $expected );
		sort( $seen );
		$this->assertSame( $expected, $seen, 'Every URL must appear once per device type' );
	}

	/**
	 * didSendPurgeRequests and getDroppedTargets keep their meaning when the work spans several batches.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::didSendPurgeRequests
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getDroppedTargets
	 * @return void
	 */
	public function testDidSendAndDroppedTargetsSurviveBatching() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish-a.internal' ],
		] );

		$urls = $this->makeUrls( 151 );
		$dropped = array_shift( $urls );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service, array &$hookUrls ) use ( $dropped ): void {
				$hookUrls = array_values( array_diff( $hookUrls, [ $dropped ] ) );
			}
		);

		$recorder = new BatchRecorder();
		$this->installRecordingClient( $recorder );

		$job = new MultiPurgeJob( [
			'urls' => $this->makeUrls( 151 ),
			'service' => Varnish::class,
		] );

		$this->assertTrue( $job->run() );
		$this->assertTrue( $job->didSendPurgeRequests() );
		$this->assertSame( [ $dropped ], $job->getDroppedTargets() );
		$this->assertSame( [ 100, 50 ], $recorder->callSizes );
	}

	/**
	 * A reused job instance must report the state of the run just executed, not the previous one.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::didSendPurgeRequests
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getDroppedTargets
	 * @return void
	 */
	public function testReusedJobReportsSecondRunNotFirst() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish-a.internal' ],
		] );

		$dropped = 'https://example.org/page-1';
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service, array &$hookUrls ) use ( $dropped ): void {
				$hookUrls = array_values( array_diff( $hookUrls, [ $dropped ] ) );
			}
		);

		$recorder = new BatchRecorder();
		$this->installRecordingClient( $recorder );

		$job = new MultiPurgeJob( [
			'urls' => $this->makeUrls( 5 ),
			'service' => Varnish::class,
		] );

		$this->assertTrue( $job->run() );
		$this->assertTrue( $job->didSendPurgeRequests() );
		$this->assertSame( [ $dropped ], $job->getDroppedTargets() );

		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function (): bool {
				return false;
			}
		);

		$this->assertTrue( $job->run(), 'A vetoed run still reports success' );
		$this->assertFalse(
			$job->didSendPurgeRequests(),
			'The second run must not inherit the first run\'s sent flag'
		);
		$this->assertSame( [], $job->getDroppedTargets(), 'The second run must not inherit the first run\'s drops' );
		$this->assertCount( 4, $recorder->requests, 'Only the first run reached HTTP' );
	}
}
