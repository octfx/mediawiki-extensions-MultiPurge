<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

use MediaWiki\Cache\HTMLCacheUpdater;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;

/**
 * @group MultiPurge
 * @group Database
 * @covers \MediaWiki\Extension\MultiPurge\PurgeEventRelayer
 */
class PurgeEventRelayerTest extends MediaWikiIntegrationTestCase {

	public function testPurgesFileThumbnailsOncePerService(): void {
		// Keep the upload out of the wiki's own upload directory
		$dir = $this->getNewTempDirectory();
		$repo = $this->getConfVar( MainConfigNames::LocalFileRepo );
		$repo['directory'] = "$dir/images";
		$repo['thumbDir'] = "$dir/images/thumb";
		$repo['deletedDir'] = "$dir/deleted";
		$this->overrideConfigValues( [
			MainConfigNames::LocalFileRepo => $repo,
			MainConfigNames::UseInstantCommons => false,
			'MultiPurgeRunInQueue' => false,
			'MultiPurgeEnabledServices' => [ 'Varnish' ],
			'MultiPurgeVarnishServers' => [],
		] );
		// MW 1.46's test framework replaces the service with one that never reaches the relayer
		$services = $this->getServiceContainer();
		$this->setService(
			version_compare( MW_VERSION, '1.46', '<' ) ? 'HtmlCacheUpdater' : 'HTMLCacheUpdater',
			new HTMLCacheUpdater( $services->getHookContainer(), $services->getTitleFactory(), 0, false, 0 )
		);
		$purged = [];
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService', static function ( $service, $urls ) use ( &$purged ) {
			$purged[] = $urls;
		} );

		$source = MW_INSTALL_PATH . '/tests/phpunit/data/media/Png-native-test.png';
		$localRepo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$file = $localRepo->newFile( 'MultiPurgeThumbnailTest.png' );
		$this->assertStatusGood(
			$file->upload( $source, '', '', 0, false, false, $this->getTestSysop()->getUser() )
		);
		$thumbnails = [ '50px-MultiPurgeThumbnailTest.png', '100px-MultiPurgeThumbnailTest.png' ];
		foreach ( $thumbnails as $thumbnail ) {
			$this->assertStatusGood( $localRepo->quickImport( $source, $file->getThumbPath( $thumbnail ) ) );
		}
		DeferredUpdates::doUpdates();
		$purged = [];

		$file->purgeThumbnails();
		DeferredUpdates::doUpdates();

		$this->assertCount( 1, $purged );
		$this->assertEqualsCanonicalizing( array_map( [ $file, 'getThumbUrl' ], $thumbnails ), $purged[0] );
	}
}
