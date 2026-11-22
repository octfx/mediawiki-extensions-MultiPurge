<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge;

use MediaWiki\Config\Config;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;

/** Only the wiki and explicitly configured hosts may be purged. */
class PurgeUrlPolicy {
	private ?string $serverOrigin = null;

	/** @var array<string,true> */
	private array $allowedHosts = [];

	/**
	 * @param string $server Value of $wgServer
	 * @param string[] $allowedHosts Additional bare hostnames
	 */
	public function __construct( string $server, array $allowedHosts = [] ) {
		if ( str_starts_with( $server, '//' ) ) {
			// Match core's default scheme when building a URL from the wiki's own origin.
			$server = "http:$server";
		}
		$wiki = self::readAuthority( $server );
		$logger = LoggerFactory::getInstance( 'MultiPurge' );
		if ( $wiki !== null ) {
			[ $this->serverOrigin, $host ] = $wiki;
			$this->allowedHosts[strtolower( $host )] = true;
		} else {
			$logger->warning( '$wgServer has no usable HTTP(S) origin for purge targets' );
		}

		foreach ( $allowedHosts as $host ) {
			if ( !is_string( $host ) || !self::isHostname( $host ) ) {
				$logger->warning( 'Ignoring an invalid $wgMultiPurgeAllowedHosts entry: use bare ASCII hostnames' );
				continue;
			}
			$this->allowedHosts[strtolower( $host )] = true;
		}
	}

	public static function newFromConfigs( Config $mainConfig, Config $extensionConfig ): self {
		$allowedHosts = $extensionConfig->get( 'MultiPurgeAllowedHosts' );
		return new self( $mainConfig->get( MainConfigNames::Server ), is_array( $allowedHosts ) ? $allowedHosts : [] );
	}

	public function resolve( mixed $url, ?string &$reason = null ): ?string {
		$reason = null;
		if ( !is_string( $url ) ) {
			$reason = 'not a string';
			return null;
		}

		// Add only the missing origin because rewriting the rest can change the cache key.
		if ( str_starts_with( $url, '//' ) ) {
			$url = "https:$url";
		} elseif ( str_starts_with( $url, '/' ) ) {
			if ( $this->serverOrigin === null ) {
				$reason = '$wgServer has no usable origin';
				return null;
			}
			$url = $this->serverOrigin . $url;
		}

		$authority = self::readAuthority( $url, $reason );
		if ( $authority === null ) {
			return null;
		}
		if ( !isset( $this->allowedHosts[strtolower( $authority[1] )] ) ) {
			$reason = 'host is not an allowed purge target';
			return null;
		}
		return $url;
	}

	/**
	 * @param mixed[] $urls
	 * @param int|null &$rejectedCount Number of rejected selections
	 * @return string[]
	 */
	public function resolveList( array $urls, ?int &$rejectedCount = null ): array {
		$rejectedCount = 0;
		$seen = [];
		$resolved = [];
		foreach ( $urls as $url ) {
			if ( is_string( $url ) ) {
				if ( isset( $seen[$url] ) ) {
					continue;
				}
				$seen[$url] = true;
			}
			$target = $this->resolve( $url, $reason );
			if ( $target === null ) {
				$rejectedCount++;
				$this->logRejection( $url, $reason ?? 'invalid URL' );
			} else {
				$resolved[$target] = $target;
			}
		}
		return array_values( $resolved );
	}

	public function logRejection( mixed $url, string $reason ): void {
		// A rejected URL can contain secrets, so log only a validated hostname.
		$host = is_string( $url ) ? parse_url( $url, PHP_URL_HOST ) : null;
		LoggerFactory::getInstance( 'MultiPurge' )->warning(
			'Purge target rejected by the host policy: {reason} (host: {host})',
			[ 'reason' => $reason, 'host' => is_string( $host ) && self::isHostname( $host ) ? $host : null ]
		);
	}

	/** @return array{string,string}|null Origin and hostname */
	private static function readAuthority( string $url, ?string &$reason = null ): ?array {
		if ( $url === '' || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			$reason = 'empty URL or contains whitespace or control characters';
			return null;
		}
		if ( str_contains( $url, '\\' ) ) {
			$reason = 'contains a backslash';
			return null;
		}
		if ( !preg_match( '~\Ahttps?://([^/?#]*)~i', $url, $parts ) ) {
			$reason = 'requires an HTTP(S) scheme and host or a root-relative URL';
			return null;
		}
		if ( $parts[1] === '' ) {
			$reason = 'failed to parse host';
			return null;
		}
		if ( str_contains( $parts[1], '@' ) ) {
			$reason = 'contains credentials';
			return null;
		}
		// PHP's URL parser accepts trailing junk in ports, while HTTP clients may parse it differently.
		if ( !preg_match( '~\A(\[[^\]]+\]|[^:]+)(?::([0-9]{1,5}))?\z~', $parts[1], $authority )
			|| ( isset( $authority[2] ) && ( (int)$authority[2] < 1 || (int)$authority[2] > 65535 ) )
		) {
			$reason = 'invalid port or authority';
			return null;
		}
		if ( !self::isHostname( $authority[1] ) ) {
			$reason = 'host is not a valid purge target hostname';
			return null;
		}
		return [ $parts[0], $authority[1] ];
	}

	private static function isHostname( string $host ): bool {
		if ( str_starts_with( $host, '[' ) && str_ends_with( $host, ']' ) ) {
			return filter_var( substr( $host, 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false;
		}
		return filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) !== false;
	}
}
