<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use MediaWiki\Extension\MultiPurge\PurgeUrlPolicy;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;

/**
 * @group MultiPurge
 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy
 */
class PurgeUrlPolicyTest extends MediaWikiIntegrationTestCase {

	private const SERVER = 'https://wiki.example';

	private function policy( array $allowedHosts = [] ): PurgeUrlPolicy {
		return new PurgeUrlPolicy( self::SERVER, $allowedHosts );
	}

	/**
	 * @dataProvider provideAllowedUrls
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::resolve
	 * @param string $url
	 * @param string $expected
	 * @param string[] $allowedHosts
	 * @return void
	 */
	public function testResolvesAllowedUrls( string $url, string $expected, array $allowedHosts = [] ) {
		$reason = null;
		$resolved = $this->policy( $allowedHosts )->resolve( $url, $reason );
		$this->assertSame( $expected, $resolved, $reason ?? 'rejected unexpectedly' );
		$this->assertNull( $reason );
	}

	public static function provideAllowedUrls(): array {
		return [
			'root relative' => [ '/wiki/Foo', 'https://wiki.example/wiki/Foo' ],
			'root relative keeps the query' => [
				'/load.php?lang=de&modules=site.styles&only=styles&skin=citizen',
				'https://wiki.example/load.php?lang=de&modules=site.styles&only=styles&skin=citizen',
			],
			'absolute https' => [ 'https://wiki.example/wiki/Foo', 'https://wiki.example/wiki/Foo' ],
			'absolute http' => [ 'http://wiki.example/wiki/Foo', 'http://wiki.example/wiki/Foo' ],
			'uppercase host and scheme are preserved' => [
				'HTTPS://WIKI.EXAMPLE/Foo', 'HTTPS://WIKI.EXAMPLE/Foo',
			],
			'absolute path and query are untouched' => [
				'HTTP://wiki.example/a/../b?x=%2B', 'HTTP://wiki.example/a/../b?x=%2B',
			],
			'root-relative path and query are untouched' => [
				'/a/../b?x=%2B', 'https://wiki.example/a/../b?x=%2B',
			],
			'default port preserved' => [ 'http://wiki.example:80/x', 'http://wiki.example:80/x' ],
			'non-default port preserved' => [ 'https://wiki.example:8443/x', 'https://wiki.example:8443/x' ],
			'protocol relative resolves to https' => [ '//wiki.example/x', 'https://wiki.example/x' ],
			'protocol relative allowlisted host' => [
				'//static.example/x', 'https://static.example/x', [ 'static.example' ],
			],
			'absolute allowlisted upload host' => [
				'https://static.example/images/a/ab/Foo.png',
				'https://static.example/images/a/ab/Foo.png',
				[ 'static.example' ],
			],
			'allowlist entry matched case-insensitively' => [
				'https://STATIC.example/x', 'https://STATIC.example/x', [ 'Static.EXAMPLE' ],
			],
			'punycode host is allowed as written' => [
				'https://xn--wrth-5qa.example/x', 'https://xn--wrth-5qa.example/x', [ 'xn--wrth-5qa.example' ],
			],
		];
	}

	/**
	 * @dataProvider provideRejectedUrls
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::resolve
	 * @param string $url
	 * @param string $expectedReason Fragment expected inside the rejection reason
	 * @return void
	 */
	public function testRejectsDisallowedUrls( string $url, string $expectedReason ) {
		$reason = null;
		$this->assertNull( $this->policy( [ 'static.example' ] )->resolve( $url, $reason ) );
		$this->assertNotNull( $reason );
		$this->assertStringContainsString( $expectedReason, $reason );
	}

	public static function provideRejectedUrls(): array {
		return [
			'empty' => [ '', 'empty' ],
			'leading whitespace' => [ ' https://wiki.example/x', 'whitespace' ],
			'trailing whitespace' => [ 'https://wiki.example/x ', 'whitespace' ],
			'inner whitespace' => [ 'https://wiki.example/a b', 'whitespace' ],
			'null byte' => [ "https://wiki.example/\0x", 'control' ],
			'tab in path' => [ "https://wiki.example/\tx", 'control' ],
			'javascript scheme' => [ 'javascript:alert(1)', 'scheme' ],
			'ftp scheme' => [ 'ftp://wiki.example/x', 'scheme' ],
			'file scheme' => [ 'file:///etc/passwd', 'scheme' ],
			'mailto scheme' => [ 'mailto:user@evil.example', 'scheme' ],
			'uppercase non-http scheme' => [ 'JAVASCRIPT:alert(1)', 'scheme' ],
			'userinfo with password' => [ 'https://user:pass@wiki.example/x', 'credential' ],
			'userinfo without password' => [ 'https://user@wiki.example/x', 'credential' ],
			'userinfo hiding a hostile host' => [ 'https://wiki.example@evil.example/x', 'credential' ],
			'encoded userinfo hiding a hostile host' => [
				'https://wiki.example%2F@evil.example/x', 'credential',
			],
			'backslash in authority' => [ 'https://wiki.example\@evil.example/x', 'backslash' ],
			'backslash path traversal' => [ '/\evil.example/x', 'backslash' ],
			'mixed slash authority' => [ 'https:/\/\evil.example/x', 'backslash' ],
			'disallowed host' => [ 'https://evil.example/x', 'not an allowed' ],
			'percent-encoded host' => [ 'https://%77iki.example/x', 'not a valid' ],
			'Unicode hostname' => [ 'https://wörth.example/x', 'not a valid' ],
			'trailing dot is not the allowlisted hostname' => [ 'https://wiki.example./x', 'not an allowed' ],
			'uppercase disallowed host' => [ 'https://EVIL.example/x', 'not an allowed' ],
			'protocol relative disallowed host' => [ '//evil.example/x', 'not an allowed' ],
			'subdomain of the allowed host' => [ 'https://sub.wiki.example/x', 'not an allowed' ],
			'allowed host as suffix of the target' => [ 'https://wiki.example.evil.example/x', 'not an allowed' ],
			'allowlisted host as suffix of the target' => [
				'https://static.example.evil.example/x', 'not an allowed',
			],
			'allowlisted host as prefix of the target' => [
				'https://notstatic.example/x', 'not an allowed',
			],
			'empty host' => [ 'http://', 'parse' ],
			'no authority' => [ 'https:wiki.example/x', 'host' ],
			'out of range port' => [ 'https://wiki.example:70000/x', 'port' ],
			'port with trailing characters' => [ 'https://wiki.example:80oops/x', 'port' ],
			'empty port' => [ 'https://wiki.example:/x', 'port' ],
			'invalid bracketed host' => [ 'https://[abc]/x', 'not a valid' ],
			'port zero' => [ 'https://wiki.example:0/x', 'port' ],
			'current-dir relative' => [ 'images/a/ab/Foo.png', 'root-relative' ],
			'bare host without scheme' => [ 'wiki.example/x', 'root-relative' ],
		];
	}

	/**
	 * Percent-encoded authority delimiters must never survive into a host.
	 * An allowlist entry like wiki.example%40evil.example used to pass the bare-host check.
	 * A matching target then assembled as https://wiki.example@evil.example/x, whose real host is evil.example.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::resolve
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::__construct
	 * @return void
	 */
	public function testEncodedAuthorityDelimitersCannotEnterTheAllowlist() {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->atLeast( 1 ) )->method( 'warning' );
		$this->setLogger( 'MultiPurge', $logger );

		$policy = new PurgeUrlPolicy( self::SERVER, [ 'wiki.example%40evil.example' ] );

		$this->assertNull( $policy->resolve( 'https://wiki.example%40evil.example/x' ) );
		$this->assertNull( $policy->resolve( 'https://evil.example/x' ) );
		$this->assertSame( 'https://wiki.example/x', $policy->resolve( '/x' ) );
	}

	/**
	 * A $wgServer hostname that decodes to a non-hostname must disable purging, not allowlist a delimiter.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::__construct
	 * @return void
	 */
	public function testEncodedDelimiterServerHostRejectsEveryTarget() {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->atLeast( 1 ) )->method( 'warning' );
		$this->setLogger( 'MultiPurge', $logger );

		$policy = new PurgeUrlPolicy( 'https://wiki.example%40evil.example' );

		$this->assertNull( $policy->resolve( 'https://wiki.example%40evil.example/x' ) );
		$this->assertNull( $policy->resolve( 'https://evil.example/x' ) );
		$this->assertNull( $policy->resolve( '/x' ) );
	}

	/**
	 * Targets whose host decodes to a non-hostname are rejected regardless of configuration.
	 * No encoding of @, /, ?, #, or \ can smuggle an authority.
	 *
	 * @dataProvider provideEncodedDelimiterTargets
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::resolve
	 * @param string $url
	 * @return void
	 */
	public function testRejectsTargetsWhoseDecodedHostIsNotAHostname( string $url ) {
		$reason = null;
		$this->assertNull( $this->policy( [ 'static.example' ] )->resolve( $url, $reason ) );
		$this->assertNotNull( $reason );
		$this->assertStringContainsString( 'not a valid', $reason );
	}

	public static function provideEncodedDelimiterTargets(): array {
		return [
			'encoded at sign' => [ 'https://wiki.example%40evil.example/x' ],
			'encoded slash' => [ 'https://wiki.example%2Fevil.example/x' ],
			'encoded question mark' => [ 'https://wiki.example%3Fevil.example/x' ],
			'encoded fragment sign' => [ 'https://wiki.example%23evil.example/x' ],
			'encoded backslash' => [ 'https://wiki.example%5Cevil.example/x' ],
			'encoded colon' => [ 'https://wiki.example%3A8080/x' ],
			'protocol-relative encoded at sign' => [ '//wiki.example%40evil.example/x' ],
		];
	}

	/**
	 * Whatever the policy accepts must re-parse to exactly the validated host and carry no credentials.
	 * An assembly quirk can never reinterpret the authority.
	 *
	 * @dataProvider provideValidatedHostTargets
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::resolve
	 * @param string $url
	 * @param string $expectedHost
	 * @param string[] $allowedHosts
	 * @return void
	 */
	public function testResolvedUrlCarriesTheValidatedHostWithoutCredentials(
		string $url,
		string $expectedHost,
		array $allowedHosts = []
	) {
		$resolved = $this->policy( $allowedHosts )->resolve( $url );

		$this->assertNotNull( $resolved );
		$bits = parse_url( $resolved );
		$this->assertIsArray( $bits );
		$this->assertSame( $expectedHost, $bits['host'] );
		$this->assertArrayNotHasKey( 'user', $bits );
		$this->assertArrayNotHasKey( 'pass', $bits );
	}

	public static function provideValidatedHostTargets(): array {
		return [
			'plain' => [ 'https://wiki.example/x', 'wiki.example' ],
			'uppercase' => [ 'HTTPS://WIKI.EXAMPLE/x', 'WIKI.EXAMPLE' ],
			'punycode' => [ 'https://xn--wrth-5qa.example/x', 'xn--wrth-5qa.example', [ 'xn--wrth-5qa.example' ] ],
			'ipv6 with port' => [ 'http://[::1]:8080/x', '[::1]', [ '[::1]' ] ],
		];
	}

	/**
	 * An explicitly empty query is a distinct cache key from no query at all.
	 * A "?" inside the fragment must not be mistaken for the restored query.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::resolve
	 * @return void
	 */
	public function testPreservesExplicitlyEmptyQuery() {
		$policy = $this->policy();

		$this->assertSame( 'https://wiki.example/x?', $policy->resolve( 'https://wiki.example/x?' ) );
		$this->assertSame( 'https://wiki.example/x?', $policy->resolve( '/x?' ) );
		$this->assertSame( 'https://wiki.example/y?a', $policy->resolve( '/y?a' ) );
		$this->assertSame(
			'https://wiki.example/x?#fragment?value',
			$policy->resolve( 'https://wiki.example/x?#fragment?value' )
		);
		$this->assertSame(
			'https://wiki.example/x?#fragment?value',
			$policy->resolve( '/x?#fragment?value' )
		);
		$this->assertSame( 'https://wiki.example/y?a#f', $policy->resolve( '/y?a#f' ) );
	}

	/**
	 * Ports are validated and preserved but do not widen or narrow the hostname decision.
	 * The hostname is the operator's trust boundary.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::resolve
	 * @return void
	 */
	public function testResolvesIpv6ServerHostWithPort() {
		$policy = new PurgeUrlPolicy( 'http://[::1]:8080' );

		$this->assertSame( 'http://[::1]:8080/x', $policy->resolve( 'http://[::1]:8080/x' ) );
		$this->assertSame( 'http://[::1]/x', $policy->resolve( 'http://[::1]/x' ) );
		$this->assertSame( 'http://[::1]:8080/x', $policy->resolve( '/x' ) );
		$this->assertNull( $policy->resolve( 'http://[::2]:8080/x' ) );
	}

	public function testInternationalizedServerRequiresPunycode(): void {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' );
		$this->setLogger( 'MultiPurge', $logger );

		$unicodeServer = new PurgeUrlPolicy( 'https://wörth.example' );
		$this->assertNull( $unicodeServer->resolve( '/x' ) );
		$this->assertNull( $unicodeServer->resolve( 'https://wörth.example/x' ) );

		$punycodeServer = new PurgeUrlPolicy( 'https://xn--wrth-5qa.example' );
		$this->assertSame( 'https://xn--wrth-5qa.example/x', $punycodeServer->resolve( '/x' ) );
	}

	public function testProtocolRelativeServerKeepsTheRootRelativeSchemeFallback(): void {
		$policy = new PurgeUrlPolicy( '//wiki.example:80' );

		$this->assertSame( 'http://wiki.example:80/x?', $policy->resolve( '/x?' ) );
		$this->assertSame( 'https://wiki.example:80/x?', $policy->resolve( '//wiki.example:80/x?' ) );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::resolve
	 * @return void
	 */
	public function testResolvesIpv6AllowlistEntry() {
		$policy = new PurgeUrlPolicy( self::SERVER, [ '[::2]' ] );

		$this->assertSame( 'http://[::2]/x', $policy->resolve( 'http://[::2]/x' ) );
		$this->assertNull( $policy->resolve( 'http://[::3]/x' ) );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::newFromConfigs
	 * @return void
	 */
	public function testNewFromConfigsReadsServerAndAllowlist() {
		$this->overrideConfigValues( [
			MainConfigNames::Server => 'https://configured.example',
			'MultiPurgeAllowedHosts' => [ 'static.example' ],
		] );

		$policy = PurgeUrlPolicy::newFromConfigs(
			$this->getServiceContainer()->getMainConfig(),
			$this->getServiceContainer()->getConfigFactory()->makeConfig( 'MultiPurge' )
		);

		$this->assertSame( 'https://configured.example/x', $policy->resolve( '/x' ) );
		$this->assertSame( 'https://static.example/x', $policy->resolve( '//static.example/x' ) );
		$this->assertNull( $policy->resolve( '//other.example/x' ) );
	}

	/**
	 * Entries that are not bare hostnames would silently never match, so they are dropped with a warning.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::__construct
	 * @return void
	 */
	public function testInvalidAllowlistEntriesAreDroppedWithWarning() {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->exactly( 3 ) )->method( 'warning' );
		$this->setLogger( 'MultiPurge', $logger );

		$policy = new PurgeUrlPolicy( self::SERVER, [
			'static.example:8080',
			'https://other.example',
			'wörth.example',
			'valid.example',
		] );

		$this->assertNull( $policy->resolve( 'https://static.example:8080/x' ) );
		$this->assertNull( $policy->resolve( 'https://other.example/x' ) );
		$this->assertSame( 'https://valid.example/x', $policy->resolve( 'https://valid.example/x' ) );
	}

	/**
	 * A rejected URL is untrusted input, so nothing of it is logged: no raw url and no untrusted reason text.
	 * The log names only the rejection reason and, when proven safe by validation, the host.
	 *
	 * @dataProvider provideUrlsWithCredentials
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::logRejection
	 * @param string $url
	 * @param string|null $expectedHost
	 * @return void
	 */
	public function testRejectionLogsNeverContainTheRawUrlOrItsSecrets( string $url, ?string $expectedHost ) {
		$logged = [];
		$logger = $this->createMock( LoggerInterface::class );
		$logger->method( 'warning' )->willReturnCallback(
			static function ( $message, array $context = [] ) use ( &$logged ): void {
				$logged[] = [ $message, $context ];
			}
		);
		$this->setLogger( 'MultiPurge', $logger );

		$this->policy()->resolveList( [ $url ] );

		$this->assertNotEmpty( $logged );
		[ $message, $context ] = $logged[0];
		$this->assertArrayNotHasKey( 'url', $context, 'No raw URL may be logged' );
		$this->assertArrayNotHasKey( 'entry', $context );
		$this->assertSame( $expectedHost, $context['host'] ?? null );
		$this->assertStringNotContainsString( 'secret', $message . json_encode( $context ) );
		$this->assertStringNotContainsString( 'user:secret', $message . json_encode( $context ) );
	}

	public static function provideUrlsWithCredentials(): array {
		return [
			'credentials before an allowed host' => [
				'https://user:secret@wiki.example/x', 'wiki.example',
			],
			'credentials hiding a disallowed host' => [
				'https://wiki.example@evil.example/x', 'evil.example',
			],
			'credentials in a protocol-relative url' => [
				'//user:secret@evil.example/x', 'evil.example',
			],
			'credentials in a malformed url' => [
				'https://user:secret@/x', null,
			],
			'password containing an at sign' => [
				'https://user:first@secret@wiki.example/x', 'wiki.example',
			],
			'userinfo containing an at sign' => [
				'https://user@wiki.example@evil.example/x', 'evil.example',
			],
			'leading space before credentials' => [
				' https://user:secret@wiki.example/x', null,
			],
			'leading tab before credentials' => [
				"\thttps://user:secret@wiki.example/x", null,
			],
			'vertical tab inside the url' => [
				"https://wiki.example/\x0buser:secret", 'wiki.example',
			],
		];
	}

	/**
	 * An invalid allowlist entry is untrusted too: the warning says what was wrong, never the entry itself.
	 * An entry like user:secret@host cannot leak.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::__construct
	 * @return void
	 */
	public function testInvalidAllowlistEntryWarningNeverContainsTheEntry() {
		$logged = [];
		$logger = $this->createMock( LoggerInterface::class );
		$logger->method( 'warning' )->willReturnCallback(
			static function ( $message, array $context = [] ) use ( &$logged ): void {
				$logged[] = [ $message, $context ];
			}
		);
		$this->setLogger( 'MultiPurge', $logger );

		$policy = new PurgeUrlPolicy( self::SERVER, [
			'user:secret@wiki.example',
			' https://user:secret@other.example',
			'wiki.example%40evil.example',
		] );

		$this->assertNull( $policy->resolve( 'https://user:secret@wiki.example/x' ) );
		foreach ( $logged as [ $message, $context ] ) {
			$this->assertStringNotContainsString( 'secret', $message . json_encode( $context ) );
			$this->assertArrayNotHasKey( 'entry', $context );
			$this->assertArrayNotHasKey( 'url', $context );
		}
	}

	/**
	 * A $wgServer that cannot yield a usable hostname is also never logged verbatim.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::__construct
	 * @return void
	 */
	public function testUnusableServerWarningNeverContainsTheServerValue() {
		$logged = [];
		$logger = $this->createMock( LoggerInterface::class );
		$logger->method( 'warning' )->willReturnCallback(
			static function ( $message, array $context = [] ) use ( &$logged ): void {
				$logged[] = [ $message, $context ];
			}
		);
		$this->setLogger( 'MultiPurge', $logger );

		$policy = new PurgeUrlPolicy( ' https://user:secret@wiki.example' );

		$this->assertNull( $policy->resolve( '/x' ) );
		$this->assertNotEmpty( $logged );
		foreach ( $logged as [ $message, $context ] ) {
			$this->assertStringNotContainsString( 'secret', $message . json_encode( $context ) );
		}
	}

	/**
	 * A mixed batch keeps its valid targets and reports rejected ones.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::resolveList
	 * @return void
	 */
	public function testResolveListKeepsValidTargetsAndLogsRejectedOnes() {
		$logged = [];
		$logger = $this->createMock( LoggerInterface::class );
		$logger->method( 'warning' )->willReturnCallback(
			static function ( $message, array $context = [] ) use ( &$logged ): void {
				$logged[] = $context;
			}
		);
		$this->setLogger( 'MultiPurge', $logger );

		$resolved = $this->policy()->resolveList( [
			'/wiki/Foo',
			'https://evil.example/x',
			'https://wiki.example/wiki/Foo',
		] );

		$this->assertSame( [ 'https://wiki.example/wiki/Foo' ], $resolved );
		$this->assertCount( 1, $logged );
		$this->assertArrayNotHasKey( 'url', $logged[0] );
		$this->assertSame( 'evil.example', $logged[0]['host'] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::resolveList
	 * @return void
	 */
	public function testResolveListDeduplicatesEquivalentUrls() {
		$resolved = $this->policy()->resolveList( [
			'/wiki/Foo',
			'https://wiki.example/wiki/Foo',
			'//wiki.example/wiki/Foo',
		] );

		$this->assertSame( [ 'https://wiki.example/wiki/Foo' ], $resolved );
	}

	/**
	 * A rejected input selected in several groups is one rejection, counted and logged once.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::resolveList
	 * @return void
	 */
	public function testResolveListCountsUniqueRejectedValues() {
		$warnings = 0;
		$logger = $this->createMock( LoggerInterface::class );
		$logger->method( 'warning' )->willReturnCallback(
			static function () use ( &$warnings ): void {
				$warnings++;
			}
		);
		$this->setLogger( 'MultiPurge', $logger );

		$rejectedCount = -1;
		$resolved = $this->policy()->resolveList( [
			'https://evil.example/dup',
			'https://evil.example/dup',
			'https://evil.example/other',
			'https://wiki.example/ok',
		], $rejectedCount );

		$this->assertSame( [ 'https://wiki.example/ok' ], $resolved );
		$this->assertSame( 2, $rejectedCount, 'Duplicate inputs count once; distinct inputs count each' );
		$this->assertSame( 2, $warnings, 'Each distinct rejection is logged once' );
	}
}
