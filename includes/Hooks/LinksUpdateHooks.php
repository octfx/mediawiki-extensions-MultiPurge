<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Hooks;

use Exception;
use MediaWiki\Cache\HTMLCacheUpdater;
use MediaWiki\Config\Config;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Hook\LinksUpdateCompleteHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\ParserCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDBAccessObject;

class LinksUpdateHooks implements LinksUpdateCompleteHook {

	private Config $config;
	private ParserCache $parserCache;
	private WikiPageFactory $wikiPageFactory;
	private IConnectionProvider $connectionProvider;
	private HTMLCacheUpdater $cacheUpdater;

	/**
	 * @param Config $config
	 * @param ParserCache $parserCache
	 * @param WikiPageFactory $wikiPageFactory
	 * @param IConnectionProvider $connectionProvider
	 * @param HTMLCacheUpdater $cacheUpdater
	 */
	public function __construct(
		Config $config,
		ParserCache $parserCache,
		WikiPageFactory $wikiPageFactory,
		IConnectionProvider $connectionProvider,
		HTMLCacheUpdater $cacheUpdater
	) {
		$this->config = $config;
		$this->parserCache = $parserCache;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->connectionProvider = $connectionProvider;
		$this->cacheUpdater = $cacheUpdater;
	}

	/**
	 * MW 1.46 renamed the HtmlCacheUpdater service to HTMLCacheUpdater and deprecated the old name,
	 * which MW 1.43 still requires, so the service cannot be listed in extension.json
	 *
	 * @param Config $config
	 * @param ParserCache $parserCache
	 * @param WikiPageFactory $wikiPageFactory
	 * @param IConnectionProvider $connectionProvider
	 * @return self
	 */
	public static function factory(
		Config $config,
		ParserCache $parserCache,
		WikiPageFactory $wikiPageFactory,
		IConnectionProvider $connectionProvider
	): self {
		$cacheUpdater = MediaWikiServices::getInstance()->getService(
			version_compare( MW_VERSION, '1.46', '<' ) ? 'HtmlCacheUpdater' : 'HTMLCacheUpdater'
		);

		return new self( $config, $parserCache, $wikiPageFactory, $connectionProvider, $cacheUpdater );
	}

	/**
	 * Keeps the render of a non-recursive links update, such as a template cascade's
	 * RefreshLinksJob, which core would otherwise discard. The entry is stamped with the
	 * render's own start time, not this hook's run time, so a render that began before a
	 * later edit to the same page or a transcluded template expires instead of sticking.
	 *
	 * @param LinksUpdate $linksUpdate
	 * @param mixed $ticket
	 * @return void
	 */
	public function onLinksUpdateComplete( $linksUpdate, $ticket ): void {
		// The edited page's own update is recursive, and core has already saved its parser cache.
		// Opportunistic refreshes queued by page views carry RefreshLinksJob's default cause and
		// are left to core.
		if (
			!$this->config->get( 'MultiPurgeWarmParserCacheOnRefreshLinks' ) ||
			$linksUpdate->isRecursive() ||
			$linksUpdate->getCauseAction() === 'RefreshLinksJob'
		) {
			return;
		}

		$page = $this->wikiPageFactory->newFromID( $linksUpdate->getPageId(), IDBAccessObject::READ_LATEST );
		$revision = $linksUpdate->getRevisionRecord();
		$output = $linksUpdate->getParserOutput();
		$cacheTime = $output->getCacheTime();

		if (
			$page === null ||
			$revision === null ||
			$revision->getId() !== $page->getLatest() ||
			!$output->hasText() ||
			!$output->isCacheable() ||
			$page->getTouched() > $cacheTime
		) {
			return;
		}

		try {
			$parserOptions = $page->makeParserOptions( 'canonical' );
			// MW 1.47 can render links updates with another parser than page views, and a render only
			// belongs in the cache of the parser that made it. RefreshLinksJob picks it the same way.
			$linksUpdateUsesParsoid = $this->config->has( 'UseParsoidLinksUpdate' ) ?
				$this->config->get( 'UseParsoidLinksUpdate' ) : null;
			if ( $linksUpdateUsesParsoid !== null && $linksUpdateUsesParsoid !== $parserOptions->getUseParsoid() ) {
				return;
			}

			$cachedOutput = $this->parserCache->getDirty( $page, $parserOptions );
			// Only replace an entry the parser cache still holds, so warming doesn't push out pages
			// that are read more often than this one (T327162). RefreshLinksJob reuses the cached
			// render when a page view has already re-rendered the page since it was invalidated,
			// and whatever invalidated it also purged the CDN.
			$renderId = $output->getRenderId();
			if ( !$cachedOutput || ( $renderId !== null && $cachedOutput->getRenderId() === $renderId ) ) {
				return;
			}

			// Makes warmed entries recognisable in the parser cache comment and render stats
			$parserOptions->setRenderReason( 'multipurge-warmup' );
			$this->parserCache->save(
				$output,
				$page,
				$parserOptions,
				$cacheTime,
				$revision->getId()
			);

			$dbw = $this->connectionProvider->getPrimaryDatabase();
			$dbw->newUpdateQueryBuilder()
				->update( 'page' )
				->set( [ 'page_touched' => $dbw->timestamp( $cacheTime ) ] )
				->where( [ 'page_id' => $page->getId() ] )
				->andWhere( $dbw->expr( 'page_touched', '<', $dbw->timestamp( $cacheTime ) ) )
				->caller( __METHOD__ )
				->execute();

			$this->cacheUpdater->purgeTitleUrls(
				$page,
				HTMLCacheUpdater::PURGE_NAIVE | HTMLCacheUpdater::PURGE_URLS_LINKSUPDATE_ONLY
			);
		} catch ( Exception $e ) {
			LoggerFactory::getInstance( 'MultiPurge' )->error(
				'Could not warm parser cache for page {page}: {message}',
				[ 'page' => $page->getId(), 'message' => $e->getMessage(), 'exception' => $e ]
			);
			return;
		}

		wfDebugLog( 'MultiPurge', sprintf( 'Warmed parser cache for page %d at %s', $page->getId(), $cacheTime ) );
	}
}
