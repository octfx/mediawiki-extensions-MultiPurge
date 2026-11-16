<?php

namespace MediaWiki\Extension\MultiPurge\Tests\Services;

use Exception;
use InvalidArgumentException;
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
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::buildUrl
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
	 * Regression test: target components must stay unmodified across server iterations.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::buildUrl
	 */
	public function testMakeUrlTwoUrlsTwoServers() {
		$this->overrideConfigValues( [
			'Server' => 'http://wiki.example',
			'MultiPurgeVarnishServers' => [
				'http://varnish-a.example',
				'https://varnish-b.example:6082',
			],
		] );
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( [
			'http://wiki.example/wiki/Page_A',
			'http://wiki.example/wiki/Page_B?action=history',
		] );

		$this->assertCount( 4, $requests );

		$actualUrls = array_map( static fn ( array $request ) => $request['url'], $requests );
		sort( $actualUrls );
		$this->assertSame( [
			'http://varnish-a.example/wiki/Page_A',
			'http://varnish-a.example/wiki/Page_B?action=history',
			'https://varnish-b.example:6082/wiki/Page_A',
			'https://varnish-b.example:6082/wiki/Page_B?action=history',
		], $actualUrls );

		foreach ( $requests as $request ) {
			$this->assertSame( 'wiki.example', $request['headers']['Host'] );
		}
	}

	/**
	 * The request port must come from the server config, not the original target URL.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::buildUrl
	 */
	public function testMakeUrlPorts() {
		$this->overrideConfigValues( [
			'Server' => 'http://wiki.example:8080',
			'MultiPurgeVarnishServers' => [
				'http://varnish-a.example',
				'https://varnish-b.example:6082',
			],
		] );
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( [
			'http://wiki.example:8080/wiki/PortTarget',
			'http://wiki.example/wiki/PlainTarget',
		] );

		$this->assertCount( 4, $requests );

		$actualUrls = array_map( static fn ( array $request ) => $request['url'], $requests );
		sort( $actualUrls );
		$this->assertSame( [
			'http://varnish-a.example/wiki/PlainTarget',
			'http://varnish-a.example/wiki/PortTarget',
			'https://varnish-b.example:6082/wiki/PlainTarget',
			'https://varnish-b.example:6082/wiki/PortTarget',
		], $actualUrls );

		foreach ( $requests as $request ) {
			$this->assertSame( 'wiki.example', $request['headers']['Host'] );
		}
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::buildUrl
	 */
	public function testMakeUrlIpServers() {
		$this->overrideConfigValues( [
			'Server' => 'http://wiki.example',
			'MultiPurgeVarnishServers' => [ '10.0.0.1', '192.168.0.2' ],
		] );
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( [ 'http://wiki.example/wiki/Page_A' ] );

		$this->assertCount( 2, $requests );

		$actualUrls = array_map( static fn ( array $request ) => $request['url'], $requests );
		sort( $actualUrls );
		$this->assertSame( [
			'http://10.0.0.1/wiki/Page_A',
			'http://192.168.0.2/wiki/Page_A',
		], $actualUrls );

		foreach ( $requests as $request ) {
			$this->assertSame( 'wiki.example', $request['headers']['Host'] );
		}
	}

	/**
	 * An unparseable target must fail the batch; a silently dropped one would be reported as purged.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 */
	public function testMakeUrlUnparseableTargetFails() {
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$this->expectException( InvalidArgumentException::class );
		$varnish->getPurgeRequest( [
			'http://wiki.example/wiki/Parseable',
			'http://',
		] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::buildUrl
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
