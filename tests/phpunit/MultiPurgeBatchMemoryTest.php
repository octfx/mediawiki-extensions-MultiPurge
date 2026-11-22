<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use MediaWiki\Shell\Shell;
use MediaWikiIntegrationTestCase;

/**
 * Measures the approved batch budget in a separate PHP process, outside PHPUnit's own allocations.
 *
 * Workload: 10,000 URLs across four Varnish servers (40,000 PURGE requests) plus Cloudflare (100 posts).
 * Cloudflare keeps the operator default of 100 URLs per post.
 * Every response is a short controlled 200 "ok" from the probe's counting client, which retains no request maps.
 *
 * Method: the probe boots MediaWiki as a maintenance script and resets the process peak when PHP supports it.
 * The reset requires PHP 8.2+ and was verified to cover both allocators.
 * Without it, subtracting current usage from the post-run peak can only overstate the workload.
 * The baseline is sampled per allocator immediately before generating the URL array.
 * memory_get_usage() pairs with memory_get_peak_usage() for script allocations.
 * memory_get_usage( true ) pairs with memory_get_peak_usage( true ) for the allocator's real footprint.
 * The 32 MiB budget covers the whole delta of both allocators, including URL generation.
 *
 * @group MultiPurge
 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob
 * @covers \MediaWiki\Extension\MultiPurge\Services\Varnish
 * @covers \MediaWiki\Extension\MultiPurge\Services\Cloudflare
 */
class MultiPurgeBatchMemoryTest extends MediaWikiIntegrationTestCase {

	private const URL_COUNT = 10000;

	private const SERVER_COUNT = 4;

	private const BUDGET_BYTES = 32 * 1024 * 1024;

	public function testLargeBatchStaysWithinBudgetAndLosesNothing() {
		if ( Shell::isDisabled() ) {
			$this->markTestSkipped( 'proc_open() is unavailable, so the separate-process probe cannot run' );
		}

		$installPath = defined( 'MW_INSTALL_PATH' ) ? MW_INSTALL_PATH : dirname( __DIR__, 4 );
		$script = __DIR__ . '/Memory/MultiPurgeBatchMemoryScript.php';

		// The probe environment is explicit so the child never depends on the parent's shell setup.
		$environment = [ 'MW_INSTALL_PATH' => $installPath ];
		$configFile = getenv( 'MW_CONFIG_FILE' );
		if ( $configFile !== false ) {
			$environment['MW_CONFIG_FILE'] = (string)$configFile;
		}

		$result = Shell::command( PHP_BINARY, $script )
			->environment( $environment )
			->includeStderr()
			->execute();

		$this->assertSame( 0, $result->getExitCode(), "Probe failed:\n" . $result->getStdout() );

		$json = null;
		foreach ( array_reverse( explode( "\n", $result->getStdout() ) ) as $line ) {
			if ( str_starts_with( $line, 'MULTIPURGE_MEMORY_RESULT ' ) ) {
				$json = json_decode( substr( $line, strlen( 'MULTIPURGE_MEMORY_RESULT ' ) ), true );
				break;
			}
		}
		$this->assertIsArray( $json, 'Probe produced no result line:\n' . $result->getStdout() );

		$this->assertTrue( $json['jobResult'], 'The controlled 200-only workload must succeed' );
		$this->assertSame( self::URL_COUNT, $json['urlCount'] );
		$this->assertSame( self::SERVER_COUNT, $json['servers'] );
		$this->assertSame(
			32,
			$json['budgetMiB'],
			'The probe must state the approved 32 MiB target it is measured against'
		);
		$this->assertMatchesRegularExpression(
			'/(^peak reset|no peak reset available)/',
			(string)$json['measurement'],
			'The probe must state which baseline method produced the numbers'
		);

		$this->assertSame( 100, $json['maxBatch'], 'No runMulti() call may exceed 100 requests' );
		$this->assertSame(
			self::URL_COUNT * self::SERVER_COUNT / 100 + 1,
			$json['runMultiCalls'],
			'400 full Varnish batches plus one 100-post Cloudflare call'
		);
		$this->assertSame( self::URL_COUNT * self::SERVER_COUNT + self::URL_COUNT / 100, $json['totalRequests'] );
		$this->assertSame( self::URL_COUNT * self::SERVER_COUNT, $json['varnishRequests'] );
		$this->assertSame( self::URL_COUNT / 100, $json['cloudflarePosts'] );
		$this->assertSame(
			100,
			$json['cloudflareMaxUrlsPerPost'],
			'The operator default of 100 URLs per post is kept'
		);

		$this->assertCount( self::SERVER_COUNT + 1, $json['missingPerBucket'] );
		foreach ( $json['missingPerBucket'] as $bucket => $missing ) {
			$this->assertSame( 0, $missing, "Bucket $bucket lost URLs" );
		}

		$this->assertLessThanOrEqual(
			self::BUDGET_BYTES,
			$json['peakDelta'],
			"Script-allocator peak of {$json['peakDeltaMiB']} MiB ({$json['measurement']}) " .
			'exceeds the 32 MiB budget'
		);
		$this->assertLessThanOrEqual(
			self::BUDGET_BYTES,
			$json['realPeakDelta'],
			"Real-allocator peak of {$json['realPeakDeltaMiB']} MiB ({$json['measurement']}) " .
			'exceeds the 32 MiB budget'
		);
	}
}
