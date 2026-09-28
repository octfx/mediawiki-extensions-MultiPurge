<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Hooks;

use Exception;
use File;
use JobQueueGroup;
use MediaWiki\Cache\HtmlCacheUpdater;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Extension\MultiPurge\MultiPurgeJob;
use MediaWiki\Hook\EditPage__attemptSave_afterHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\ArticlePurgeHook;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\DerivativeContext;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Utils\UrlUtils;
use ReflectionException;
use ReflectionObject;
use WikiFilePage;
use WikiPage;

/**
 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
 */
class PurgeHooks implements ArticlePurgeHook, EditPage__attemptSave_afterHook {

	private Config $config;
	private HtmlCacheUpdater $cacheUpdater;
	private JobQueueGroup $group;
	private ResourceLoader $rl;
	private UrlUtils $utils;

	/**
	 * @param Config $config
	 * @param HtmlCacheUpdater $cacheUpdater
	 * @param JobQueueGroup $group
	 * @param ResourceLoader $rl
	 * @param UrlUtils $utils
	 */
	public function __construct(
		Config $config,
		HtmlCacheUpdater $cacheUpdater,
		JobQueueGroup $group,
		ResourceLoader $rl,
		UrlUtils $utils
	) {
		$this->config = $config;
		$this->cacheUpdater = $cacheUpdater;
		$this->group = $group;
		$this->rl = $rl;
		$this->utils = $utils;
	}

	/**
	 * MW 1.46 renamed the HtmlCacheUpdater service to HTMLCacheUpdater and deprecated the old name,
	 * which MW 1.43 still requires, so the service cannot be listed in extension.json
	 *
	 * @param Config $config
	 * @param JobQueueGroup $group
	 * @param ResourceLoader $rl
	 * @param UrlUtils $utils
	 * @return self
	 */
	public static function factory(
		Config $config,
		JobQueueGroup $group,
		ResourceLoader $rl,
		UrlUtils $utils
	): self {
		$cacheUpdater = MediaWikiServices::getInstance()->getService(
			version_compare( MW_VERSION, '1.46', '<' ) ? 'HtmlCacheUpdater' : 'HTMLCacheUpdater'
		);

		return new self( $config, $cacheUpdater, $group, $rl, $utils );
	}

	/**
	 * @param WikiPage $wikiPage
	 * @return void
	 */
	public function onArticlePurge( $wikiPage ): void {
		if ( $wikiPage instanceof WikiFilePage && $wikiPage->getTitle()->getNamespace() === NS_FILE ) {
			$files = $this->getThumbnails( $wikiPage->getFile() );
			// Remove mwbackend link
			array_shift( $files );
			$urls = $this->linkThumbnails( $files, $wikiPage->getFile() );
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
	 * @param array $resultDetails
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
	 * File::getThumbnails() is protected, so it is called through reflection
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

		if ( !method_exists( $file, 'getThumbnails' ) ) {
			return [];
		}

		$refObject = new ReflectionObject( $file );
		try {
			$refMethod = $refObject->getMethod( 'getThumbnails' );
		} catch ( ReflectionException ) {
			return [];
		}

		try {
			$thumbnails = $refMethod->invoke( $file );
		} catch ( ReflectionException ) {
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

		if ( !$urls ) {
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
				} catch ( Exception ) {
					$status = false;
				}
				wfDebugLog(
					'MultiPurge',
					sprintf(
						'Job Status: %s',
						( $status ? 'success' : 'error' )
					)
				);
			}
		}
	}

	/**
	 * This manually builds the url for the site.styles module
	 * Gets called after an edit to a MediaWiki:*.css page
	 *
	 * @param Title $title
	 * @param array &$urls
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
