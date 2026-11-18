<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
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
		$multiMock->expects( $this->once() )->method( 'runMulti' )->willReturn( [
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
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getReleaseTimestamp
	 * @return void
	 */
	public function testGetReleaseTimestamp() {
		$job = new MultiPurgeJob( [ 'jobReleaseTimestamp' => 1600000000 ] );

		$this->assertEquals( 1600000000, $job->getReleaseTimestamp() );
	}

	/**
	 * An initially enqueued job must stay immediately eligible: queues without delayed
	 * job support reject any job whose release timestamp is not null.
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
	 * A rate-limited service response must fail the job so MediaWiki's queue retries
	 * it, instead of the job reporting success or scheduling its own delayed retry.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testRunFailsOnRateLimit() {
		$this->overrideConfigValue( 'MultiPurgeEnabledServices', [ Cloudflare::class ] );

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
	 * A batch where only some requests succeed must still fail the job; reporting
	 * success would hide the unpurged URLs.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testRunFailsWhenOnlySomeRequestsSucceed() {
		$this->overrideConfigValues( [
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
}
