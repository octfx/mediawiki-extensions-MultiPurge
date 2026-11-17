<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use ArrayObject;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Wikimedia\Http\MultiHttpClient;

/**
 * @group MultiPurge
 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob
 */
class MultiPurgeJobTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob
	 * @return void
	 */
	public function testConstructor() {
		$job = new MultiPurgeJob( [] );

		$this->assertInstanceOf( MultiPurgeJob::class, $job );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getServiceOrder
	 * @return void
	 */
	public function testServiceOrderOne() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [
				Varnish::class,
			]
		] );

		$this->assertCount( 1, MultiPurgeJob::getServiceOrder() );
		$this->assertEquals( Varnish::class, MultiPurgeJob::getServiceOrder()[0] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getServiceOrder
	 * @return void
	 */
	public function testServiceOrderTwo() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [
				Varnish::class,
				Cloudflare::class,
			]
		] );

		$this->assertCount( 2, MultiPurgeJob::getServiceOrder() );
		$this->assertEquals( [ Varnish::class, Cloudflare::class ], MultiPurgeJob::getServiceOrder() );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getServiceOrder
	 * @return void
	 */
	public function testServiceOrderTwoReverse() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [
				Cloudflare::class,
				Varnish::class,
			]
		] );

		$this->assertCount( 2, MultiPurgeJob::getServiceOrder() );
		$this->assertEquals( [ Cloudflare::class, Varnish::class ], MultiPurgeJob::getServiceOrder() );
	}

	/**
	 * An unknown enabled name still flows to the jobs, which warn when they skip it.
	 * The configuration error must already be visible from the setting it came from.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getServiceOrder
	 * @return void
	 */
	public function testServiceOrderWarnsUnknownEnabledServiceName() {
		$this->overrideConfigValue( 'MultiPurgeEnabledServices', [ 'Fastly' ] );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' )->with(
			$this->stringContains( 'Unknown purge service' ),
			$this->callback( static fn ( array $context ): bool =>
				( $context['service'] ?? null ) === 'Fastly'
				&& ( $context['setting'] ?? null ) === 'MultiPurgeEnabledServices' )
		);
		$this->setLogger( 'MultiPurge', $logger );

		$this->assertSame( [ 'Fastly' ], MultiPurgeJob::getServiceOrder(),
			'The returned order stays the configured intersection, invalid name included' );
	}

	/**
	 * An invalid MultiPurgeServiceOrder entry is dropped by the intersection before any job can warn.
	 * getServiceOrder must warn about it itself, and an empty intersection must not stay silent.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getServiceOrder
	 * @return void
	 */
	public function testServiceOrderWarnsUnknownOrderNameBeforeIntersection() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Varnish::class ],
			'MultiPurgeServiceOrder' => [ 'Varis' ],
		] );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' )->with(
			$this->stringContains( 'Unknown purge service' ),
			$this->callback( static fn ( array $context ): bool =>
				( $context['service'] ?? null ) === 'Varis'
				&& ( $context['setting'] ?? null ) === 'MultiPurgeServiceOrder' )
		);
		$this->setLogger( 'MultiPurge', $logger );

		$this->assertSame( [], MultiPurgeJob::getServiceOrder(), 'The typo selects no service' );
	}

	/**
	 * Unknown names in both settings are each warned about with the setting they came from.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getServiceOrder
	 * @return void
	 */
	public function testServiceOrderEmptyIntersectionWithBothInvalidWarnsBothSettings() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ 'Foo' ],
			'MultiPurgeServiceOrder' => [ 'Bar' ],
		] );

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$this->assertSame( [], MultiPurgeJob::getServiceOrder() );

		$log = implode( "\n", $entries->getArrayCopy() );
		$this->assertStringContainsString( '"service":"Foo"', $log );
		$this->assertStringContainsString( 'MultiPurgeEnabledServices', $log );
		$this->assertStringContainsString( '"service":"Bar"', $log );
		$this->assertStringContainsString( 'MultiPurgeServiceOrder', $log );
	}

	/**
	 * A mixed configuration keeps the configured ordering and normalizes known spellings without injecting anything.
	 * The invalid name neither filters the result nor disappears without a warning.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getServiceOrder
	 * @return void
	 */
	public function testServiceOrderKeepsConfiguredOrderWithMixedValidity() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ 'cloudflare', 'Fastly', 'Varnish' ],
			'MultiPurgeServiceOrder' => [ 'varnish', 'Cloudflare' ],
		] );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' )->with(
			$this->anything(),
			$this->callback( static fn ( array $context ): bool =>
				( $context['service'] ?? null ) === 'Fastly'
				&& ( $context['setting'] ?? null ) === 'MultiPurgeEnabledServices' )
		);
		$this->setLogger( 'MultiPurge', $logger );

		$this->assertSame(
			[ Varnish::class, Cloudflare::class ],
			MultiPurgeJob::getServiceOrder(),
			'The configured order is respected and no invalid name is injected or filtered'
		);
	}

	/**
	 * An intentionally disabled extension (no enabled services) stays silent, because nothing was lost.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getServiceOrder
	 * @return void
	 */
	public function testServiceOrderDisabledConfigurationStaysSilent() {
		$this->overrideConfigValue( 'MultiPurgeEnabledServices', [] );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->never() )->method( 'warning' );
		$this->setLogger( 'MultiPurge', $logger );

		$this->assertSame( [], MultiPurgeJob::getServiceOrder() );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getPurgeService
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getServiceOrder
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::normalizeServiceName
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::buildUrl
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @return void
	 * @throws \Exception
	 */
	public function testRun() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [
				Cloudflare::class,
				Varnish::class,
			],
			'MultiPurgeVarnishServers' => [
				'127.0.0.1',
			],
		] );

		$multiMock = $this->getMockBuilder( MultiHttpClient::class )->disableOriginalConstructor()->getMock();
		// Batches are submitted per service, so each enabled service gets its own runMulti() call.
		$multiMock->expects( $this->exactly( 2 ) )->method( 'runMulti' )->willReturn( [
			[ 'response' => [ 200, null, null, null, null ] ],
		] );

		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )->disableOriginalConstructor()->getMock();
		$httpMock->expects( $this->once() )->method( 'createMultiClient' )->willReturn( $multiMock );

		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		$job = new MultiPurgeJob( [
			'urls' => [
				'http://localhost',
			],
		] );

		$this->assertTrue( $job->run() );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getPurgeService
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getServiceOrder
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::normalizeServiceName
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @return void
	 * @throws \Exception
	 */
	public function testRunFalse() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [
				Cloudflare::class,
				Varnish::class,
			]
		] );

		$multiMock = $this->getMockBuilder( MultiHttpClient::class )->disableOriginalConstructor()->getMock();
		// No Varnish servers are configured, so only Cloudflare submits a batch.
		$multiMock->expects( $this->once() )->method( 'runMulti' )->willReturn( [
			[ 'response' => [ 500, null, null, null, null ] ],
		] );

		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )->disableOriginalConstructor()->getMock();
		$httpMock->expects( $this->once() )->method( 'createMultiClient' )->willReturn( $multiMock );

		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		$job = new MultiPurgeJob( [
			'urls' => [
				'http://localhost',
			],
		] );

		$this->assertFalse( $job->run() );
	}

	/**
	 * A mixed batch keeps its valid targets; only they reach HTTP.
	 * A permanent policy rejection must not fail the job and burn queue retries on it.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy
	 * @return void
	 */
	public function testRunFiltersDisallowedUrlsBeforeHttp() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );

		$sent = new ArrayObject();
		$multiMock = $this->getMockBuilder( MultiHttpClient::class )->disableOriginalConstructor()->getMock();
		$multiMock->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) use ( $sent ) {
				foreach ( $requests as $i => $request ) {
					$sent->append( $request );
					$request['response'] = [ 200, null, [], null, null ];
					$requests[$i] = $request;
				}
				return $requests;
			}
		);
		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )->disableOriginalConstructor()->getMock();
		$httpMock->method( 'createMultiClient' )->willReturn( $multiMock );
		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' );
		$this->setLogger( 'MultiPurge', $logger );

		$job = new MultiPurgeJob( [
			'urls' => [ 'http://localhost/ok', 'https://evil.example/bad' ],
			'service' => Cloudflare::class,
		] );

		$this->assertTrue( $job->run(), 'Policy rejections must not fail the job' );
		$this->assertCount( 1, $sent->getArrayCopy() );
		$this->assertSame(
			[ 'https://localhost/ok' ],
			json_decode( $sent[0]['postData'], true )['files']
		);
	}

	/**
	 * A job whose every target is rejected stays a success for the queue.
	 * Retrying a permanent policy violation can only re-purge nothing and waste attempts.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testRunSkipsHttpWhenAllUrlsAreRejected() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );

		$multiMock = $this->getMockBuilder( MultiHttpClient::class )->disableOriginalConstructor()->getMock();
		$multiMock->expects( $this->never() )->method( 'runMulti' );
		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )->disableOriginalConstructor()->getMock();
		$httpMock->method( 'createMultiClient' )->willReturn( $multiMock );
		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->exactly( 2 ) )->method( 'warning' );
		$this->setLogger( 'MultiPurge', $logger );

		$job = new MultiPurgeJob( [
			'urls' => [ 'https://evil.example/1', 'https://evil.example/2' ],
			'service' => Cloudflare::class,
		] );

		$this->assertTrue( $job->run() );
	}

	/**
	 * URLs a MultiPurgeOnPurgeService hook subscriber adds are validated too.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testRunValidatesHookModifiedUrls() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
		] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( $service, array &$urls ): void {
				$urls[] = 'https://evil.example/hook-added';
			}
		);

		$sent = new ArrayObject();
		$multiMock = $this->getMockBuilder( MultiHttpClient::class )->disableOriginalConstructor()->getMock();
		$multiMock->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) use ( $sent ) {
				foreach ( $requests as $i => $request ) {
					$sent->append( $request );
					$request['response'] = [ 200, null, [], null, null ];
					$requests[$i] = $request;
				}
				return $requests;
			}
		);
		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )->disableOriginalConstructor()->getMock();
		$httpMock->method( 'createMultiClient' )->willReturn( $multiMock );
		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		$job = new MultiPurgeJob( [
			'urls' => [ 'http://localhost/ok' ],
			'service' => Varnish::class,
		] );

		$this->assertTrue( $job->run() );
		$this->assertCount( 1, $sent->getArrayCopy() );
		$this->assertSame( 'http://varnish.internal/ok', $sent[0]['url'] );
	}

	/**
	 * The job must not override operator HTTP settings, so it hands createMultiClient() only concurrency options.
	 * Every effective timeout stays whatever MediaWiki's configuration produces.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testRunPassesNoTimeoutOptionsToTheHttpClient() {
		$this->overrideConfigValues( [
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );

		$capturedOptions = [];
		$multiMock = $this->getMockBuilder( MultiHttpClient::class )->disableOriginalConstructor()->getMock();
		$multiMock->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) {
				foreach ( $requests as $i => $request ) {
					$request['response'] = [ 200, null, [], null, null ];
					$requests[$i] = $request;
				}
				return $requests;
			}
		);

		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )->disableOriginalConstructor()->getMock();
		$httpMock->method( 'createMultiClient' )->willReturnCallback(
			static function ( $options = [] ) use ( $multiMock, &$capturedOptions ) {
				$capturedOptions[] = $options;
				return $multiMock;
			}
		);
		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		$job = new MultiPurgeJob( [ 'urls' => [ 'https://example.org/1' ], 'service' => Cloudflare::class ] );
		$this->assertTrue( $job->run() );

		$this->assertCount( 1, $capturedOptions );
		$this->assertSame(
			[],
			array_intersect(
				array_keys( $capturedOptions[0] ),
				[ 'timeout', 'connectTimeout', 'reqTimeout', 'connTimeout',
					'maxTimeout', 'maxConnectTimeout', 'maxReqTimeout', 'maxConnTimeout' ]
			),
			'The job must not pass any timeout option to the HTTP client'
		);
	}

	/**
	 * A client built by the real factory from the job's options must run with MediaWiki's effective timeouts.
	 * Those are $wgHTTPTimeout/$wgHTTPConnectTimeout capped by $wgHTTPMaxTimeout/$wgHTTPMaxConnectTimeout.
	 * The inspected MW 1.43 and 1.45 source defaults them to 25 and 5 seconds with no maximum.
	 * The test container's factory is the NullHttpRequestFactory stand-in, so this test builds the real one itself.
	 * It is constructed the way core's ServiceWiring constructs it.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @dataProvider provideHttpTimeoutConfig
	 * @param array<string, int|float> $configOverrides
	 * @param int|float $expectedReqTimeout
	 * @param int|float $expectedConnTimeout
	 * @param int|float $expectedMaxReqTimeout
	 * @param int|float $expectedMaxConnTimeout
	 * @return void
	 */
	public function testEffectiveHttpTimeoutsComeFromMediaWikiConfig(
		array $configOverrides,
		$expectedReqTimeout,
		$expectedConnTimeout,
		$expectedMaxReqTimeout,
		$expectedMaxConnTimeout
	) {
		$this->overrideConfigValues( $configOverrides );

		$factory = new HttpRequestFactory(
			new ServiceOptions(
				HttpRequestFactory::CONSTRUCTOR_OPTIONS,
				$this->getServiceContainer()->getMainConfig()
			),
			new NullLogger()
		);
		$client = $factory->createMultiClient( [ 'maxConnsPerHost' => 8, 'usePipelining' => true ] );

		$this->assertEquals( $expectedReqTimeout, self::clientOption( $client, 'reqTimeout' ) );
		$this->assertEquals( $expectedConnTimeout, self::clientOption( $client, 'connTimeout' ) );
		$this->assertEquals( $expectedMaxReqTimeout, self::clientOption( $client, 'maxReqTimeout' ) );
		$this->assertEquals( $expectedMaxConnTimeout, self::clientOption( $client, 'maxConnTimeout' ) );
	}

	public static function provideHttpTimeoutConfig(): array {
		return [
			'core defaults' => [ [], 25, 5.0, INF, INF ],
			'operator settings apply' => [
				[ MainConfigNames::HTTPTimeout => 17, MainConfigNames::HTTPConnectTimeout => 3.5 ],
				17, 3.5, INF, INF,
			],
			'configured maximums cap the settings' => [
				[
					MainConfigNames::HTTPTimeout => 17,
					MainConfigNames::HTTPMaxTimeout => 9,
					MainConfigNames::HTTPConnectTimeout => 3.5,
					MainConfigNames::HTTPMaxConnectTimeout => 2,
				],
				9, 2, 9, 2,
			],
		];
	}

	/**
	 * @param MultiHttpClient $client
	 * @param string $option
	 * @return mixed
	 */
	private static function clientOption( MultiHttpClient $client, string $option ) {
		$property = new ReflectionProperty( MultiHttpClient::class, $option );
		return $property->getValue( $client );
	}

	/**
	 * An unknown service name is a permanent misconfiguration.
	 * It must not abort the run and block the valid service behind it, and it must be logged by name.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getPurgeService
	 * @return void
	 */
	public function testRunSkipsUnknownServiceAndStillPurgesValidServices() {
		$this->overrideConfigValues( [
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ 'Fastly', Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );

		$sent = new ArrayObject();
		$multiMock = $this->getMockBuilder( MultiHttpClient::class )->disableOriginalConstructor()->getMock();
		$multiMock->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) use ( $sent ) {
				foreach ( $requests as $i => $request ) {
					$sent->append( $request );
					$request['response'] = [ 200, null, [], null, null ];
					$requests[$i] = $request;
				}
				return $requests;
			}
		);
		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )->disableOriginalConstructor()->getMock();
		$httpMock->method( 'createMultiClient' )->willReturn( $multiMock );
		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$level|$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$job = new MultiPurgeJob( [ 'urls' => [ 'https://example.org/1' ] ] );

		$this->assertTrue( $job->run(), 'The valid service must still run and report success' );
		$this->assertTrue( $job->didSendPurgeRequests() );
		$this->assertSame( [ 'Fastly' ], $job->getInvalidServices(), 'The skipped name is exposed to callers' );
		$this->assertCount( 1, $sent->getArrayCopy(), 'Only the valid service submitted requests' );
		$this->assertStringContainsString(
			'warning|Could not instantiate purge service',
			implode( "\n", $entries->getArrayCopy() ),
			'The misconfiguration must be logged at warning level'
		);
		$this->assertStringContainsString(
			'"service":"Fastly"',
			implode( "\n", $entries->getArrayCopy() ),
			'The unknown service name must be logged'
		);
	}

	/**
	 * With every configured service unknown nothing can be purged.
	 * The run must stay a queue success, because retrying a permanent misconfiguration can only burn queue attempts.
	 * The configuration error stays visible in the log, and callers can see that no request was sent.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getPurgeService
	 * @return void
	 */
	public function testRunWithOnlyUnknownServicesSendsNothingAndStaysQueueSafe() {
		$this->overrideConfigValues( [
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ 'Fastly', 'Lokerz' ],
		] );

		$multiMock = $this->getMockBuilder( MultiHttpClient::class )->disableOriginalConstructor()->getMock();
		$multiMock->expects( $this->never() )->method( 'runMulti' );
		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )->disableOriginalConstructor()->getMock();
		$httpMock->method( 'createMultiClient' )->willReturn( $multiMock );
		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$level|$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$job = new MultiPurgeJob( [ 'urls' => [ 'https://example.org/1' ] ] );

		$this->assertTrue( $job->run(), 'A permanent misconfiguration must not be retried by the queue' );
		$this->assertFalse( $job->didSendPurgeRequests() );
		$this->assertSame(
			[ 'Fastly', 'Lokerz' ],
			$job->getInvalidServices(),
			'Every skipped name is exposed to callers'
		);

		$log = implode( "\n", $entries->getArrayCopy() );
		$this->assertStringContainsString(
			'warning|Could not instantiate purge service',
			$log,
			'The misconfiguration must be logged at warning level'
		);
		$this->assertStringContainsString( '"service":"Fastly"', $log, 'Every unknown service name must be logged' );
		$this->assertStringContainsString( '"service":"Lokerz"', $log, 'Every unknown service name must be logged' );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getReleaseTimestamp
	 * @return void
	 */
	public function testGetReleaseTimestamp() {
		$job = new MultiPurgeJob( [ 'jobReleaseTimestamp' => 1600000000 ] );

		$this->assertEquals( 1600000000, $job->getReleaseTimestamp() );
	}

	/**
	 * An initially enqueued job must stay immediately eligible.
	 * Queues without delayed-job support reject any job whose release timestamp is not null.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getReleaseTimestamp
	 * @dataProvider provideInitialUrlCounts
	 * @param string $service
	 * @param int $urlCount
	 * @return void
	 */
	public function testInitialJobReleaseTimestampIsNull( string $service, int $urlCount ) {
		$job = new MultiPurgeJob( [
			'urls' => array_map(
				static fn ( int $i ): string => "https://example.org/$i",
				range( 1, $urlCount )
			),
			'service' => $service,
		] );

		$this->assertNull( $job->getReleaseTimestamp() );
	}

	public static function provideInitialUrlCounts(): array {
		return [
			'Cloudflare with eight URLs' => [ Cloudflare::class, 8 ],
			'Cloudflare with nine URLs' => [ Cloudflare::class, 9 ],
			'Varnish with nine URLs' => [ Varnish::class, 9 ],
		];
	}

	/**
	 * A rate-limited service response must fail the job so MediaWiki's queue retries it.
	 * The job must not report success or schedule its own delayed retry.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testRunFailsOnRateLimit() {
		$this->overrideConfigValues( [
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
		] );

		$multiMock = $this->getMockBuilder( MultiHttpClient::class )->disableOriginalConstructor()->getMock();
		$multiMock->expects( $this->once() )->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) {
				foreach ( $requests as $i => $request ) {
					$request['response'] = [ 429, null, [ 'retry-after' => '42' ], null, null ];
					$requests[$i] = $request;
				}
				return $requests;
			}
		);

		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )->disableOriginalConstructor()->getMock();
		$httpMock->expects( $this->once() )->method( 'createMultiClient' )->willReturn( $multiMock );

		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		$job = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/1' ],
			'service' => Cloudflare::class,
		] );

		$this->assertFalse( $job->run() );
		DeferredUpdates::doUpdates();

		$queue = MediaWikiServices::getInstance()->getJobQueueGroupFactory()
			->makeJobQueueGroup()->get( 'MultiPurgePages' );
		$queue->flushCaches();
		$this->assertSame( 0, $queue->getSize(), 'No custom retry job was enqueued' );
	}

	/**
	 * A batch where only some requests succeed must still fail the job.
	 * Reporting success would hide the unpurged URLs.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testRunFailsWhenOnlySomeRequestsSucceed() {
		$this->overrideConfigValues( [
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal', 'varnish2.internal' ],
		] );

		$multiMock = $this->getMockBuilder( MultiHttpClient::class )->disableOriginalConstructor()->getMock();
		$multiMock->expects( $this->once() )->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) {
				foreach ( $requests as $i => $request ) {
					$failed = str_contains( $request['url'], 'varnish2' );
					$request['response'] = [ $failed ? 500 : 200, null, [], null, null ];
					$requests[$i] = $request;
				}
				return $requests;
			}
		);

		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )->disableOriginalConstructor()->getMock();
		$httpMock->method( 'createMultiClient' )->willReturn( $multiMock );
		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		$job = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/page-ok' ],
			'service' => Varnish::class,
		] );

		$this->assertFalse( $job->run() );
	}

	/**
	 * Even the failure result log must not echo the request URL, which carries the untrusted target path.
	 * Counts and statuses only.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testRunLogsNoTargetUrls() {
		$this->overrideConfigValues( [
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
		] );

		$multiMock = $this->getMockBuilder( MultiHttpClient::class )->disableOriginalConstructor()->getMock();
		$multiMock->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) {
				foreach ( $requests as $i => $request ) {
					$request['response'] = [ 500, null, [], 'failed', null ];
					$requests[$i] = $request;
				}
				return $requests;
			}
		);
		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )->disableOriginalConstructor()->getMock();
		$httpMock->method( 'createMultiClient' )->willReturn( $multiMock );
		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$job = new MultiPurgeJob( [
			'urls' => [ 'https://example.org/path-with-secret/x' ],
			'service' => Varnish::class,
		] );

		$this->assertFalse( $job->run(), 'The failing response is still reported' );
		$this->assertNotEmpty( $entries->getArrayCopy() );
		foreach ( $entries as $entry ) {
			$this->assertStringNotContainsString( 'path-with-secret', $entry );
			$this->assertStringNotContainsString( 'varnish.internal/path', $entry );
		}
	}
}
