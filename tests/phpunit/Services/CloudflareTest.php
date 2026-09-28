<?php

namespace MediaWiki\Extension\MultiPurge\Tests\Services;

use Exception;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\MainConfigNames;

/**
 * @group MultiPurge
 */
class CloudflareTest extends \MediaWikiIntegrationTestCase {

	public function setUp(): void {
		$this->overrideConfigValues( [
			'MultiPurgeCloudFlareZoneId' => 'foo',
			'MultiPurgeCloudFlareApiToken' => 'foo',
		] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare
	 * @return void
	 * @throws Exception
	 */
	public function testConstructor() {
		$cf = new Cloudflare( $this->getServiceContainer()->getMainConfig() );

		$this->assertInstanceOf( Cloudflare::class, $cf );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::makeRequest
	 * @return void
	 * @throws Exception
	 */
	public function testMakeUrl() {
		$cf = new Cloudflare( $this->getServiceContainer()->getMainConfig() );

		$requests = $cf->getPurgeRequest( 'http://foo' );

		$this->assertCount( 1, $requests );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::makeRequest
	 * @return void
	 * @throws Exception
	 */
	public function testMakeUrlChunked() {
		$this->overrideConfigValues( [ 'MultiPurgeCloudFlareUrlsPerRequest' => 30 ] );
		$cf = new Cloudflare( $this->getServiceContainer()->getMainConfig() );

		$requests = $cf->getPurgeRequest( [
			'https://foo1',
			'https://foo2',
			'https://foo3',
			'https://foo4',
			'https://foo5',
			'https://foo6',
			'https://foo7',
			'https://foo8',
			'https://foo9',
			'https://foo10',
			'https://foo12',
			'https://foo13',
			'https://foo14',
			'https://foo15',
			'https://foo16',
			'https://foo17',
			'https://foo18',
			'https://foo19',
			'https://foo20',
			'https://foo21',
			'https://foo22',
			'https://foo23',
			'https://foo24',
			'https://foo25',
			'https://foo26',
			'https://foo27',
			'https://foo28',
			'https://foo29',
			'https://foo30',
			'https://foo31',
			'https://foo32',
			'https://foo33',
			'https://foo34',
			'https://foo35',
		] );

		$this->assertCount( 2, $requests );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @return void
	 * @throws Exception
	 */
	public function testDefaultUrlsPerRequest() {
		$cf = new Cloudflare( $this->getServiceContainer()->getMainConfig() );

		$requests = $cf->getPurgeRequest( array_map( static fn ( $i ) => "https://foo$i", range( 1, 150 ) ) );

		$this->assertCount( 2, $requests );
		$this->assertCount( 100, json_decode( $requests[0]['postData'], true )['files'] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @return void
	 * @throws Exception
	 */
	public function testUrlsPerRequestWithCacheByDeviceType() {
		$this->overrideConfigValues( [ 'MultiPurgeCloudFlareCacheByDeviceType' => true ] );
		$cf = new Cloudflare( $this->getServiceContainer()->getMainConfig() );

		$requests = $cf->getPurgeRequest( array_map( static fn ( $i ) => "https://foo$i", range( 1, 150 ) ) );

		// Every URL is sent twice, once per device type
		$this->assertCount( 3, $requests );
		$this->assertCount( 100, json_decode( $requests[0]['postData'], true )['files'] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @dataProvider provideUrls
	 * @param string $server
	 * @param string $url
	 * @param string $expected
	 * @return void
	 * @throws Exception
	 */
	public function testMakesUrlsAbsoluteHttps( string $server, string $url, string $expected ) {
		$this->overrideConfigValues( [ MainConfigNames::Server => $server ] );
		$cf = new Cloudflare( $this->getServiceContainer()->getMainConfig() );

		$requests = $cf->getPurgeRequest( $url );

		$this->assertSame( [ $expected ], json_decode( $requests[0]['postData'], true )['files'] );
	}

	public static function provideUrls(): array {
		return [
			'relative, as core passes file URLs' => [
				'https://wiki.example', '/images/a/ab/Foo.png', 'https://wiki.example/images/a/ab/Foo.png'
			],
			'relative, with an http server' => [
				'http://wiki.example', '/images/a/ab/Foo.png', 'https://wiki.example/images/a/ab/Foo.png'
			],
			'relative, with a protocol-relative server' => [
				'//wiki.example', '/images/a/ab/Foo.png', 'https://wiki.example/images/a/ab/Foo.png'
			],
			'protocol-relative' => [
				'https://wiki.example', '//static.example/Foo.png', 'https://static.example/Foo.png'
			],
			'http' => [
				'https://wiki.example', 'http://wiki.example/wiki/Foo', 'https://wiki.example/wiki/Foo'
			],
			'https' => [
				'https://wiki.example', 'https://wiki.example/wiki/Foo', 'https://wiki.example/wiki/Foo'
			],
		];
	}
}
