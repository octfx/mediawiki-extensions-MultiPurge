<?php

namespace MediaWiki\Extension\MultiPurge\Tests\Services;

use ArrayObject;
use Exception;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * @group MultiPurge
 */
class VarnishTest extends \MediaWikiIntegrationTestCase {

	public function setUp(): void {
		$this->overrideConfigValues( [
			'Server' => 'https://foo.example',
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

		$requests = $varnish->getPurgeRequest( 'http://foo.example' );

		$this->assertCount( 1, $requests );
		$this->assertStringStartsWith( 'http://varnish', $requests[0]['url'] );
		$this->assertSame( 'foo.example', $requests[0]['headers']['Host'] );
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

		// The Host header carries the actual target host and its non-default port.
		// Request order is target-major: both servers per URL.
		$this->assertSame( 'wiki.example:8080', $requests[0]['headers']['Host'] );
		$this->assertSame( 'wiki.example:8080', $requests[1]['headers']['Host'] );
		$this->assertSame( 'wiki.example', $requests[2]['headers']['Host'] );
		$this->assertSame( 'wiki.example', $requests[3]['headers']['Host'] );
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
	 * Varnish keys its cache by the Host header of the purge request.
	 * The header must carry the actual target host plus the port when it is not the scheme default.
	 * See https://varnish-cache.org/docs/trunk/users-guide/purging.html.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 */
	public function testHostHeaderIsTheTargetHostWithNonDefaultPort() {
		$this->overrideConfigValues( [
			'Server' => 'http://wiki.example:8080',
			'MultiPurgeVarnishServers' => [ 'http://varnish-a.example' ],
		] );
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( [
			'http://wiki.example:8080/wiki/Ported',
			'http://wiki.example/wiki/Plain',
		] );

		$this->assertCount( 2, $requests );
		$this->assertSame( 'http://varnish-a.example/wiki/Ported', $requests[0]['url'] );
		$this->assertSame( 'wiki.example:8080', $requests[0]['headers']['Host'] );
		$this->assertSame( 'http://varnish-a.example/wiki/Plain', $requests[1]['url'] );
		$this->assertSame( 'wiki.example', $requests[1]['headers']['Host'] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 */
	public function testHostHeaderUsesAllowlistedTargetHost() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeAllowedHosts' => [ 'static.example' ],
			'MultiPurgeVarnishServers' => [ 'http://varnish-a.example' ],
		] );
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( 'https://static.example:8443/images/x.png' );

		$this->assertCount( 1, $requests );
		$this->assertSame( 'http://varnish-a.example/images/x.png', $requests[0]['url'] );
		$this->assertSame( 'static.example:8443', $requests[0]['headers']['Host'] );
	}

	/**
	 * Varnish can key on the Host header as sent.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 */
	public function testHostKeepsItsSpellingAndRejectsEncodedHosts() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeAllowedHosts' => [ 'xn--wrth-5qa.example' ],
			'MultiPurgeVarnishServers' => [ 'http://varnish-a.example' ],
		] );
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( [
			'https://%77iki.example:8443/x',
			'https://wörth.example/y',
			'https://xn--wrth-5qa.example/y',
			'https://WIKI.EXAMPLE:8443/a/../z?',
		] );

		$this->assertCount( 2, $requests );
		$this->assertSame( 'xn--wrth-5qa.example', $requests[0]['headers']['Host'] );
		$this->assertSame( 'WIKI.EXAMPLE:8443', $requests[1]['headers']['Host'] );
		$this->assertSame( 'http://varnish-a.example/a/../z?', $requests[1]['url'] );
	}

	/**
	 * An uppercase scheme must not defeat the default-port check.
	 * The HTTPS and HTTP default ports are omitted from the Host header regardless of letter case.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 */
	public function testHostHeaderOmitsDefaultPortForUppercaseScheme() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeVarnishServers' => [ 'http://varnish-a.example' ],
		] );
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( [
			'HTTPS://wiki.example:443/x',
			'HTTP://wiki.example:80/x',
			'https://wiki.example:443/x',
		] );

		$this->assertCount( 3, $requests );
		$this->assertSame( 'wiki.example', $requests[0]['headers']['Host'] );
		$this->assertSame( 'wiki.example', $requests[1]['headers']['Host'] );
		$this->assertSame( 'wiki.example', $requests[2]['headers']['Host'] );
	}

	/**
	 * An explicitly empty query is a distinct Varnish cache key, so the request URL keeps the trailing "?".
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 */
	public function testEmptyQueryIsPreservedInRequestUrl() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeVarnishServers' => [ 'http://varnish-a.example' ],
		] );
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( [
			'https://wiki.example/x?',
			'https://wiki.example/y?a=b',
		] );

		$this->assertCount( 2, $requests );
		$this->assertSame( 'http://varnish-a.example/x?', $requests[0]['url'] );
		$this->assertSame( 'http://varnish-a.example/y?a=b', $requests[1]['url'] );
	}

	/**
	 * A "?" inside the fragment belongs to the fragment.
	 * The restored empty query must land directly before the "#", and the request URL must match exactly.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::buildUrl
	 */
	public function testEmptyQueryWithQuestionMarkInFragmentIsPreserved() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeVarnishServers' => [ 'http://varnish-a.example' ],
		] );
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( [ 'https://wiki.example/x?#fragment?value' ] );

		$this->assertCount( 1, $requests );
		$this->assertSame( 'http://varnish-a.example/x?#fragment?value', $requests[0]['url'] );
	}

	/**
	 * A disallowed target is skipped and logged while the valid targets of the same batch still produce requests.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 */
	public function testDisallowedTargetsAreSkippedButValidOnesPurged() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeVarnishServers' => [ 'http://varnish-a.example' ],
		] );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' )
			->with(
				$this->anything(),
				$this->callback( static fn ( array $context ): bool =>
					!array_key_exists( 'url', $context ) && ( $context['host'] ?? null ) === 'evil.example' )
			);
		$this->setLogger( 'MultiPurge', $logger );

		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( [
			'http://wiki.example/ok',
			'https://evil.example/bad',
		] );

		$this->assertCount( 1, $requests );
		$this->assertSame( 'http://varnish-a.example/ok', $requests[0]['url'] );
		$this->assertSame( 'wiki.example', $requests[0]['headers']['Host'] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 */
	public function testProtocolRelativeTargetIsResolved() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeAllowedHosts' => [ 'static.example' ],
			'MultiPurgeVarnishServers' => [ 'http://varnish-a.example' ],
		] );
		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( '//static.example/x' );

		$this->assertCount( 1, $requests );
		$this->assertSame( 'http://varnish-a.example/x', $requests[0]['url'] );
		$this->assertSame( 'static.example', $requests[0]['headers']['Host'] );
	}

	/**
	 * A malformed target is a permanent violation, so it is skipped with a warning instead of failing the batch.
	 * The parseable targets still produce requests.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 */
	public function testMalformedTargetIsSkippedWithWarning() {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' );
		$this->setLogger( 'MultiPurge', $logger );

		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( [
			'https://foo.example/wiki/Parseable',
			'http://',
		] );

		$this->assertCount( 1, $requests );
		$this->assertSame( 'http://varnish/wiki/Parseable', $requests[0]['url'] );
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
			'https://foo.example/1',
			'https://foo.example/2',
			'https://foo.example/3',
		] );

		$this->assertCount( 3, $requests );
	}

	/**
	 * The request URL carries the untrusted target path, so this channel logs the validated Host header and counts.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish::getPurgeRequest
	 * @return void
	 */
	public function testRequestUrlsAreNotLogged() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeVarnishServers' => [ 'http://varnish-a.example' ],
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

		$varnish = new Varnish( $this->getServiceContainer()->getMainConfig() );

		$requests = $varnish->getPurgeRequest( [ 'https://wiki.example/path-with-secret/x' ] );

		$this->assertCount( 1, $requests, 'The target is still purged' );
		$this->assertSame( 'http://varnish-a.example/path-with-secret/x', $requests[0]['url'] );
		$this->assertNotEmpty( $entries->getArrayCopy() );
		foreach ( $entries as $entry ) {
			$this->assertStringNotContainsString( 'path-with-secret', $entry );
		}
	}
}
