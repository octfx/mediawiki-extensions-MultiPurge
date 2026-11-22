<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Services;

/**
 * A purge service that can produce its request maps one at a time.
 * Extending PurgeServiceInterface keeps base-interface implementations valid as they are.
 */
interface StreamingPurgeService extends PurgeServiceInterface {

	/**
	 * Lazily yields the request maps getPurgeRequest() would return so a caller never holds the whole list.
	 *
	 * @param string|array $urls
	 * @return iterable<int, array>
	 */
	public function yieldPurgeRequests( $urls ): iterable;
}
