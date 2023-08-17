<?php

namespace MediaWiki\Extension\MultiPurge\Tests\Services;

use Exception;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;

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
}
