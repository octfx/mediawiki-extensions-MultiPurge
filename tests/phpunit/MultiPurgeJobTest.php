<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;

class MultiPurgeJobTest extends \MediaWikiIntegrationTestCase {

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
		$this->assertEquals( Varnish::class, MultiPurgeJob::getServiceOrder() );
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
}
