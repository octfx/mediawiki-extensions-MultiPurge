<?php

declare( strict_types=1 );

use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;

if ( !getenv( 'MW_INSTALL_PATH' ) ) {
	// The probe runs from the extension's test tree, so the checkout root sits five levels up.
	putenv( 'MW_INSTALL_PATH=' . dirname( __DIR__, 5 ) );
}

require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';

/**
 * Standalone memory probe for MultiPurgeJob's batch pipeline, run by MultiPurgeBatchMemoryTest.
 * It lives outside PHPUnit because the test runner's own allocations would drown out the measurement.
 * Peak memory is sampled immediately before URL generation and again after run() returns.
 * The delta therefore covers URL generation, request generation, submission, and response handling.
 */
class MultiPurgeBatchMemoryScript extends Maintenance {

	private const URL_COUNT = 10000;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'MultiPurge' );
	}

	/**
	 * @return bool
	 */
	public function execute() {
		ini_set( 'memory_limit', '-1' );

		// The probe classes extend core classes, which only load once MediaWiki is booted.
		require_once __DIR__ . '/MultiPurgeMemoryProbeClient.php';
		require_once __DIR__ . '/MultiPurgeMemoryProbeRequestFactory.php';
		$client = new MultiPurgeMemoryProbeClient( self::URL_COUNT );
		$factory = new MultiPurgeMemoryProbeRequestFactory( $client );
		MediaWikiServices::getInstance()->redefineService(
			'HttpRequestFactory',
			static fn () => $factory
		);

		$server = MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::Server );

		$GLOBALS['wgMultiPurgeEnabledServices'] = [ Varnish::class, Cloudflare::class ];
		$GLOBALS['wgMultiPurgeVarnishServers'] = [
			'varnish-a.internal',
			'http://varnish-b.internal:6081',
			'10.11.12.13',
			'https://varnish-d.internal:6082',
		];
		$GLOBALS['wgMultiPurgeCloudFlareZoneId'] = 'test-zone';
		$GLOBALS['wgMultiPurgeCloudFlareApiToken'] = 'test-token';

		gc_collect_cycles();
		// PHP 8.2+ drops the boot-time high-water mark so the workload peak is measured exactly.
		// Without it, the post-run peak minus baseline usage stays conservative instead.
		// The peak can never sit below usage reached during the workload, so old headroom cannot hide allocations.
		$peakReset = function_exists( 'memory_reset_peak_usage' );
		if ( $peakReset ) {
			memory_reset_peak_usage();
		}
		$usageBefore = memory_get_usage();
		$realUsageBefore = memory_get_usage( true );
		$peakBefore = memory_get_peak_usage();
		$realPeakBefore = memory_get_peak_usage( true );

		$urls = [];
		for ( $i = 0; $i < self::URL_COUNT; $i++ ) {
			$urls[] = "$server/purge-batch/$i";
		}

		$job = new MultiPurgeJob( [ 'urls' => $urls ] );
		$jobResult = $job->run();

		$peakAfter = memory_get_peak_usage();
		$realPeakAfter = memory_get_peak_usage( true );
		$peakDelta = max( 0, $peakAfter - $usageBefore );
		$realPeakDelta = max( 0, $realPeakAfter - $realUsageBefore );

		$result = [
			'phpVersion' => PHP_VERSION,
			'measurement' => $peakReset
				? 'peak reset before workload, usage baseline per allocator'
				: 'no peak reset available, conservative usage baseline per allocator',
			'budgetMiB' => 32,
			'urlCount' => self::URL_COUNT,
			'servers' => 4,
			'jobResult' => $jobResult,
			'runMultiCalls' => $client->calls,
			'maxBatch' => $client->maxBatch,
			'totalRequests' => $client->totalRequests,
			'varnishRequests' => $client->varnishRequests,
			'cloudflarePosts' => $client->cloudflarePosts,
			'cloudflareMaxUrlsPerPost' => $client->cloudflareMaxUrlsPerPost,
			'missingPerBucket' => $client->missingPerBucket(),
			'usageBefore' => $usageBefore,
			'realUsageBefore' => $realUsageBefore,
			'peakBefore' => $peakBefore,
			'realPeakBefore' => $realPeakBefore,
			'peakAfter' => $peakAfter,
			'realPeakAfter' => $realPeakAfter,
			'peakDelta' => $peakDelta,
			'realPeakDelta' => $realPeakDelta,
			'usageAfter' => memory_get_usage(),
		];
		$result['peakDeltaMiB'] = round( $result['peakDelta'] / 1048576, 2 );
		$result['realPeakDeltaMiB'] = round( $result['realPeakDelta'] / 1048576, 2 );

		echo 'MULTIPURGE_MEMORY_RESULT ' . json_encode( $result ) . "\n";

		return true;
	}
}

$maintClass = MultiPurgeBatchMemoryScript::class;
require_once RUN_MAINTENANCE_IF_MAIN;
