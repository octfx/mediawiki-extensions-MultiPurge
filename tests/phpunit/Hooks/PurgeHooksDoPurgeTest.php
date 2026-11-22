<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests\Hooks;

use ArrayObject;
use ForeignAPIFile;
use ForeignAPIRepo;
use FSFileBackend;
use JobQueueGroup;
use LocalFile;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use RepoGroup;
use WikiFilePage;
use Wikimedia\EventRelayer\EventRelayerGroup;
use Wikimedia\EventRelayer\EventRelayerNull;
use Wikimedia\Http\MultiHttpClient;

require_once __DIR__ . '/RecordingEventRelayer.php';

/**
 * Counts what each purge service receives when MediaWiki's real WikiPage::doPurge() runs,
 * to prove which extension work duplicates the core cdn-url-purges relayer path.
 *
 * @group MultiPurge
 * @group Database
 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks
 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onArticlePurge
 */
class PurgeHooksDoPurgeTest extends MediaWikiIntegrationTestCase {

	/**
	 * The extension hook dispatches during doPurge itself, and the core relayer dispatches
	 * when the deferred CdnCacheUpdate runs, so the observer captures both paths per service.
	 *
	 * @return void
	 */
	public function testOrdinaryPageDoPurgeDispatchesTitleUrlsOncePerService(): void {
		$this->enableBothServicesInline();
		$sent = $this->mockHttpResponses();

		$created = $this->insertPage( 'MultiPurgeOrdinaryPage' );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $created['title'] );
		DeferredUpdates::doUpdates();

		$dispatched = [];
		$this->captureServicePurges( $dispatched );

		$this->assertTrue( $page->doPurge() );
		DeferredUpdates::doUpdates();

		$title = $created['title'];
		$expected = [
			$title->getInternalURL(),
			$title->getInternalURL( 'action=history' ),
		];
		$this->assertSame(
			[
				[ Varnish::class, $expected ],
				[ Cloudflare::class, $expected ],
			],
			$dispatched,
			'Each service receives the title urls exactly once, relayed by MediaWiki core'
		);
		$this->assertNotEmpty( $sent->getArrayCopy(), 'Purge requests were really dispatched' );
	}

	public function testPagePurgeHonorsRelayerUrlHookVeto(): void {
		$this->enableBothServicesInline();
		$sent = $this->mockHttpResponses();
		$created = $this->insertPage( 'MultiPurgeRelayerVetoPage' );
		DeferredUpdates::doUpdates();
		$sent->exchangeArray( [] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeUrls', static fn () => false );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $created['title'] );

		$this->assertTrue( $page->doPurge() );
		DeferredUpdates::doUpdates();

		$this->assertSame( [], $sent->getArrayCopy() );
	}

	public function testPagePurgeInvalidatesLocallyWhenRelayerLookupFails(): void {
		$this->enableBothServicesInline();
		$this->mockHttpResponses();
		$created = $this->insertPage( 'MultiPurgeUnavailableRelayerPage' );
		DeferredUpdates::doUpdates();

		$dbw = $this->getDb( DB_PRIMARY );
		$before = '20000101000000';
		$dbw->newUpdateQueryBuilder()
			->update( 'page' )
			->set( [ 'page_touched' => $dbw->timestamp( $before ) ] )
			->where( [ 'page_id' => $created['id'] ] )
			->caller( __METHOD__ )->execute();

		$services = $this->getServiceContainer();
		$relayers = $services->getEventRelayerGroup();
		$services->resetServiceForTesting( 'EventRelayerGroup' );
		$services->redefineService( 'EventRelayerGroup', static fn () => new EventRelayerGroup( [] ) );
		$page = $services->getWikiPageFactory()->newFromTitle( $created['title'] );

		// Isolate the synchronous page purge from core's deferred CDN notification.
		$scope = DeferredUpdates::preventOpportunisticUpdates();
		try {
			$this->assertTrue( $page->doPurge() );
		} finally {
			$services->resetServiceForTesting( 'EventRelayerGroup' );
			$services->redefineService( 'EventRelayerGroup', static fn () => $relayers );
			unset( $scope );
		}
		DeferredUpdates::doUpdates();

		$this->assertGreaterThan( $before, $services->getPageStore()->getPageById( $created['id'] )->getTouched() );
	}

	/**
	 * Core never purges ResourceLoader load.php urls, so the extension must keep purging
	 * site.styles itself while no longer duplicating the title urls core relays.
	 *
	 * @return void
	 */
	public function testCssPageDoPurgeKeepsSiteStylesAndDropsTheDuplicateTitlePurge(): void {
		$this->enableBothServicesInline();
		$this->mockHttpResponses();

		$created = $this->insertPage( 'Common.css', '/* unit test css */', NS_MEDIAWIKI );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $created['title'] );
		DeferredUpdates::doUpdates();

		$dispatched = [];
		$this->captureServicePurges( $dispatched );

		$this->assertTrue( $page->doPurge() );
		DeferredUpdates::doUpdates();

		$title = $created['title'];
		$relayedPageUrls = [
			$title->getInternalURL(),
			$title->getInternalURL( 'action=history' ),
			$title->getInternalURL( 'action=raw&ctype=text/css' ),
		];

		$this->assertCount( 4, $dispatched, 'Each service got one extension dispatch and one core relay' );
		foreach ( [ Varnish::class, Cloudflare::class ] as $index => $service ) {
			[ $hookService, $hookUrls ] = $dispatched[$index];
			[ $relayerService, $relayerUrls ] = $dispatched[$index + 2];
			$this->assertSame( $service, $hookService );
			$this->assertSame( $service, $relayerService );

			$this->assertSame( $relayedPageUrls, $relayerUrls, 'Core relayed the css page urls' );

			$this->assertCount( 1, $hookUrls, 'The extension purges only the site.styles url itself' );
			$this->assertStringContainsString( 'load.php', $hookUrls[0] );
			$this->assertStringStartsWith( 'https://example.org/', $hookUrls[0] );
			$query = [];
			parse_str( (string)parse_url( $hookUrls[0], PHP_URL_QUERY ), $query );
			$this->assertSame( 'site.styles', $query['modules'] ?? null );
			$this->assertSame( 'styles', $query['only'] ?? null );
		}
	}

	/**
	 * Core deletes the rendered thumbnails and relays their urls before the ArticlePurge hook
	 * runs, so the retained file branch only re-purges the file url while core relays the rest.
	 * Counts are per url, because web request rounds merge the core relays into one batch.
	 *
	 * @return void
	 */
	public function testLocalFileDoPurgeCountsUrlsPerService(): void {
		[ $file, $thumbnails, $title ] = $this->uploadLocalFileWithThumbnails( 'MultiPurgeDoPurgeFile.png' );
		$this->enableBothServicesInline();
		$this->mockHttpResponses();

		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
		DeferredUpdates::doUpdates();

		$dispatched = [];
		$this->captureServicePurges( $dispatched );

		$this->assertTrue( $page->doPurge() );
		DeferredUpdates::doUpdates();

		$server = 'https://example.org';
		$fileUrl = $server . $file->getUrl();
		$thumbUrls = array_map(
			static fn ( string $thumb ): string => $server . $file->getThumbUrl( $thumb ),
			$thumbnails
		);
		$pageUrls = [
			$title->getInternalURL(),
			$title->getInternalURL( 'action=history' ),
		];

		$countsByUrl = [];
		$services = [];
		foreach ( $dispatched as [ $service, $urls ] ) {
			$services[$service] = true;
			foreach ( $urls as $url ) {
				$countsByUrl["$service|$url"] = ( $countsByUrl["$service|$url"] ?? 0 ) + 1;
			}
		}
		$this->assertSame( [ Varnish::class, Cloudflare::class ], array_keys( $services ) );

		foreach ( [ Varnish::class, Cloudflare::class ] as $service ) {
			foreach ( $thumbUrls as $thumbUrl ) {
				$this->assertSame( 1, $countsByUrl["$service|$thumbUrl"] ?? 0,
					'Core relays each thumbnail url to the service exactly once' );
			}
			foreach ( $pageUrls as $pageUrl ) {
				$this->assertSame( 1, $countsByUrl["$service|$pageUrl"] ?? 0,
					'Core relays each file page url to the service exactly once' );
			}
			$this->assertSame( 2, $countsByUrl["$service|$fileUrl"] ?? 0,
				'Core relays the file url once and the retained file branch re-purges it once' );
		}
	}

	/**
	 * ForeignAPIFile purgeCache deletes the local thumb cache without any CDN relay,
	 * and the host policy keeps unallowlisted foreign urls out, so only the file page
	 * title urls reach the services.
	 *
	 * @return void
	 */
	public function testForeignFileDoPurgeRelaysOnlyPageUrlsWhenForeignHostIsNotAllowed(): void {
		[ $page, $title ] = $this->newForeignFilePage();
		$this->enableBothServicesInline();
		$this->mockHttpResponses();
		DeferredUpdates::doUpdates();

		$dispatched = [];
		$this->captureServicePurges( $dispatched );

		$this->assertTrue( $page->doPurge() );
		DeferredUpdates::doUpdates();

		$expected = [
			$title->getInternalURL(),
			$title->getInternalURL( 'action=history' ),
		];
		$this->assertSame( [
			[ Varnish::class, $expected ],
			[ Cloudflare::class, $expected ],
		], $dispatched, 'Only the file page title urls were relayed by core' );

		foreach ( $dispatched as [ , $urls ] ) {
			foreach ( $urls as $url ) {
				$this->assertStringNotContainsString( 'foreign.example', $url );
			}
		}
	}

	/**
	 * An operator can explicitly allowlist a foreign upload host, and the retained file branch
	 * is then the only path that still submits the foreign file url on a purge.
	 *
	 * @return void
	 */
	public function testForeignFileDoPurgeKeepsHookCoverageWhenForeignHostIsAllowed(): void {
		[ $page, , $file ] = $this->newForeignFilePage();
		$this->enableBothServicesInline();
		$this->overrideConfigValue( 'MultiPurgeAllowedHosts', [ 'foreign.example' ] );
		$this->mockHttpResponses();
		DeferredUpdates::doUpdates();

		$dispatched = [];
		$this->captureServicePurges( $dispatched );

		$this->assertTrue( $page->doPurge() );
		DeferredUpdates::doUpdates();

		$fileUrlCounts = [];
		foreach ( $dispatched as [ , $urls ] ) {
			foreach ( $urls as $url ) {
				$fileUrlCounts[$url] = ( $fileUrlCounts[$url] ?? 0 ) + 1;
			}
		}
		$this->assertSame( 2, $fileUrlCounts[$file->getUrl()] ?? 0,
			'The retained file branch kept submitting the foreign file url for each service' );
	}

	/**
	 * Listing thumbnails scans the file backend, so with no enabled service it must not run at all.
	 *
	 * @return void
	 */
	public function testFilePageDoPurgeSkipsThumbnailListingWhenNoServiceIsEnabled(): void {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => false,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [],
		] );

		$fileMock = $this->getMockBuilder( LocalFile::class )
			->disableOriginalConstructor()
			->onlyMethods( [
				'exists', 'getName', 'purgeCache', 'getHistory',
				'getThumbnails', 'getThumbUrl', 'getUrl',
			] )
			->getMock();
		$fileMock->method( 'exists' )->willReturn( true );
		$fileMock->method( 'getName' )->willReturn( 'SkipListing.png' );
		$fileMock->method( 'getHistory' )->willReturn( [] );
		$fileMock->method( 'getUrl' )->willReturn( '/w/images/SkipListing.png' );
		$fileMock->method( 'getThumbUrl' )->willReturn( '/w/images/thumb/50px-SkipListing.png' );
		$fileMock->expects( $this->never() )->method( 'getThumbnails' );

		$repoMock = $this->getMockBuilder( RepoGroup::class )->disableOriginalConstructor()->getMock();
		$repoMock->method( 'findFile' )->willReturn( $fileMock );
		$this->setService( 'RepoGroup', $repoMock );

		$title = Title::newFromText( 'SkipListing.png', NS_FILE );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$this->assertTrue( $page->doPurge() );
		DeferredUpdates::doUpdates();

		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( 'MultiPurgePages' );
		$this->assertFalse( $queue->pop(), 'No purge job was enqueued' );
	}

	/**
	 * EventRelayerGroup copies its channel config when the service is first built and never refreshes
	 * it, so this group reproduces one constructed before MultiPurge's setup installed its relayer:
	 * core relays title urls to the stock no-op and the hook must keep direct title coverage.
	 *
	 * @return void
	 */
	public function testPagePurgeKeepsTitleCoverageWhenRelayerGroupWasBuiltBeforeSetup(): void {
		$this->enableBothServicesInline();
		$this->mockHttpResponses();
		$this->getServiceContainer()->redefineService( 'EventRelayerGroup',
			static fn () => new EventRelayerGroup( [ 'default' => [ 'class' => EventRelayerNull::class ] ] )
		);

		$created = $this->insertPage( 'MultiPurgeStaleRelayerGroupPage' );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $created['title'] );
		DeferredUpdates::doUpdates();

		$dispatched = [];
		$this->captureServicePurges( $dispatched );

		$this->assertTrue( $page->doPurge() );
		DeferredUpdates::doUpdates();

		$title = $created['title'];
		$expected = [
			$title->getInternalURL(),
			$title->getInternalURL( 'action=history' ),
		];
		$this->assertSame(
			[
				[ Varnish::class, $expected ],
			[ Cloudflare::class, $expected ],
			],
			$dispatched,
			'The hook kept direct title coverage because core relays to a group built before setup'
		);
	}

	/**
	 * A foreign relayer that owns the cdn-url-purges channel still receives core's relay, while the
	 * hook must keep MultiPurge's own title coverage instead of silently relying on that relayer.
	 *
	 * @return void
	 */
	public function testPagePurgeKeepsTitleCoverageWhenAnotherRelayerOwnsTheChannel(): void {
		$this->enableBothServicesInline();
		$this->mockHttpResponses();
		RecordingEventRelayer::$lastInstance = null;
		$this->getServiceContainer()->redefineService( 'EventRelayerGroup',
			static fn () => new EventRelayerGroup( [
				'default' => [ 'class' => EventRelayerNull::class ],
				'cdn-url-purges' => [ 'class' => RecordingEventRelayer::class ],
			] )
		);

		$created = $this->insertPage( 'MultiPurgeForeignRelayerPage' );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $created['title'] );
		DeferredUpdates::doUpdates();
		if ( RecordingEventRelayer::$lastInstance ) {
			RecordingEventRelayer::$lastInstance->notifiedUrls = [];
		}

		$dispatched = [];
		$this->captureServicePurges( $dispatched );

		$this->assertTrue( $page->doPurge() );
		DeferredUpdates::doUpdates();

		$title = $created['title'];
		$expected = [
			$title->getInternalURL(),
			$title->getInternalURL( 'action=history' ),
		];
		$this->assertSame(
			[
				[ Varnish::class, $expected ],
			[ Cloudflare::class, $expected ],
			],
			$dispatched,
			'The hook kept direct title coverage next to the foreign relayer core relays to'
		);
		$this->assertNotNull( RecordingEventRelayer::$lastInstance,
			'Core resolved and used the foreign relayer that owns the channel' );
		$this->assertSame( $expected, RecordingEventRelayer::$lastInstance->notifiedUrls,
			'Core still relayed the title urls to the foreign relayer owner' );
	}

	/**
	 * The real ServiceWiring builds EventRelayerGroup from the main config, so a channel entry
	 * replaced before the group is constructed leaves a foreign owner: the fallback must keep
	 * MultiPurge coverage while core still relays to that foreign owner.
	 *
	 * @return void
	 */
	public function testRelayerConfigReplacedBeforeGroupConstructionKeepsCoverage(): void {
		// Config overrides reset service instances, so the group is rebuilt from this config on first use.
		$this->overrideConfigValue( MainConfigNames::EventRelayerConfig, [
			'default' => [ 'class' => EventRelayerNull::class ],
			'cdn-url-purges' => [ 'class' => RecordingEventRelayer::class ],
		] );
		$this->enableBothServicesInline();
		$this->mockHttpResponses();
		RecordingEventRelayer::$lastInstance = null;

		$created = $this->insertPage( 'MultiPurgeLateConfigPage' );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $created['title'] );
		DeferredUpdates::doUpdates();
		if ( RecordingEventRelayer::$lastInstance ) {
			RecordingEventRelayer::$lastInstance->notifiedUrls = [];
		}

		$dispatched = [];
		$this->captureServicePurges( $dispatched );

		$this->assertTrue( $page->doPurge() );
		DeferredUpdates::doUpdates();

		$title = $created['title'];
		$expected = [
			$title->getInternalURL(),
			$title->getInternalURL( 'action=history' ),
		];
		$this->assertSame(
			[
				[ Varnish::class, $expected ],
			[ Cloudflare::class, $expected ],
			],
			$dispatched,
			'The hook kept direct title coverage because the group was built from the replaced config'
		);
		$this->assertNotNull( RecordingEventRelayer::$lastInstance,
			'Core resolved and used the foreign relayer built from the replaced config' );
		$this->assertSame( $expected, RecordingEventRelayer::$lastInstance->notifiedUrls,
			'Core still relayed the title urls to the foreign relayer owner' );
	}

	/**
	 * The fallback title dispatch uses the same queue-after-invalidation handoff as the file branch,
	 * so a stale relayer group must not make title jobs visible to workers before the commit either.
	 *
	 * @return void
	 */
	public function testFallbackTitleDispatchWaitsForPageInvalidation(): void {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
		] );
		$this->getServiceContainer()->redefineService( 'EventRelayerGroup',
			static fn () => new EventRelayerGroup( [ 'default' => [ 'class' => EventRelayerNull::class ] ] )
		);

		$created = $this->insertPage( 'MultiPurgeFallbackOrderingPage' );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $created['title'] );
		$dbw = $this->getDb( DB_PRIMARY );
		$dbw->newUpdateQueryBuilder()
			->update( 'page' )
			->set( [ 'page_touched' => $dbw->timestamp( '20000101000000' ) ] )
			->where( [ 'page_id' => $created['id'] ] )
			->caller( __METHOD__ )->execute();

		$pageId = $created['id'];
		$pushed = [];
		$group = $this->getMockBuilder( JobQueueGroup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'push' ] )
			->getMock();
		$group->method( 'push' )->willReturnCallback(
			static function ( $job ) use ( $dbw, $pageId, &$pushed ): void {
				$pushed[] = [
					$job->getParams()['service'],
					$job->getParams()['urls'],
					$dbw->selectField(
						'page', 'page_touched', [ 'page_id' => $pageId ], 'PurgeHooksDoPurgeTest-pushSpy' ),
				];
			}
		);

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$group,
			$this->getServiceContainer()->getResourceLoader(),
			$this->getServiceContainer()->getUrlUtils(),
		);
		$this->setTemporaryHook( 'ArticlePurge', [ $hooks, 'onArticlePurge' ] );
		DeferredUpdates::doUpdates();

		$title = $created['title'];
		$expectedUrls = [
			$title->getInternalURL(),
			$title->getInternalURL( 'action=history' ),
		];

		$dbw->startAtomic( __METHOD__ );
		try {
			$page->doPurge();
			$this->assertSame( [], $pushed,
				'Jobs must not be enqueued while the invalidation transaction is still open' );
		} finally {
			$dbw->endAtomic( __METHOD__ );
		}
		DeferredUpdates::doUpdates();

		$this->assertCount( 1, $pushed, 'The fallback title job was enqueued after the purge returned' );
		$this->assertSame( [ Cloudflare::class, $expectedUrls ], [ $pushed[0][0], $pushed[0][1] ] );
		$this->assertGreaterThan( '20000101000000', $pushed[0][2],
			'The job was enqueued after the page invalidation committed' );
	}

	/**
	 * @param array &$dispatched
	 * @return void
	 */
	private function captureServicePurges( array &$dispatched ): void {
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( $service, array &$urls ) use ( &$dispatched ): void {
				$dispatched[] = [ $service, $urls ];
			}
		);
	}

	/**
	 * @return void
	 */
	private function enableBothServicesInline(): void {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => false,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Varnish::class, Cloudflare::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
	}

	/**
	 * @return ArrayObject Sent HTTP requests
	 */
	private function mockHttpResponses(): ArrayObject {
		$sent = new ArrayObject();
		$multiMock = $this->getMockBuilder( MultiHttpClient::class )
			->disableOriginalConstructor()
			->getMock();
		$multiMock->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) use ( $sent ) {
				foreach ( $requests as $i => $request ) {
					$sent->append( $request );
					$request['response'] = [ 200, null, [], null, null ];
					$requests[$i] = $request;
				}
				return $requests;
			}
		);

		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )
			->disableOriginalConstructor()
			->getMock();
		$httpMock->method( 'createMultiClient' )->willReturn( $multiMock );
		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		return $sent;
	}

	/**
	 * @param string $name
	 * @return array{0:LocalFile,1:string[],2:Title}
	 */
	private function uploadLocalFileWithThumbnails( string $name ): array {
		$dir = $this->getNewTempDirectory();
		$repo = $this->getConfVar( MainConfigNames::LocalFileRepo );
		$repo['directory'] = "$dir/images";
		$repo['thumbDir'] = "$dir/images/thumb";
		$repo['deletedDir'] = "$dir/deleted";
		$this->overrideConfigValue( MainConfigNames::LocalFileRepo, $repo );

		$localRepo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$file = $localRepo->newFile( $name );
		$source = MW_INSTALL_PATH . '/tests/phpunit/data/media/Png-native-test.png';
		$this->assertStatusGood(
			$file->upload( $source, '', '', 0, false, false, $this->getTestSysop()->getUser() )
		);

		$thumbnails = [ "50px-$name", "100px-$name" ];
		foreach ( $thumbnails as $thumbnail ) {
			$this->assertStatusGood( $localRepo->quickImport( $source, $file->getThumbPath( $thumbnail ) ) );
		}

		return [ $file, $thumbnails, Title::newFromText( $name, NS_FILE ) ];
	}

	/**
	 * A foreign file page whose file object is injected, so no foreign API request is needed.
	 *
	 * @return array{0:WikiFilePage,1:Title,2:ForeignAPIFile}
	 */
	private function newForeignFilePage(): array {
		$dir = $this->getNewTempDirectory();
		$backend = new FSFileBackend( [
			'name' => 'multipurge-foreign-test-backend',
			'wikiId' => WikiMap::getCurrentWikiId(),
			'containerPaths' => [
				'multipurge-foreign-public' => "$dir/public",
				'multipurge-foreign-thumb' => "$dir/thumb",
			],
		] );
		$repo = new ForeignAPIRepo( [
			'name' => 'multipurge-foreign',
			'class' => ForeignAPIRepo::class,
			'backend' => $backend,
			'url' => 'https://foreign.example/images',
			'thumbUrl' => 'https://foreign.example/images/thumb',
			'apibase' => 'https://foreign.example/w/api.php',
			'hashLevels' => 2,
		] );

		$title = Title::newFromText( 'ForeignTest.png', NS_FILE );
		$this->insertPage( 'ForeignTest.png', 'Foreign description', NS_FILE );

		$file = new ForeignAPIFile( $title, $repo, [
			'name' => 'ForeignTest.png',
			'size' => 1234,
			'mime' => 'image/png',
			'url' => 'https://foreign.example/images/f/fa/ForeignTest.png',
			'width' => 10,
			'height' => 10,
			'bits' => 8,
			'sha1' => str_repeat( '0', 40 ),
			'timestamp' => wfTimestamp( TS_MW, '20200101000000' ),
			'descriptionurl' => 'https://foreign.example/wiki/File:ForeignTest.png',
		], true );

		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
		$page->setFile( $file );

		return [ $page, $title, $file ];
	}

}
