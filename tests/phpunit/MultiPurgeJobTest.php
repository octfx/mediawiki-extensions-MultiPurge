<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiIntegrationTestCase;
use MultiHttpClient;

/**
 * @group MultiPurge
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

		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', fn() => $httpMock );

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

		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', fn() => $httpMock );

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
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::getReleaseTimestamp
	 * @return void
	 */
	public function testGetCfReleaseTimestamp() {
		$job = new MultiPurgeJob( [
			'jobReleaseTimestamp' => 1600000000,
			'urls' => [
				'', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
				'', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
				'', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
				'', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
				'', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
			],
			'service' => Cloudflare::class,
		] );

		$this->assertEquals( 1600000012, $job->getReleaseTimestamp() );
	}
}
