<?php

declare( strict_types=1 );

use Wikimedia\Http\MultiHttpClient;

/**
 * Answers every request with a short controlled response and keeps only bounded aggregates.
 * Retaining the request maps would make the memory budget self-fulfilling.
 * Coverage is folded into one bit per URL index and bucket, and everything else is discarded per call.
 */
class MultiPurgeMemoryProbeClient extends MultiHttpClient {

	public int $calls = 0;
	public int $maxBatch = 0;
	public int $totalRequests = 0;
	public int $varnishRequests = 0;
	public int $cloudflarePosts = 0;
	public int $cloudflareMaxUrlsPerPost = 0;

	/** @var array<string, array<int, int>> bucket => byte => bitmask of seen URL indexes */
	private array $coverageBits = [];

	private int $urlCount;

	public function __construct( int $urlCount ) {
		parent::__construct( [] );
		$this->urlCount = $urlCount;
	}

	/**
	 * @param array[] $reqs
	 * @param array $opts
	 * @param string $caller
	 * @return array[]
	 */
	public function runMulti( array $reqs, array $opts = [], string $caller = __METHOD__ ): array {
		$this->calls++;
		$this->maxBatch = max( $this->maxBatch, count( $reqs ) );
		$this->totalRequests += count( $reqs );

		$responses = [];
		foreach ( $reqs as $index => $req ) {
			$this->record( $req );
			$responses[$index] = [ 'response' => [ 200, 'OK', [], 'ok', null ] ];
		}
		return $responses;
	}

	/**
	 * @param array $req
	 * @return void
	 */
	private function record( array $req ): void {
		if ( $req['method'] === 'PURGE' ) {
			$this->varnishRequests++;
			$parts = parse_url( $req['url'] );
			$bucket = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
			$this->mark( $bucket, $this->urlIndexOf( $parts['path'] ?? '' ) );
			return;
		}

		$this->cloudflarePosts++;
		$files = json_decode( $req['body'], true )['files'];
		$this->cloudflareMaxUrlsPerPost = max( $this->cloudflareMaxUrlsPerPost, count( $files ) );
		foreach ( $files as $file ) {
			$this->mark( 'cloudflare', $this->urlIndexOf( parse_url( $file['url'] ?? $file, PHP_URL_PATH ) ?? '' ) );
		}
	}

	/**
	 * @param string $bucket
	 * @param int $index
	 * @return void
	 */
	private function mark( string $bucket, int $index ): void {
		$byte = intdiv( $index, 8 );
		$this->coverageBits[$bucket][$byte] = ( $this->coverageBits[$bucket][$byte] ?? 0 ) | ( 1 << ( $index % 8 ) );
	}

	/**
	 * @param string $path
	 * @return int
	 */
	private function urlIndexOf( string $path ): int {
		return (int)substr( strrchr( $path, '/' ) ?: '/', 1 );
	}

	/**
	 * @return array<string, int>
	 */
	public function missingPerBucket(): array {
		$missing = [];
		foreach ( $this->coverageBits as $bucket => $bytes ) {
			$count = 0;
			for ( $i = 0; $i < $this->urlCount; $i++ ) {
				if ( ( ( $bytes[intdiv( $i, 8 )] ?? 0 ) >> ( $i % 8 ) ) & 1 ) {
					continue;
				}
				$count++;
			}
			$missing[$bucket] = $count;
		}
		return $missing;
	}
}
