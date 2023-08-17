<?php

namespace MediaWiki\Extension\MultiPurge\Tests\Services;

use Exception;
use MediaWiki\Extension\MultiPurge\Services\Varnish;

/**
 * @group MultiPurge
 */
class VarnishTest extends \MediaWikiIntegrationTestCase {

	public function setUp(): void {
		$this->overrideConfigValues( [
			'MultiPurgeVarnishServers' => [ 'http://varnish' ],
		] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish
	 * @return void
	 * @throws \Exception
	 */
	public function testConstructor() {
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$this->assertInstanceOf( Varnish::class, $varnish );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::makeRequest
	 * @return void
	 * @throws Exception
	 */
	public function testMakeUrl() {
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( 'http://foo' );

		$this->assertCount( 1, $requests );
		$this->assertStringStartsWith( 'http://varnish', $requests[0]['url'] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::makeRequest
	 * @return void
	 * @throws Exception
	 */
	public function testMakeUrlMultiple() {
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( [
			'https://foo1',
			'https://foo2',
			'https://foo3',
		] );

		$this->assertCount( 3, $requests );
	}
}
