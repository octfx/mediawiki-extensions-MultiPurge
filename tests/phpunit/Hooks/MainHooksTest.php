<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests\Hooks;

use MediaWiki\Extension\MultiPurge\Hooks\MainHooks;
use MediaWiki\Extension\MultiPurge\PurgeEventRelayer;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Psr\Log\LogLevel;
use TestLogger;
use Wikimedia\EventRelayer\EventRelayerNull;

/**
 * @group MultiPurge
 */
class MainHooksTest extends MediaWikiIntegrationTestCase {
	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\MainHooks::setup
	 * @return void
	 */
	public function testSetup() {
		global $wgEventRelayerConfig;

		MainHooks::setup();

		$this->assertEquals( [ 'class' => PurgeEventRelayer::class ], $wgEventRelayerConfig['cdn-url-purges'] );
	}

	/**
	 * The stock core config and MultiPurge's own relayer in either position must not log.
	 * Core's EventRelayerGroup sends an absent cdn-url-purges entry to the default entry's relayer.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\MainHooks::setup
	 * @dataProvider provideNonConflictingRelayerConfigs
	 * @param array $relayerConfig
	 * @return void
	 */
	public function testSetupLogsNothingForNonConflictingConfig( array $relayerConfig ) {
		global $wgEventRelayerConfig;
		$this->overrideConfigValues( [ MainConfigNames::EventRelayerConfig => $relayerConfig ] );
		$logger = new TestLogger( true, null, true );
		$this->setLogger( 'MultiPurge', $logger );

		MainHooks::setup();

		$this->assertSame( [], $logger->getBuffer(), 'A non-conflicting setup must stay silent' );
		$this->assertSame( [ 'class' => PurgeEventRelayer::class ], $wgEventRelayerConfig['cdn-url-purges'] );
	}

	public static function provideNonConflictingRelayerConfigs(): array {
		return [
			'empty config' => [ [] ],
			'core stock null default' => [
				[ 'default' => [ 'class' => EventRelayerNull::class ] ],
			],
			'own relayer as default fallback' => [
				[ 'default' => [ 'class' => PurgeEventRelayer::class ] ],
			],
			'own relayer as default fallback with options' => [
				[ 'default' => [ 'class' => PurgeEventRelayer::class, 'logLevel' => 'debug' ] ],
			],
			'own relayer with operator options' => [
				[
					'default' => [ 'class' => EventRelayerNull::class ],
					'cdn-url-purges' => [ 'class' => PurgeEventRelayer::class, 'logLevel' => 'debug' ],
				],
			],
			'own explicit channel over custom default' => [
				[
					'default' => [ 'class' => 'AcmeDefaultRelayer' ],
					'cdn-url-purges' => [ 'class' => PurgeEventRelayer::class ],
				],
			],
		];
	}

	/**
	 * Registering through the real setup must be repeatable without warning.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\MainHooks::setup
	 * @return void
	 */
	public function testRepeatedSetupStaysSilent() {
		global $wgEventRelayerConfig;
		$logger = new TestLogger( true, null, true );
		$this->setLogger( 'MultiPurge', $logger );

		MainHooks::setup();
		MainHooks::setup();

		$this->assertSame( [], $logger->getBuffer(), 'Registering our own relayer twice must not warn' );
		$this->assertSame( [ 'class' => PurgeEventRelayer::class ], $wgEventRelayerConfig['cdn-url-purges'] );
	}

	/**
	 * The relayer MediaWiki would actually use for cdn-url-purges purges - the explicit entry,
	 * or the default entry as the fallback - must become visible when MultiPurge displaces it,
	 * while the replacement behavior stays unchanged.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\MainHooks::setup
	 * @dataProvider provideDisplacedRelayerConfigs
	 * @param array $relayerConfig
	 * @param string $expectedLoggedClass
	 * @param string $expectedVia
	 * @return void
	 */
	public function testSetupWarnsOnDisplacedRelayer(
		array $relayerConfig,
		string $expectedLoggedClass,
		string $expectedVia
	) {
		global $wgEventRelayerConfig;
		$this->overrideConfigValues( [ MainConfigNames::EventRelayerConfig => $relayerConfig ] );
		$logger = new TestLogger( true, null, true );
		$this->setLogger( 'MultiPurge', $logger );

		MainHooks::setup();

		$records = $logger->getBuffer();
		$this->assertCount( 1, $records, 'The displaced relayer must produce exactly one log record' );
		[ $level, $message, $context ] = $records[0];
		$this->assertSame( LogLevel::WARNING, $level );
		$this->assertStringContainsString( 'cdn-url-purges', $message );
		$this->assertSame( $expectedLoggedClass, $context['class'] ?? null );
		$this->assertSame( $expectedVia, $context['via'] ?? null );
		$this->assertSame(
			[ 'class' => PurgeEventRelayer::class ],
			$wgEventRelayerConfig['cdn-url-purges'],
			'The warning must not change the replacement behavior'
		);
	}

	public static function provideDisplacedRelayerConfigs(): array {
		return [
			'explicit core null relayer' => [
				[ 'cdn-url-purges' => [ 'class' => EventRelayerNull::class ] ],
				EventRelayerNull::class,
				'cdn-url-purges',
			],
			'explicit foreign relayer with options' => [
				[ 'cdn-url-purges' => [ 'class' => 'AcmePurgeRelayer', 'logLevel' => 'debug' ] ],
				'AcmePurgeRelayer',
				'cdn-url-purges',
			],
			'explicit entry without a class key' => [
				[ 'cdn-url-purges' => [ 'factory' => 'AcmePurgeRelayerFactory' ] ],
				'array',
				'cdn-url-purges',
			],
			'explicit non-array entry' => [
				[ 'cdn-url-purges' => 'AcmePurgeRelayer' ],
				'string',
				'cdn-url-purges',
			],
			'foreign default fallback' => [
				[ 'default' => [ 'class' => 'AcmeDefaultRelayer' ] ],
				'AcmeDefaultRelayer',
				'default',
			],
			'foreign default fallback with options' => [
				[ 'default' => [ 'class' => 'AcmeDefaultRelayer', 'logLevel' => 'debug' ] ],
				'AcmeDefaultRelayer',
				'default',
			],
			'foreign default fallback without a class key' => [
				[ 'default' => [ 'factory' => 'AcmeDefaultRelayerFactory' ] ],
				'array',
				'default',
			],
			'foreign non-array default fallback' => [
				[ 'default' => 'AcmeDefaultRelayer' ],
				'string',
				'default',
			],
			'non-string class in default fallback' => [
				[ 'default' => [ 'class' => 42 ] ],
				'array',
				'default',
			],
			'explicit channel takes precedence over custom default' => [
				[
					'default' => [ 'class' => 'AcmeDefaultRelayer' ],
					'cdn-url-purges' => [ 'class' => 'AcmePurgeRelayer' ],
				],
				'AcmePurgeRelayer',
				'cdn-url-purges',
			],
			'null channel entry falls back to custom default' => [
				[
					'default' => [ 'class' => 'AcmeDefaultRelayer' ],
					'cdn-url-purges' => null,
				],
				'AcmeDefaultRelayer',
				'default',
			],
		];
	}
}
