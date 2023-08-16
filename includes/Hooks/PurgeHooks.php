<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Hooks;

use Article;
use Config;
use EditPage;
use Exception;
use File;
use HtmlCacheUpdater;
use JobQueueGroup;
use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Extension\MultiPurge\PurgeEventRelayer;
use MediaWiki\Hook\EditPage__attemptSave_afterHook;
use MediaWiki\Hook\LocalFilePurgeThumbnailsHook;
use MediaWiki\Page\Hook\ArticlePurgeHook;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\DerivativeContext;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Utils\UrlUtils;
use ReflectionException;
use ReflectionObject;
use RequestContext;
use Status;
use Title;
use WikiFilePage;
use WikiPage;

class PurgeHooks implements LocalFilePurgeThumbnailsHook, ArticlePurgeHook, EditPage__attemptSave_afterHook {

	private Config $config;
	private HtmlCacheUpdater $cacheUpdater;
	private JobQueueGroup $group;
	private ResourceLoader $rl;
	private UrlUtils $utils;

	public function __construct( Config $config, HtmlCacheUpdater $cacheUpdater, JobQueueGroup $group, ResourceLoader $rl, UrlUtils $utils ) {
		$this->config = $config;
		$this->cacheUpdater = $cacheUpdater;
		$this->group = $group;
		$this->rl = $rl;
		$this->utils = $utils;
	}

	/**
	 * Retrieve a list of thumbnail URLs that needs to be purged
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LocalFilePurgeThumbnails
	 *
	 * @param File $file The File of which the thumbnails are being purged
	 * @param string $archiveName Name of an old file version or false if it's the current one
	 * @param string[] $urls Array of URLs to purge from the caches, to be manipulated
	 */
	public function onLocalFilePurgeThumbnails( $file, $archiveName, $urls ): void {
		$this->runPurge( $urls );
	}

	/**
	 * @param WikiPage $wikiPage
	 * @return void
	 */
	public function onArticlePurge( $wikiPage ): void {
		if ( $this->isFile( $wikiPage ) ) {
			$files = $this->getThumbnails( $wikiPage->getFile() );
			// Remove mwbackend link
			array_shift( $files );
			$urls = $this->linkThumbnails( $files, $wikiPage->getFile() );
		} elseif ( $wikiPage->getTitle() === null ) {
			return;
		} else {
			$urls = $this->cacheUpdater->getUrls( $wikiPage->getTitle() );
		}

		$this->buildSiteModuleUrl( $wikiPage->getTitle(), $urls );

		$this->runPurge( $urls );
	}

	/**
	 * This is only here to purge site styles
	 * Every other url is handled through PurgeEventRelayer
	 *
	 * @see PurgeEventRelayer
	 * @param EditPage $editpage_Obj
	 * @param Status $status
	 * @param $resultDetails
	 * @return void
	 */
	public function onEditPage__attemptSave_after( $editpage_Obj, $status, $resultDetails ) {
		if ( $status->isGood() ) {
			$urls = [];
			$this->buildSiteModuleUrl( $editpage_Obj->getTitle(), $urls );

			$this->runPurge( $urls );
		}
	}

	/**
	 * Returns an array of thumbnail urls for this wiki file
	 * This is an evil hack if you are on <= 1.35.2 as we change the visibility of the method to public
	 *
	 * @param File|WikiFilePage $page
	 * @return array
	 */
	private function getThumbnails( $page ): array {
		if ( $page instanceof File ) {
			$file = $page;
		} else {
			$file = $page->getFile();
		}

		if ( $file === false || !method_exists( $file, 'getThumbnails' ) ) {
			return [];
		}

		$refObject = new ReflectionObject( $file );
		try {
			$refMethod = $refObject->getMethod( 'getThumbnails' );
		} catch ( ReflectionException $e ) {
			return [];
		}

		if ( $refMethod->isPublic() ) {
			return $file->getThumbnails();
		}

		try {
			$refMethod->setAccessible( true );
			$thumbnails = $refMethod->invoke( $file );
		} catch ( ReflectionException $e ) {
			$thumbnails = [];
		}

		return $thumbnails;
	}

	/**
	 * Links relative urls to absolute urls based on wgServer or wgUploadPath
	 *
	 * @param array $files
	 * @param File $baseFile
	 * @return array
	 */
	private function linkThumbnails( array $files, File $baseFile ): array {
		$url = $this->config->get( 'Server' );
		$uploadPath = $this->config->get( 'UploadPath' );
		$parsed = parse_url( $uploadPath );

		if ( $parsed !== false && isset( $parsed['host'] ) ) {
			$url = $uploadPath;
		}

		// Purge the CDN
		$urls = [];
		foreach ( $files as $thumb ) {
			$thumbUrl = ltrim( $baseFile->getThumbUrl( $thumb ), '/' );
			if ( isset( parse_url( $thumbUrl )['host'] ) ) {
				$urls[] = $thumbUrl;
			} else {
				$urls[] = sprintf(
					'%s/%s',
					$url,
					ltrim( $baseFile->getThumbUrl( $thumb ), '/' )
				);
			}

		}

		if ( isset( parse_url( $baseFile->getUrl() )['host'] ) ) {
			$urls[] = $baseFile->getUrl();
		} else {
			$urls[] = sprintf(
				'%s/%s',
				$url,
				ltrim( $baseFile->getUrl(), '/' )
			);
		}

		return $urls;
	}

	/**
	 * Purges an array of urls
	 *
	 * @param array $urls
	 */
	private function runPurge( array $urls ): void {
		$urls = array_unique( $urls );

		if ( empty( $urls ) ) {
			return;
		}

		wfDebugLog( 'MultiPurge', 'Running Job from PurgeHooks' );

		foreach ( MultiPurgeJob::getServiceOrder() as $service ) {
			$job = new MultiPurgeJob( [
				'urls' => array_unique( $urls ),
				'service' => $service,
			] );

			if ( $this->config->get( 'MultiPurgeRunInQueue' ) === true ) {
				$this->group->lazyPush( $job );
			} else {
				try {
					$status = $job->run();
				} catch ( Exception $e ) {
					$status = false;
				}
				wfDebugLog(
					'MultiPurge',
					sprintf(
						'Job Status: %s',
						( $status === true ? 'success' : 'error' )
					)
				);
			}
		}
	}

	/**
	 * @param Article|WikiPage|Title $page
	 * @return bool
	 */
	private function isFile( $page ): bool {
		if ( $page instanceof Title ) {
			return $page->getNamespace() === NS_FILE;
		}

		return $page instanceof WikiFilePage && $page->getTitle() !== null && $page->getTitle()->getNamespace() === NS_FILE;
	}

	/**
	 * This manually builds the url for the site.styles module
	 * Gets called after an edit to a MediaWiki:*.css page
	 *
	 * @param Title $title
	 * @param array $urls
	 * @return void
	 */
	private function buildSiteModuleUrl( Title $title, array &$urls ): void {
		if ( $title->getNamespace() !== NS_MEDIAWIKI || substr( $title->getText(), -4 ) !== '.css' ) {
			return;
		}

		$request = RequestContext::getMain();

		$rlContext = new Context(
			$this->rl,
			$request->getRequest()
		);

		$derive = new DerivativeContext( $rlContext );
		$derive->setModules( [ 'site.styles' ] );
		$derive->setLanguage( $this->config->get( 'LanguageCode' ) );
		$derive->setSkin( $request->getSkin()->getSkinName() );
		$derive->setOnly( 'styles' );

		$url = $this->rl->createLoaderURL( 'local', $derive );

		$urls[] = $this->utils->expand( $url );
	}
}
