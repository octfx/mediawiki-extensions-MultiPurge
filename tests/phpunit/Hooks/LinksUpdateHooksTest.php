<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests\Hooks;

use MediaWiki\Cache\HTMLCacheUpdater;
use MediaWiki\Config\HashConfig;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Extension\MultiPurge\Hooks\LinksUpdateHooks;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\ParserCache;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiPage;

/**
 * @group MultiPurge
 * @group Database
 * @covers \MediaWiki\Extension\MultiPurge\Hooks\LinksUpdateHooks
 */
class LinksUpdateHooksTest extends MediaWikiIntegrationTestCase {

	private const TEMPLATE = 'Template:MultiPurgeWarmTest';
	private const PAGE = 'MultiPurgeWarmTestPage';

	/** @var string[] DB keys of the pages purged through HTMLCacheUpdater */
	private array $purged = [];

	protected function tearDown(): void {
		ConvertibleTimestamp::setFakeTime( false );
		parent::tearDown();
	}

	private function edit( string $title, string $text ): void {
		$this->assertStatusGood( $this->editPage( $title, $text ) );
		DeferredUpdates::doUpdates();
	}

	private function cachedOutput(): ?ParserOutput {
		$services = $this->getServiceContainer();
		$page = $services->getWikiPageFactory()->newFromTitle( Title::newFromText( self::PAGE ) );
		return $services->getParserCache()->get( $page, ParserOptions::newFromAnon() ) ?: null;
	}

	private function cachedText(): ?string {
		$output = $this->cachedOutput();
		return $output ? $output->getContentHolderText() : null;
	}

	private function touched(): string {
		return $this->getServiceContainer()->getWikiPageFactory()
			->newFromTitle( Title::newFromText( self::PAGE ) )->getTouched();
	}

	/**
	 * Renders the page the way a page view does, which saves the render to the parser cache
	 */
	private function viewPage( int $options = 0 ): void {
		$services = $this->getServiceContainer();
		$page = $services->getWikiPageFactory()->newFromTitle( Title::newFromText( self::PAGE ) );
		$parserOptions = ParserOptions::newFromAnon();
		$parserOptions->setRenderReason( 'page_view' );
		$this->assertStatusGood(
			$services->getParserOutputAccess()->getParserOutput( $page, $parserOptions, null, $options )
		);
	}

	/**
	 * Creates a page that uses a template, then edits the template, leaving the cascade's jobs queued.
	 * Core's jobs read the real clock, so the edits are made in the past.
	 *
	 * @param bool $enabled
	 * @param array $config Extra config overrides
	 */
	private function setUpCascade( bool $enabled, array $config = [] ): void {
		$this->overrideConfigValues( [
			MainConfigNames::UseCdn => true,
			MainConfigNames::UseInstantCommons => false,
			// Core sets this to LocalSettings.php's mtime on every bootstrap (see
			// includes/SetupDynamicConfig.php), which would otherwise be newer than this test's
			// fake past timestamps and make ParserCache::get() treat every entry as expired.
			MainConfigNames::CacheEpoch => '20000101000000',
			// This test runs within about a second of real time, and WANObjectCache tombstones
			// have one-second resolution, so an interim value for the template's LinkCache row
			// written just before the edit's purge would still count as fresh; CACHE_NONE avoids
			// that test-only artefact.
			MainConfigNames::MainCacheType => CACHE_NONE,
			// MW 1.47 renders links updates with Parsoid by default; follow the parser page views use,
			// as earlier versions always do
			'UseParsoidLinksUpdate' => null,
			'MultiPurgeEnabledServices' => [],
			'MultiPurgeWarmParserCacheOnRefreshLinks' => $enabled,
			...$config,
		] );
		// Record purges with a stand-in: MW 1.46's test framework replaces the real service with a no-op
		$cacheUpdater = $this->createMock( HTMLCacheUpdater::class );
		$cacheUpdater->method( 'purgeTitleUrls' )->willReturnCallback( function ( $pages ) {
			foreach ( is_iterable( $pages ) ? $pages : [ $pages ] as $page ) {
				$this->purged[] = $page->getDBkey();
			}
		} );
		$this->setService(
			version_compare( MW_VERSION, '1.46', '<' ) ? 'HtmlCacheUpdater' : 'HTMLCacheUpdater',
			$cacheUpdater
		);

		$now = time();
		ConvertibleTimestamp::setFakeTime( $now - 3600 );
		$this->edit( self::TEMPLATE, 'old template text' );
		$this->edit( self::PAGE, '{{MultiPurgeWarmTest}}' );
		$this->runJobs( [ 'minJobs' => 0 ] );
		$this->assertStringContainsString( 'old template text', $this->cachedText() ?? '' );

		ConvertibleTimestamp::setFakeTime( $now - 1800 );
		$this->edit( self::TEMPLATE, 'new template text' );
		ConvertibleTimestamp::setFakeTime( false );
		$this->purged = [];
	}

	public function testWarmsWhenRefreshLinksRunsFirst(): void {
		$this->setUpCascade( true );

		$this->runJobs( [], [ 'type' => 'refreshLinks' ] );

		$this->assertStringContainsString( 'new template text', $this->cachedText() ?? '' );
		$this->assertStringContainsString(
			'Rendering was triggered because: multipurge-warmup',
			$this->cachedOutput()->getCacheMessage()
		);
		$this->assertContains( self::PAGE, $this->purged );

		$this->runJobs( [ 'minJobs' => 0 ] );

		$this->assertStringContainsString( 'new template text', $this->cachedText() ?? '' );
	}

	public function testWarmsWhenHtmlCacheUpdateRunsFirst(): void {
		$this->setUpCascade( true );

		$this->runJobs( [], [ 'type' => 'htmlCacheUpdate' ] );

		$this->assertNull( $this->cachedText() );

		$this->runJobs( [], [ 'type' => 'refreshLinks' ] );

		$this->assertStringContainsString( 'new template text', $this->cachedText() ?? '' );
	}

	public function testLeavesRenderReusedFromParserCacheToCore(): void {
		$this->setUpCascade( true );
		$this->runJobs( [], [ 'type' => 'htmlCacheUpdate' ] );
		// A reader views the page after htmlCacheUpdate invalidated it, so RefreshLinksJob reuses that render
		ConvertibleTimestamp::setFakeTime( time() + 60 );
		$this->viewPage();
		ConvertibleTimestamp::setFakeTime( false );
		$touched = $this->touched();
		$this->purged = [];

		$this->runJobs( [], [ 'type' => 'refreshLinks' ] );

		$this->assertStringContainsString( 'new template text', $this->cachedText() ?? '' );
		$this->assertStringNotContainsString( 'multipurge-warmup', $this->cachedOutput()->getCacheMessage() );
		$this->assertNotContains( self::PAGE, $this->purged );
		$this->assertSame( $touched, $this->touched() );
	}

	public function testLeavesPageMissingFromParserCacheToCore(): void {
		$this->setUpCascade( true );
		// Evicted, as happens to rarely read pages once the parser cache is full
		$services = $this->getServiceContainer();
		$services->getParserCache()->deleteOptionsKey(
			$services->getWikiPageFactory()->newFromTitle( Title::newFromText( self::PAGE ) )
		);
		$touched = $this->touched();

		$this->runJobs( [], [ 'type' => 'refreshLinks' ] );

		$this->assertNull( $this->cachedOutput() );
		$this->assertNotContains( self::PAGE, $this->purged );
		$this->assertSame( $touched, $this->touched() );
	}

	public function testWarmsWhenParserCacheHoldsAnotherRender(): void {
		$this->setUpCascade( true );
		// A reader's newer render lands in the parser cache while the cascade's render is in progress
		$this->setTemporaryHook( 'LinksUpdate', function ( $linksUpdate ) {
			if ( $linksUpdate->getTitle()->getPrefixedText() !== self::PAGE ) {
				return;
			}
			ConvertibleTimestamp::setFakeTime( time() + 60 );
			$this->viewPage( ParserOutputAccess::OPT_FORCE_PARSE );
			ConvertibleTimestamp::setFakeTime( false );
		} );

		$this->runJobs( [], [ 'type' => 'refreshLinks' ] );

		$this->assertStringContainsString(
			'Rendering was triggered because: multipurge-warmup',
			$this->cachedOutput()->getCacheMessage()
		);
		$this->assertContains( self::PAGE, $this->purged );
	}

	public function testDoesNotKeepRenderSupersededDuringParse(): void {
		$this->setUpCascade( true );

		$renderStart = time() + 60;
		ConvertibleTimestamp::setFakeTime( $renderStart );
		// Something invalidates the page after its render has started
		$caller = __METHOD__;
		$this->setTemporaryHook( 'LinksUpdate', function ( $linksUpdate ) use ( $renderStart, $caller ) {
			if ( $linksUpdate->getTitle()->getPrefixedText() !== self::PAGE ) {
				return;
			}
			$dbw = $this->getDb();
			$dbw->newUpdateQueryBuilder()
				->update( 'page' )
				->set( [ 'page_touched' => $dbw->timestamp( $renderStart + 1 ) ] )
				->where( [ 'page_id' => $linksUpdate->getPageId() ] )
				->caller( $caller )
				->execute();
			ConvertibleTimestamp::setFakeTime( $renderStart + 2 );
		} );

		$this->runJobs( [], [ 'type' => 'refreshLinks' ] );

		$this->assertNull( $this->cachedText() );
	}

	public function testLeavesRenderFromAnotherParserToCore(): void {
		if ( version_compare( MW_VERSION, '1.47', '<' ) ) {
			$this->markTestSkipped( 'Before MW 1.47, RefreshLinksJob always uses the parser page views use' );
		}
		// Page views use the legacy parser, links updates Parsoid
		$this->setUpCascade( true, [ 'UseParsoidLinksUpdate' => true ] );
		$touched = $this->touched();

		$this->runJobs( [], [ 'type' => 'refreshLinks' ] );

		$this->assertStringContainsString( 'old template text', $this->cachedText() ?? '' );
		$this->assertNotContains( self::PAGE, $this->purged );
		$this->assertSame( $touched, $this->touched() );
	}

	public function testCoreDiscardsRenderWhenDisabled(): void {
		$this->setUpCascade( false );

		$this->runJobs();

		$this->assertNull( $this->cachedText() );
	}

	/**
	 * @dataProvider provideSkippedUpdates
	 */
	public function testSkipsDisabledRecursiveAndOpportunisticUpdates(
		bool $enabled,
		bool $recursive,
		string $cause
	): void {
		$parserCache = $this->createMock( ParserCache::class );
		$parserCache->expects( $this->never() )->method( 'save' );
		$cacheUpdater = $this->createMock( HTMLCacheUpdater::class );
		$cacheUpdater->expects( $this->never() )->method( 'purgeTitleUrls' );
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->expects( $this->never() )->method( 'newFromID' );
		$linksUpdate = $this->createMock( LinksUpdate::class );
		$linksUpdate->method( 'isRecursive' )->willReturn( $recursive );
		$linksUpdate->method( 'getCauseAction' )->willReturn( $cause );

		$hooks = new LinksUpdateHooks(
			new HashConfig( [ 'MultiPurgeWarmParserCacheOnRefreshLinks' => $enabled ] ),
			$parserCache,
			$wikiPageFactory,
			$this->createMock( IConnectionProvider::class ),
			$cacheUpdater
		);
		$hooks->onLinksUpdateComplete( $linksUpdate, null );
	}

	public static function provideSkippedUpdates(): array {
		return [
			'option disabled' => [ false, false, 'edit-page' ],
			'recursive update of the edited page' => [ true, true, 'edit-page' ],
			'opportunistic refresh from a page view' => [ true, false, 'RefreshLinksJob' ],
		];
	}

	public function testLogsWarmUpFailuresAsErrors(): void {
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getId' )->willReturn( 5 );
		$page = $this->createMock( WikiPage::class );
		$page->method( 'getId' )->willReturn( 3 );
		$page->method( 'getLatest' )->willReturn( 5 );
		$page->method( 'getTouched' )->willReturn( '20260101000000' );
		$page->method( 'makeParserOptions' )->willReturn( ParserOptions::newFromAnon() );
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $page );

		$output = new ParserOutput( 'text' );
		$output->setCacheTime( '20260102000000' );
		$linksUpdate = $this->createMock( LinksUpdate::class );
		$linksUpdate->method( 'isRecursive' )->willReturn( false );
		$linksUpdate->method( 'getCauseAction' )->willReturn( 'edit-page' );
		$linksUpdate->method( 'getPageId' )->willReturn( 3 );
		$linksUpdate->method( 'getRevisionRecord' )->willReturn( $revision );
		$linksUpdate->method( 'getParserOutput' )->willReturn( $output );

		$parserCache = $this->createMock( ParserCache::class );
		$parserCache->method( 'getDirty' )->willReturn( new ParserOutput( 'old text' ) );
		$parserCache->method( 'save' )->willThrowException( new RuntimeException( 'Cache is down' ) );
		$cacheUpdater = $this->createMock( HTMLCacheUpdater::class );
		$cacheUpdater->expects( $this->never() )->method( 'purgeTitleUrls' );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'error' )->with(
			$this->stringContains( 'Could not warm parser cache' ),
			$this->callback( static fn ( $context ) => $context['exception'] instanceof RuntimeException )
		);
		$this->setLogger( 'MultiPurge', $logger );

		$hooks = new LinksUpdateHooks(
			new HashConfig( [ 'MultiPurgeWarmParserCacheOnRefreshLinks' => true ] ),
			$parserCache,
			$wikiPageFactory,
			$this->createMock( IConnectionProvider::class ),
			$cacheUpdater
		);
		$hooks->onLinksUpdateComplete( $linksUpdate, null );
	}
}
