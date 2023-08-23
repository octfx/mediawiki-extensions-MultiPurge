<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests\Hooks;

use Exception;
use HtmlCacheUpdater;
use JobQueueGroup;
use LocalFile;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Title\Title;
use MediaWiki\Utils\UrlUtils;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use RepoGroup;
use Status;

/**
 * @group MultiPurge
 */
class PurgeHooksTest extends MediaWikiIntegrationTestCase {

	use MockHttpTrait;

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onLocalFilePurgeThumbnails
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::runPurge
	 * @return void
	 * @throws Exception
	 */
	public function testLocalFilePurgeThumbnails() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
		] );

		$mockQueue = $this->getMockBuilder( JobQueueGroup::class )->disableOriginalConstructor()->getMock();
		$mockQueue->expects( $this->once() )->method( 'lazyPush' );

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$this->getServiceContainer()->getHtmlCacheUpdater(),
			$mockQueue,
			$this->getServiceContainer()->getResourceLoader(),
			$this->getServiceContainer()->getUrlUtils(),
		);

		$hooks->onLocalFilePurgeThumbnails( null, '', [ '' ] );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onArticlePurge
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::runPurge
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::buildSiteModuleUrl
	 * @return void
	 * @throws Exception
	 */
	public function testOnArticlePurge() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
		] );

		$mockQueue = $this->getMockBuilder( JobQueueGroup::class )->disableOriginalConstructor()->getMock();
		$mockQueue->expects( $this->once() )->method( 'lazyPush' );

		$title = Title::newFromText( 'Foo' );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$cacheMock = $this->getMockBuilder( HtmlCacheUpdater::class )->disableOriginalConstructor()->getMock();
		$cacheMock->expects( $this->once() )->method( 'getUrls' )->willReturn( [ 'http://localhost/foo' ] );

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$cacheMock,
			$mockQueue,
			$this->getServiceContainer()->getResourceLoader(),
			$this->getServiceContainer()->getUrlUtils(),
		);

		$hooks->onArticlePurge( $page );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::onArticlePurge
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::runPurge
	 * @covers \MediaWiki\Extension\MultiPurge\Hooks\PurgeHooks::buildSiteModuleUrl
	 * @return void
	 * @throws Exception
	 */
	public function testOnArticlePurgeFile() {
		$this->overrideConfigValues( [
			'MultiPurgeRunInQueue' => true,
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
		] );

		$fileMock = $this->getMockBuilder( LocalFile::class )->disableOriginalConstructor()->getMock();
		$repoMock = $this->getMockBuilder( RepoGroup::class )->disableOriginalConstructor()->getMock();
		$repoMock->method( 'findFile' )->willReturn( $fileMock );
		$this->setMwGlobals( 'RepoGroup', $repoMock );

		$mockQueue = $this->getMockBuilder( JobQueueGroup::class )->disableOriginalConstructor()->getMock();
		$mockQueue->expects( $this->once() )->method( 'lazyPush' );

		$title = Title::newFromText( 'Foo.jpg', NS_FILE );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$this->getServiceContainer()->getHtmlCacheUpdater(),
			$mockQueue,
			$this->getServiceContainer()->getResourceLoader(),
			$this->getServiceContainer()->getUrlUtils(),
		);

		$hooks->onArticlePurge( $page );
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
		$mockQueue->expects( $this->once() )->method( 'lazyPush' );

		$title = Title::newFromText( 'Foo.css', NS_MEDIAWIKI );

		$editPage = $this->getMockBuilder( EditPage::class )->disableOriginalConstructor()->getMock();
		$editPage->expects( $this->once() )->method( 'getTitle' )->willReturn( $title );

		$rlMock = $this->getMockBuilder( ResourceLoader::class )->disableOriginalConstructor()->getMock();
		$rlMock->expects( $this->once() )->method( 'createLoaderURL' )->willReturn( 'http://localhost/foo' );

		$utilsMock = $this->getMockBuilder( UrlUtils::class )->disableOriginalConstructor()->getMock();
		$utilsMock->expects( $this->once() )->method( 'expand' )->willReturn( 'http://localhost/foo' );

		$hooks = new PurgeHooks(
			$this->getServiceContainer()->getMainConfig(),
			$this->getServiceContainer()->getHtmlCacheUpdater(),
			$mockQueue,
			$rlMock,
			$utilsMock,
		);

		$hooks->onEditPage__attemptSave_after( $editPage, Status::newGood(), [] );
	}

}
