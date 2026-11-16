<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests\Hooks;

use ArrayObject;
use Exception;
use JobQueueError;
use JobQueueGroup;
use JobQueueMemory;
use LocalFile;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Utils\UrlUtils;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RepoGroup;
use Wikimedia\EventRelayer\EventRelayerGroup;
use Wikimedia\Http\MultiHttpClient;

/**
 * @group MultiPurge
 * @group Database
 */
class PurgeHooksTest extends MediaWikiIntegrationTestCase {

	use MockHttpTrait;

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onArticlePurge
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::runPurge
	 * @return void
	 * @throws Exception
	 */
	public function testOnArticlePurgeFile() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
		] );

		$fileMock = $this->getMockBuilder( LocalFile::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getThumbnails', 'getThumbUrl', 'getUrl' ] )
			->getMock();
		$fileMock->method( 'getThumbnails' )->willReturn( [ 'mwbackend://foo/thumb' ] );
		$fileMock->method( 'getThumbUrl' )->willReturn( '/w/images/thumb/50px-Foo.jpg' );
		$fileMock->method( 'getUrl' )->willReturn( '/w/images/Foo.jpg' );

		$repoMock = $this->getMockBuilder( RepoGroup::class )->disableOriginalConstructor()->getMock();
		$repoMock->method( 'findFile' )->willReturn( $fileMock );
		$this->setService( 'RepoGroup', $repoMock );

		$captured = [];
		$mockQueue = $this->getMockBuilder( JobQueueGroup::class )->disableOriginalConstructor()->getMock();
		$mockQueue->expects( $this->once() )->method( 'push' )->willReturnCallback(
			static function ( $job ) use ( &$captured ): void {
				$captured[] = $job->getParams()['urls'];
			}
		);

		$title = Title::newFromText( 'Foo.jpg', NS_FILE );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$mockQueue,
			$this->getServiceContainer()->getResourceLoader(),
			$this->getServiceContainer()->getUrlUtils(),
		);

		$hooks->onArticlePurge( $page );
		DeferredUpdates::doUpdates();

		$this->assertSame( [ [ 'https://example.org/w/images/Foo.jpg' ] ], $captured );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onEditPage__attemptSave_after
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::runPurge
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::buildSiteModuleUrl
	 * @return void
	 * @throws Exception
	 */
	public function testOnEditPageAttemptSaveAfter() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
		] );

		$mockQueue = $this->getMockBuilder( JobQueueGroup::class )->disableOriginalConstructor()->getMock();
		$mockQueue->expects( $this->once() )->method( 'push' );

		$title = Title::newFromText( 'Foo.css', NS_MEDIAWIKI );

		$editPage = $this->getMockBuilder( EditPage::class )->disableOriginalConstructor()->getMock();
		$editPage->expects( $this->once() )->method( 'getTitle' )->willReturn( $title );

		$rlMock = $this->getMockBuilder( ResourceLoader::class )->disableOriginalConstructor()->getMock();
		$rlMock->expects( $this->once() )->method( 'createLoaderURL' )->willReturn( 'http://localhost/foo' );

		$utilsMock = $this->getMockBuilder( UrlUtils::class )->disableOriginalConstructor()->getMock();
		$utilsMock->expects( $this->once() )->method( 'expand' )->willReturn( 'http://localhost/foo' );

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$mockQueue,
			$rlMock,
			$utilsMock,
		);

		$hooks->onEditPage__attemptSave_after( $editPage, Status::newGood(), [] );
		DeferredUpdates::doUpdates();
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::factory
	 * @return void
	 */
	public function testFactory() {
		$services = $this->getServiceContainer();

		$hooks = PurgeHooks::factory(
			$services->getMainConfig(),
			$services->getJobQueueGroup(),
			$services->getResourceLoader(),
			$services->getUrlUtils()
		);

		$this->assertInstanceOf( PurgeHooks::class, $hooks );
	}

	/**
	 * An inline run has no queue retry of its own, so a failed service job must be enqueued immediately.
	 * The enqueue covers only that service and does not stop the services configured after it.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onArticlePurge
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::runPurge
	 * @return void
	 */
	public function testInlineFailureIsQueuedForRetryIndependently() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => false,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Varnish::class, Cloudflare::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );

		$sent = new ArrayObject();
		$multiMock = $this->getMockBuilder( MultiHttpClient::class )
			->disableOriginalConstructor()
			->getMock();
		$multiMock->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) use ( $sent ) {
				foreach ( $requests as $i => $request ) {
					$sent->append( $request );
					$failed = str_contains( $request['url'], 'varnish' );
					$request['response'] = [ $failed ? 500 : 200, null, [], null, null ];
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

		$fileMock = $this->getMockBuilder( LocalFile::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getThumbnails', 'getThumbUrl', 'getUrl' ] )
			->getMock();
		$fileMock->method( 'getThumbnails' )->willReturn( [ 'mwbackend://foo/thumb' ] );
		$fileMock->method( 'getThumbUrl' )->willReturn( '/w/images/thumb/50px-Foo.jpg' );
		$fileMock->method( 'getUrl' )->willReturn( 'https://example.org/foo' );

		$repoMock = $this->getMockBuilder( RepoGroup::class )->disableOriginalConstructor()->getMock();
		$repoMock->method( 'findFile' )->willReturn( $fileMock );
		$this->setService( 'RepoGroup', $repoMock );

		$group = $this->getServiceContainer()->getJobQueueGroup();

		$title = Title::newFromText( 'Foo.jpg', NS_FILE );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$group,
			$this->getServiceContainer()->getResourceLoader(),
			$this->getServiceContainer()->getUrlUtils(),
		);

		$hooks->onArticlePurge( $page );
		DeferredUpdates::doUpdates();

		$this->assertNotEmpty(
			array_filter(
				$sent->getArrayCopy(),
				static fn ( array $request ): bool => str_contains( $request['url'], 'cloudflare' )
			),
			'The service after the failing one was dispatched'
		);

		$queue = $group->get( 'MultiPurgePages' );
		$jobs = [];
		$job = $queue->pop();
		while ( $job ) {
			$jobs[] = $job;
			$job = $queue->pop();
		}

		$this->assertCount( 1, $jobs, 'Only the failed service was queued for retry' );
		$this->assertSame( Varnish::class, $jobs[0]->getParams()['service'] );
		$this->assertSame( [ 'https://example.org/foo' ], $jobs[0]->getParams()['urls'] );
	}

	/**
	 * Protocol-relative thumbnail URLs keep their own authority instead of being folded into $wgServer.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onArticlePurge
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::linkThumbnails
	 * @dataProvider provideFilePurgePaths
	 */
	public function testArticlePurgeFilePreservesRepositoryUrls( string $fileUrl, string $expected ): void {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'Server' => 'https://wiki.example',
			'MultiPurgeAllowedHosts' => [ 'static.example' ],
			'UploadPath' => 'https://static.example/uploads',
		] );

		$fileMock = $this->getMockBuilder( LocalFile::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getThumbnails', 'getThumbUrl', 'getUrl' ] )
			->getMock();
		// Real getThumbnails returns the thumb directory first, which the hook shifts off.
		$fileMock->method( 'getThumbnails' )->willReturn( [ 'mwbackend://foo/thumb', '50px-Foo.jpg' ] );
		$fileMock->method( 'getThumbUrl' )->willReturnCallback(
			static fn ( string $name ): string => "//static.example/w/images/thumb/$name"
		);
		$fileMock->method( 'getUrl' )->willReturn( $fileUrl );

		$repoMock = $this->getMockBuilder( RepoGroup::class )->disableOriginalConstructor()->getMock();
		$repoMock->method( 'findFile' )->willReturn( $fileMock );
		$this->setService( 'RepoGroup', $repoMock );

		$captured = [];
		$mockQueue = $this->getMockBuilder( JobQueueGroup::class )->disableOriginalConstructor()->getMock();
		$mockQueue->method( 'push' )->willReturnCallback(
			static function ( $job ) use ( &$captured ): void {
				$captured[] = $job->getParams()['urls'];
			}
		);

		$title = Title::newFromText( 'Foo.jpg', NS_FILE );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$mockQueue,
			$this->getServiceContainer()->getResourceLoader(),
			$this->getServiceContainer()->getUrlUtils(),
		);

		$hooks->onArticlePurge( $page );
		DeferredUpdates::doUpdates();

		$this->assertSame( [
			[ 'https://static.example/w/images/thumb/50px-Foo.jpg', $expected ],
		], $captured );
	}

	public static function provideFilePurgePaths(): array {
		return [
			'root-relative URL belongs to the wiki' => [ '/w/images/Foo.jpg', 'https://wiki.example/w/images/Foo.jpg' ],
			'bare path keeps the upload base' => [ 'images/Foo.jpg', 'https://static.example/uploads/images/Foo.jpg' ],
		];
	}

	/**
	 * A rejected thumbnail URL must never reach the job parameters the queue serializes.
	 * A failed insert can echo them in the exception the hook logs.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onArticlePurge
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::runPurge
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::filterDispatchUrls
	 * @return void
	 */
	public function testQueuedFilePurgeNeverSerializesRejectedUrls() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'Server' => 'https://wiki.example',
			'UploadPath' => '/w/images',
		] );

		$fileMock = $this->getMockBuilder( LocalFile::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getThumbnails', 'getThumbUrl', 'getUrl' ] )
			->getMock();
		$fileMock->method( 'getThumbnails' )->willReturn( [ 'mwbackend://foo/thumb', '50px-Foo.jpg' ] );
		$fileMock->method( 'getThumbUrl' )->willReturn( 'https://user:secret@evil.example/thumb' );
		$fileMock->method( 'getUrl' )->willReturn( '/w/images/Foo.jpg' );

		$repoMock = $this->getMockBuilder( RepoGroup::class )->disableOriginalConstructor()->getMock();
		$repoMock->method( 'findFile' )->willReturn( $fileMock );
		$this->setService( 'RepoGroup', $repoMock );

		$captured = [];
		$mockQueue = $this->getMockBuilder( JobQueueGroup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'push' ] )
			->getMock();
		$mockQueue->method( 'push' )->willReturnCallback(
			static function ( $job ) use ( &$captured ): void {
				$captured[] = $job->getParams();
				throw new JobQueueError(
					'DBQueryError: INSERT INTO job (job_params) VALUES ('
					. serialize( $job->getParams() ) . ')'
				);
			}
		);

		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );

		$title = Title::newFromText( 'Foo.jpg', NS_FILE );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$mockQueue,
			$this->getServiceContainer()->getResourceLoader(),
			$this->getServiceContainer()->getUrlUtils(),
		);

		$hooks->onArticlePurge( $page );
		DeferredUpdates::doUpdates();

		$this->assertNotEmpty( $captured, 'The valid target was still enqueued' );
		foreach ( $captured as $params ) {
			$this->assertSame( [ 'https://wiki.example/w/images/Foo.jpg' ], $params['urls'] );
		}
		foreach ( $entries as $entry ) {
			$this->assertStringNotContainsString( 'secret', $entry, 'Rejected input never reaches the log' );
		}
	}

	/**
	 * A thumbnail URL with any syntactically present scheme must reach the policy unchanged.
	 * Prefixing $wgServer would fold "javascript:..." into an allowed local path.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onArticlePurge
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::linkThumbnails
	 * @return void
	 */
	public function testArticlePurgeFileKeepsNonHierarchicalSchemeForPolicy() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'Server' => 'https://wiki.example',
			'UploadPath' => '/w/images',
		] );

		$fileMock = $this->getMockBuilder( LocalFile::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getThumbnails', 'getThumbUrl', 'getUrl' ] )
			->getMock();
		// Real getThumbnails returns the thumb directory first, which the hook shifts off.
		$fileMock->method( 'getThumbnails' )->willReturn( [ 'mwbackend://foo/thumb', '50px-Foo.jpg' ] );
		$fileMock->method( 'getThumbUrl' )->willReturn( 'javascript:alert(1)' );
		$fileMock->method( 'getUrl' )->willReturn( '/w/images/Foo.jpg' );

		$repoMock = $this->getMockBuilder( RepoGroup::class )->disableOriginalConstructor()->getMock();
		$repoMock->method( 'findFile' )->willReturn( $fileMock );
		$this->setService( 'RepoGroup', $repoMock );

		$captured = [];
		$mockQueue = $this->getMockBuilder( JobQueueGroup::class )->disableOriginalConstructor()->getMock();
		$mockQueue->method( 'push' )->willReturnCallback(
			static function ( $job ) use ( &$captured ): void {
				$captured[] = $job->getParams()['urls'];
			}
		);

		$title = Title::newFromText( 'Foo.jpg', NS_FILE );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$mockQueue,
			$this->getServiceContainer()->getResourceLoader(),
			$this->getServiceContainer()->getUrlUtils(),
		);

		$hooks->onArticlePurge( $page );
		DeferredUpdates::doUpdates();

		$this->assertSame( [
			[ 'https://wiki.example/w/images/Foo.jpg' ],
		], $captured, 'A non-HTTP scheme never reaches the queued job parameters' );
	}

	/**
	 * In queue mode a rejected enqueue must not escape the hook, hide the failure, or stop later services.
	 * The read-only queue makes each real immediate enqueue reject the job.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onArticlePurge
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::runPurge
	 * @return void
	 */
	public function testQueuedRejectedEnqueueIsLoggedWithoutSuppressingLaterServices() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Varnish::class, Cloudflare::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [
			'default' => [ 'class' => JobQueueMemory::class, 'readOnlyReason' => 'test queue read-only' ],
		] );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );

		$fileMock = $this->getMockBuilder( LocalFile::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getThumbnails', 'getThumbUrl', 'getUrl' ] )
			->getMock();
		$fileMock->method( 'getThumbnails' )->willReturn( [ 'mwbackend://foo/thumb' ] );
		$fileMock->method( 'getThumbUrl' )->willReturn( '/w/images/thumb/50px-Foo.jpg' );
		$fileMock->method( 'getUrl' )->willReturn( 'https://example.org/foo' );

		$repoMock = $this->getMockBuilder( RepoGroup::class )->disableOriginalConstructor()->getMock();
		$repoMock->method( 'findFile' )->willReturn( $fileMock );
		$this->setService( 'RepoGroup', $repoMock );

		$logger = $this->createMock( LoggerInterface::class );
		// Both services must be attempted: the first rejection must not end the loop.
		$logger->expects( $this->exactly( 2 ) )->method( 'error' );
		$this->setLogger( 'MultiPurge', $logger );

		$title = Title::newFromText( 'Foo.jpg', NS_FILE );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$this->getServiceContainer()->getJobQueueGroupFactory()->makeJobQueueGroup(),
			$this->getServiceContainer()->getResourceLoader(),
			$this->getServiceContainer()->getUrlUtils(),
		);

		// The rejected enqueues must not throw out of the hook.
		$hooks->onArticlePurge( $page );
		DeferredUpdates::doUpdates();
	}

	/**
	 * An EventRelayerGroup without a usable entry cannot resolve any relayer, which must not
	 * escape the hook as a new fatal; the hook falls back to direct title dispatch instead.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onArticlePurge
	 * @return void
	 */
	public function testFallbackTitleDispatchWhenRelayerCannotBeResolved(): void {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
		] );
		$this->getServiceContainer()->redefineService( 'EventRelayerGroup',
			static fn () => new EventRelayerGroup( [] )
		);

		$captured = [];
		$mockQueue = $this->getMockBuilder( JobQueueGroup::class )
			->disableOriginalConstructor()
			->getMock();
		$mockQueue->method( 'push' )->willReturnCallback(
			static function ( $job ) use ( &$captured ): void {
				$captured[] = $job->getParams()['urls'];
			}
		);

		$title = Title::newFromText( 'RelayerResolutionFailurePage' );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$mockQueue,
			$this->getServiceContainer()->getResourceLoader(),
			$this->getServiceContainer()->getUrlUtils(),
		);

		$hooks->onArticlePurge( $page );
		DeferredUpdates::doUpdates();

		$this->assertSame( [
			[ $title->getInternalURL(), $title->getInternalURL( 'action=history' ) ],
		], $captured, 'The hook fell back to direct title dispatch without a fatal' );
	}

	/**
	 * Queued jobs must not become visible to workers before the page invalidation of the purge has committed.
	 * This test drives the real WikiPage::doPurge() path, which calls ArticlePurge before updating page_touched.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onArticlePurge
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::runPurge
	 * @return void
	 */
	public function testQueuedJobsAreEnqueuedAfterPageInvalidation() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'Server' => 'https://example.org',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
		] );

		$fileMock = $this->getMockBuilder( LocalFile::class )
			->disableOriginalConstructor()
			->onlyMethods( [
				'exists', 'getName', 'purgeCache', 'getHistory',
				'getThumbnails', 'getThumbUrl', 'getUrl',
			] )
			->getMock();
		$fileMock->method( 'exists' )->willReturn( true );
		$fileMock->method( 'getName' )->willReturn( 'MultiPurgeOrderingTest.jpg' );
		$fileMock->method( 'getHistory' )->willReturn( [] );
		$fileMock->method( 'getThumbnails' )->willReturn( [ 'mwbackend://foo/thumb' ] );
		$fileMock->method( 'getThumbUrl' )->willReturn( '/w/images/thumb/50px-Foo.jpg' );
		$fileMock->method( 'getUrl' )->willReturn( '/w/images/Foo.jpg' );

		$repoMock = $this->getMockBuilder( RepoGroup::class )->disableOriginalConstructor()->getMock();
		$repoMock->method( 'findFile' )->willReturn( $fileMock );
		$this->setService( 'RepoGroup', $repoMock );

		$dbw = $this->getDb( DB_PRIMARY );
		$created = $this->insertPage( 'MultiPurgeOrderingTest.jpg', '', NS_FILE );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $created['title'] );

		// Backdate page_touched so the invalidation writes a clearly newer value.
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
				$pushed[] = $dbw->selectField(
					'page', 'page_touched', [ 'page_id' => $pageId ], 'PurgeHooksTest-pushSpy'
				);
			}
		);

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$group,
			$this->getServiceContainer()->getResourceLoader(),
			$this->getServiceContainer()->getUrlUtils(),
		);
		$this->setTemporaryHook( 'ArticlePurge', [ $hooks, 'onArticlePurge' ] );

		// An open transaction keeps deferred updates from running early, like a web request.
		$dbw->startAtomic( __METHOD__ );
		try {
			$page->doPurge();
			$this->assertSame(
				[],
				$pushed,
				'Jobs must not be enqueued while the invalidation transaction is still open'
			);
		} finally {
			$dbw->endAtomic( __METHOD__ );
		}

		DeferredUpdates::doUpdates();

		$this->assertCount( 1, $pushed, 'The service job was enqueued after the purge returned' );
		$this->assertGreaterThan(
			'20000101000000',
				$pushed[0],
				'The job was enqueued after the page invalidation committed'
			);
	}

}
