<?php
/**
 * Short-lived submission state and atomic ownership in the core options table.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Submissions;

defined( 'ABSPATH' ) || exit;

/** Coordinates workers without custom tables or an external cache dependency. */
final class State {

	/**
	 * Acquire a lease without overwriting another worker's ownership.
	 *
	 * @param string $key Internal identifier.
	 * @param int    $seconds Lease duration in seconds.
	 * @return string|null Result of this operation.
	 */
	public static function claim( string $key, int $seconds = 300 ): ?string {
		global $wpdb;
		$name  = 'easyformly_lock_' . $key;
		$owner = ( time() + $seconds ) . '.' . wp_generate_uuid4();
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The unique core option_name index provides atomic INSERT IGNORE; add_option() may upsert a competing lease. Ownership must bypass caches.
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $owner, 'no' ) );
			if ( 1 === $inserted ) {
				self::invalidate( $name );
				return $owner;
			}
			$previous = self::read_option( $name );
			if ( ! is_string( $previous ) || (int) explode( '.', $previous )[0] >= time() || ! self::remove( $name, $previous ) ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Confirm the lease is still held before performing an irreversible action.
	 *
	 * @param string $key Internal identifier.
	 * @param string $owner Unique lease ownership token.
	 * @return bool Result of this operation.
	 */
	public static function owns( string $key, string $owner ): bool {
		return (int) explode( '.', $owner )[0] >= time() && self::read_option( 'easyformly_lock_' . $key ) === $owner;
	}

	/**
	 * Release only this worker's lease, including after its expiry.
	 *
	 * @param string $key Internal identifier.
	 * @param string $owner Unique lease ownership token.
	 * @return void
	 */
	public static function release( string $key, string $owner ): void {
		self::remove( 'easyformly_lock_' . $key, $owner );
	}

	/**
	 * Read the latest receipt after ownership is acquired, even with a stale object cache.
	 *
	 * @param string $key Internal identifier.
	 * @return array<string, mixed> Result of this operation.
	 */
	public static function read( string $key ): array {
		$raw    = self::read_option( 'easyformly_state_' . $key );
		$record = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $record ) && ( $record['expires'] ?? 0 ) >= time() && is_array( $record['data'] ?? null ) ? $record['data'] : array();
	}

	/**
	 * Persist outcomes and a keyed fingerprint; never submission values or credentials.
	 *
	 * @param string               $key Internal identifier.
	 * @param array<string, mixed> $data Operational record without submission values.
	 * @param int                  $expires UTC expiry timestamp.
	 * @return bool Result of this operation.
	 */
	public static function write( string $key, array $data, int $expires ): bool {
		$name  = 'easyformly_state_' . $key;
		$value = wp_json_encode(
			array(
				'expires' => $expires,
				'data'    => $data,
			)
		);
		if ( false === $value ) {
			return false;
		}
		self::invalidate( $name );
		update_option( $name, $value, false );
		return self::read_option( $name ) === $value;
	}

	/**
	 * Bound rate-counter updates with a separate, short lease per network identity.
	 *
	 * @param int    $form_id Published form ID.
	 * @param int    $limit Submissions allowed in the window.
	 * @param string $bucket Counter name.
	 * @param int    $window Window in seconds.
	 * @return bool Result of this operation.
	 */
	public static function rate_allowed( int $form_id, int $limit = 10, string $bucket = 'submit', int $window = 300 ): bool {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$count   = self::count( $bucket . '_' . $form_id, $address, $window, $limit + 1 );
		return null !== $count && $count <= $limit;
	}

	/**
	 * Atomically count a bounded, short-lived keyed identity; never store its raw value.
	 *
	 * @param string $context  Counter name.
	 * @param string $identity Value to count, such as an address.
	 * @param int    $window   Window in seconds.
	 * @param int    $cap      Highest count stored.
	 * @return int|null Count in the window, or null when the counter is busy.
	 */
	public static function count( string $context, string $identity, int $window = 300, int $cap = 100 ): ?int {
		$key = 'rate_' . substr( hash_hmac( 'sha256', get_current_blog_id() . '|' . $context . '|' . $identity, wp_salt( 'auth' ) ), 0, 40 );
		if ( wp_using_ext_object_cache() ) {
			// A persistent cache increments atomically: no lease and no database write (D6).
			wp_cache_add( $key, 0, 'easyformly_rate', max( 1, $window ) );
			$count = wp_cache_incr( $key, 1, 'easyformly_rate' );
			return false === $count ? null : min( $cap, (int) $count );
		}
		$owner = null;
		// Simultaneous requests from one identity wait briefly instead of failing; the short lease bounds a crashed holder.
		for ( $attempt = 0; $attempt < 8 && null === $owner; ++$attempt ) {
			if ( $attempt > 0 ) {
				usleep( 25000 * $attempt );
			}
			$owner = self::claim( $key, 5 );
		}
		if ( null === $owner ) {
			return null;
		}
		try {
			$rate = get_transient( 'easyformly_' . $key );
			if ( ! is_array( $rate ) || ( $rate['expires'] ?? 0 ) < time() ) {
				$rate = array(
					'count'   => 0,
					'expires' => time() + max( 1, $window ),
				);
			}
			if ( (int) $rate['count'] >= $cap ) {
				return $cap;
			}
			$rate['count'] = min( $cap, (int) $rate['count'] + 1 );
			return set_transient( 'easyformly_' . $key, $rate, max( 1, (int) $rate['expires'] - time() ) ) ? $rate['count'] : null;
		} finally {
			self::release( $key, $owner );
		}
	}

	/**
	 * Remove expired records in bounded batches; Maintenance keeps the cursor between runs.
	 *
	 * @param int $after_id Last processed ID for keyset pagination.
	 * @return int|null Cursor for the next run, or null when the whole table was walked.
	 */
	public static function cleanup( int $after_id = 0 ): ?int {
		global $wpdb;
		for ( $batch = 0; $batch < 10; ++$batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded housekeeping of this plugin's non-autoloaded core options; keyset pagination must reflect deletions.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d AND (option_name LIKE %s OR option_name LIKE %s) ORDER BY option_id ASC LIMIT 100", $after_id, $wpdb->esc_like( 'easyformly_lock_' ) . '%', $wpdb->esc_like( 'easyformly_state_' ) . '%' ) );
			foreach ( $rows as $row ) {
				$after_id = (int) $row->option_id;
				$record   = str_starts_with( $row->option_name, 'easyformly_state_' ) ? json_decode( $row->option_value, true ) : null;
				$expires  = is_array( $record ) ? (int) ( $record['expires'] ?? 0 ) : (int) explode( '.', $row->option_value )[0];
				if ( $expires < time() ) {
					self::remove( $row->option_name, $row->option_value );
				}
			}
			if ( count( $rows ) < 100 ) {
				return null;
			}
		}
		return $after_id;
	}

	/**
	 * Read ownership and receipts directly; per-request caches may predate a competing worker.
	 *
	 * @param string $name Field or native option name.
	 * @return string|null Result of this operation.
	 */
	private static function read_option( string $name ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Correctness requires the latest committed ownership/receipt, not a previously cached absence.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Compare and delete prevents an expired worker from removing its successor's lease.
	 *
	 * @param string $name Field or native option name.
	 * @param string $expected Validated comparison value.
	 * @return bool Result of this operation.
	 */
	private static function remove( string $name, string $expected ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-delete on a core option; delete_option() has no ownership predicate.
		$removed = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $expected,
			),
			array( '%s', '%s' )
		);
		if ( 1 === $removed ) {
			self::invalidate( $name );
			return true;
		}
		return false;
	}

	/**
	 * Keep native option caches coherent after an atomic database operation.
	 *
	 * @param string $name Field or native option name.
	 * @return void
	 */
	private static function invalidate( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
