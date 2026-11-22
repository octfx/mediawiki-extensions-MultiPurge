<?php

namespace MediaWiki\Extension\MultiPurge\Tests\Services;

use ArrayObject;
use Exception;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\MainConfigNames;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * @group MultiPurge
 */
class CloudflareTest extends \MediaWikiIntegrationTestCase {

	public function setUp(): void {
		$this->overrideConfigValues( [
			'Server' => 'https://foo.example',
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

		$requests = $cf->getPurgeRequest( 'http://foo.example' );

		$this->assertCount( 1, $requests );
		$this->assertSame( 'https://api.cloudflare.com/client/v4/zones/foo/purge_cache', $requests[0]['url'] );

		$this->overrideConfigValues( [ 'MultiPurgeCloudFlareBaseUrl' => 'http://egress-gateway:8081/' ] );

		$cf = new Cloudflare( $this->getServiceContainer()->getMainConfig() );

		$requests = $cf->getPurgeRequest( 'http://foo.example' );

		$this->assertSame( 'http://egress-gateway:8081/client/v4/zones/foo/purge_cache', $requests[0]['url'] );
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

		$requests = $cf->getPurgeRequest( array_map(
			static fn ( $i ) => "https://foo.example/$i",
			range( 1, 35 )
		) );

		$this->assertCount( 2, $requests );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @return void
	 * @throws Exception
	 */
	public function testDefaultUrlsPerRequest() {
		$cf = new Cloudflare( $this->getServiceContainer()->getMainConfig() );

		$requests = $cf->getPurgeRequest( array_map(
			static fn ( $i ) => "https://foo.example/$i",
			range( 1, 150 )
		) );

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

		$requests = $cf->getPurgeRequest( array_map(
			static fn ( $i ) => "https://foo.example/$i",
			range( 1, 150 )
		) );

		// Every URL is sent three times, once per device type:
		// four requests of 33 URLs (99 files), then one of 18 URLs (54 files)
		$this->assertCount( 5, $requests );
		$this->assertCount( 99, json_decode( $requests[0]['postData'], true )['files'] );
		$this->assertCount( 54, json_decode( $requests[4]['postData'], true )['files'] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::makeRequest
	 * @return void
	 * @throws Exception
	 */
	public function testPurgesEveryDeviceType() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeCloudFlareCacheByDeviceType' => true,
		] );
		$cf = new Cloudflare( $this->getServiceContainer()->getMainConfig() );
		$url = 'https://wiki.example/wiki/Foo';

		$requests = $cf->getPurgeRequest( $url );

		$this->assertSame( [
			[ 'url' => $url, 'headers' => [ 'CF-Device-Type' => 'desktop' ] ],
			[ 'url' => $url, 'headers' => [ 'CF-Device-Type' => 'mobile' ] ],
			[ 'url' => $url, 'headers' => [ 'CF-Device-Type' => 'tablet' ] ],
		], json_decode( $requests[0]['postData'], true )['files'] );
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
	public function testMakesUrlsAbsoluteHttps(
		string $server,
		string $url,
		string $expected,
		array $allowedHosts = []
	) {
		$this->overrideConfigValues( [
			MainConfigNames::Server => $server,
			'MultiPurgeAllowedHosts' => $allowedHosts,
		] );
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
				'https://wiki.example', '//static.example/Foo.png', 'https://static.example/Foo.png',
				[ 'static.example' ]
			],
			'http' => [
				'https://wiki.example', 'http://wiki.example/wiki/Foo', 'https://wiki.example/wiki/Foo'
			],
			'uppercase http scheme is normalized and converted' => [
				'https://wiki.example', 'HTTP://wiki.example/wiki/Foo', 'https://wiki.example/wiki/Foo'
			],
			'http-to-https keeps the cache key' => [
				'https://wiki.example', 'HTTP://WIKI.EXAMPLE/a/../b?', 'https://WIKI.EXAMPLE/a/../b?'
			],
			'https cache key stays intact' => [
				'https://wiki.example', 'HTTPS://WIKI.EXAMPLE/a/../b?', 'HTTPS://WIKI.EXAMPLE/a/../b?'
			],
			'https' => [
				'https://wiki.example', 'https://wiki.example/wiki/Foo', 'https://wiki.example/wiki/Foo'
			],
		];
	}

	/**
	 * A disallowed host never reaches the Cloudflare request, while the valid targets of the batch are still purged.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @return void
	 */
	public function testDisallowedHostsAreDroppedFromPurgeRequests() {
		$this->overrideConfigValues( [ MainConfigNames::Server => 'https://wiki.example' ] );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' )
			->with(
				$this->anything(),
				$this->callback( static fn ( array $context ): bool =>
					!array_key_exists( 'url', $context ) && ( $context['host'] ?? null ) === 'evil.example' )
			);
		$this->setLogger( 'MultiPurge', $logger );

		$cf = new Cloudflare( $this->getServiceContainer()->getMainConfig() );

		$requests = $cf->getPurgeRequest( [
			'https://wiki.example/wiki/Foo',
			'https://evil.example/x',
		] );

		$this->assertCount( 1, $requests );
		$this->assertSame(
			[ 'https://wiki.example/wiki/Foo' ],
			json_decode( $requests[0]['postData'], true )['files']
		);
	}

	/**
	 * An allowlist entry that encodes an authority delimiter must not turn a direct call into a purge of another host.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @return void
	 */
	public function testEncodedDelimiterAllowlistEntryProducesNoRequests() {
		$this->overrideConfigValues( [
			MainConfigNames::Server => 'https://wiki.example',
			'MultiPurgeAllowedHosts' => [ 'wiki.example%40evil.example' ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->atLeast( 1 ) )->method( 'warning' );
		$this->setLogger( 'MultiPurge', $logger );

		$cf = new Cloudflare( $this->getServiceContainer()->getMainConfig() );

		$this->assertSame( [], $cf->getPurgeRequest( [
			'https://wiki.example%40evil.example/x',
			'https://evil.example/x',
		] ) );
	}

	/**
	 * Accepted targets are untrusted input too: a local path or query can carry credentials.
	 * The service logs counts, never the URLs themselves.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare::getPurgeRequest
	 * @return void
	 */
	public function testAcceptedUrlsAreNotLogged() {
		$this->overrideConfigValues( [ MainConfigNames::Server => 'https://wiki.example' ] );
		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$cf = new Cloudflare( $this->getServiceContainer()->getMainConfig() );

		$requests = $cf->getPurgeRequest( [ 'https://wiki.example/load.php?token=secret' ] );

		$this->assertCount( 1, $requests, 'The target is still purged' );
		$this->assertStringContainsString( 'token=secret', (string)$requests[0]['postData'] );
		$this->assertNotEmpty( $entries->getArrayCopy() );
		foreach ( $entries as $entry ) {
			$this->assertStringNotContainsString( 'token=secret', $entry );
			$this->assertStringNotContainsString( 'load.php', $entry );
		}
	}
}
