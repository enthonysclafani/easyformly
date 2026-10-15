<?php
/**
 * Public disposable-domain lists, cached locally and synchronized only on request.
 *
 * @package EasyFormly
 */

namespace EasyFormly\AntiSpam;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The list ships with the plugin and is replaced only by a manual synchronization.
 */
final class DisposableDomains {
	public const OPTION       = 'easyformly_disposable_domains';
	private const MAX_BYTES   = 8 * 1024 * 1024;
	private const MAX_DOMAINS = 250000;

	/**
	 * The newline-delimited list, per site, parsed once per request.
	 *
	 * @var array<int, string>
	 */
	private static array $lists = array();

	/**
	 * The two public sources a synchronization must download in full.
	 *
	 * @return array<string, array{name: string, url: string, project: string}>
	 */
	public static function sources(): array {
		return array(
			'community' => array(
				'name'    => 'disposable-email-domains',
				'url'     => 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf',
				'project' => 'https://github.com/disposable-email-domains/disposable-email-domains',
			),
			'aggregate' => array(
				'name'    => 'disposable/disposable',
				'url'     => 'https://raw.githubusercontent.com/disposable/disposable-email-domains/master/domains.txt',
				'project' => 'https://github.com/disposable/disposable',
			),
		);
	}

	/**
	 * Read the last complete synchronization, or the bundled snapshot without HTTP.
	 *
	 * @return array<string, mixed> Metadata and the newline-delimited domains.
	 */
	private static function snapshot(): array {
		$saved = get_option( self::OPTION, array() );
		if ( is_array( $saved ) && 1 === ( $saved['version'] ?? null ) && is_string( $saved['domains'] ?? null ) && '' !== $saved['domains'] && strlen( $saved['domains'] ) < self::MAX_BYTES && ( $saved['count'] ?? 0 ) > 0 && ( $saved['count'] ?? 0 ) <= self::MAX_DOMAINS && ! empty( $saved['updated_at'] ) ) {
			return $saved;
		}
		$metadata            = (array) wp_json_file_decode( dirname( __DIR__, 2 ) . '/includes/data/disposable-domains.json', array( 'associative' => true ) );
		$metadata['domains'] = trim( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/data/disposable-domains.txt' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled local file, never a URL.
		$metadata['synced']  = false;
		return $metadata;
	}

	/**
	 * Metadata of the active list, without the domains.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$snapshot = self::snapshot();
		unset( $snapshot['domains'] );
		return $snapshot;
	}

	/**
	 * Exact domain and parent-domain matches, never arbitrary string suffixes.
	 *
	 * @param string $domain Domain or email address.
	 */
	public static function contains( string $domain ): bool {
		$domain = AntiSpam::domain( $domain );
		if ( '' === $domain ) {
			return false;
		}
		$site = get_current_blog_id();
		// Search the newline-delimited list directly: a lookup table of every domain would cost about 10 MB per request.
		if ( ! isset( self::$lists[ $site ] ) ) {
			self::$lists[ $site ] = "\n" . self::snapshot()['domains'] . "\n";
		}
		$parts = explode( '.', $domain );
		$last  = count( $parts ) - 1;
		for ( $i = 0; $i < $last; $i++ ) {
			if ( str_contains( self::$lists[ $site ], "\n" . implode( '.', array_slice( $parts, $i ) ) . "\n" ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reject incomplete, oversized or malformed feeds before replacing any saved data.
	 *
	 * @param string $body Downloaded feed.
	 * @return array<string, true>|WP_Error Normalized domains as keys.
	 */
	private static function parse( string $body ) {
		if ( '' === $body || strlen( $body ) >= self::MAX_BYTES ) {
			return new WP_Error( 'disposable_size' );
		}
		$body  = preg_replace( '/^\xEF\xBB\xBF/', '', $body ) ?? '';
		$lines = preg_split( '/\r\n|\r|\n/', $body );
		if ( false === $lines || count( $lines ) > self::MAX_DOMAINS + 100 ) {
			return new WP_Error( 'disposable_size' );
		}
		$domains = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$domain = AntiSpam::domain( $line );
			if ( '' === $domain ) {
				return new WP_Error( 'disposable_format' );
			}
			$domains[ $domain ] = true;
		}
		return count( $domains ) >= 1000 && count( $domains ) <= self::MAX_DOMAINS ? $domains : new WP_Error( 'disposable_incomplete' );
	}

	/**
	 * Called only by the authorized manual admin action; no cron or visitor downloads.
	 *
	 * @return array<string, mixed>|WP_Error Status of the new list, or why the previous one was kept.
	 */
	public static function sync() {
		$domains = array();
		$counts  = array();
		foreach ( self::sources() as $key => $source ) {
			$response = wp_safe_remote_get(
				$source['url'],
				array(
					'timeout'             => 15,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_BYTES,
					'headers'             => array( 'Accept' => 'text/plain' ),
				)
			);
			$parsed   = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ? self::parse( wp_remote_retrieve_body( $response ) ) : new WP_Error( 'disposable_download' );
			if ( is_wp_error( $parsed ) ) {
				/* translators: %s: public domain-list source name. */
				return new WP_Error( 'disposable_sync', sprintf( __( 'The %s source is unavailable or returned an incomplete or invalid list. The previous list was kept.', 'easyformly' ), $source['name'] ) );
			}
			$counts[ $key ] = count( $parsed );
			$domains       += $parsed;
		}
		if ( count( $domains ) > self::MAX_DOMAINS ) {
			return new WP_Error( 'disposable_size', __( 'The received list is too large. The previous list was kept.', 'easyformly' ) );
		}
		$list = array_keys( $domains );
		sort( $list, SORT_STRING );
		$snapshot = array(
			'version'    => 1,
			'domains'    => implode( "\n", $list ),
			'count'      => count( $list ),
			'updated_at' => time(),
			'synced'     => true,
			'sources'    => $counts,
		);
		if ( strlen( $snapshot['domains'] ) >= self::MAX_BYTES || ( ! update_option( self::OPTION, $snapshot, false ) && get_option( self::OPTION ) !== $snapshot ) ) {
			return new WP_Error( 'disposable_save', __( 'The list could not be saved. The previous list was kept.', 'easyformly' ) );
		}
		unset( self::$lists[ get_current_blog_id() ] );
		return self::status();
	}
}
