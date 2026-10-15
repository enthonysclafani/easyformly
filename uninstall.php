<?php
/**
 * Explicit opt-in cleanup using core tables and APIs only.
 *
 * @package EasyFormly
 */

namespace EasyFormly;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Manage plugin-specific native WordPress data.
 *
 * @return void Nothing.
 */
function uninstall_site(): void {
	wp_unschedule_hook( 'easyformly_retry_delivery' );
	delete_option( 'easyformly_version' );
	delete_option( 'easyformly_maintenance' );
	delete_option( 'easyformly_disposable_domains' );
	// Read names in the existing core options table; delete through the Options API.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall enumeration of our own native options; removal uses core APIs and caching would serve stale names.
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'easyformly_lock_' ) . '%', $wpdb->esc_like( 'easyformly_state_' ) . '%', $wpdb->esc_like( '_transient_easyformly_' ) . '%', $wpdb->esc_like( '_transient_timeout_easyformly_' ) . '%' ) );
	foreach ( $names as $name ) {
		if ( str_starts_with( $name, '_transient_timeout_easyformly_' ) ) {
			delete_transient( substr( $name, strlen( '_transient_timeout_' ) ) );
		} elseif ( str_starts_with( $name, '_transient_easyformly_' ) ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		} else {
			delete_option( $name );
		}
	}
	$settings = get_option( 'easyformly_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}
	foreach ( array( 'easyformly_form', 'easyformly_entry' ) as $type ) {
		register_post_type(
			$type,
			array(
				'public'  => false,
				'rewrite' => false,
			)
		);
	}
	register_taxonomy(
		'easyformly_safety',
		'easyformly_entry',
		array(
			'public'  => false,
			'rewrite' => false,
		)
	);
	do {
		$ids     = get_posts(
			array(
				'post_type'      => array( 'easyformly_form', 'easyformly_entry' ),
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future', 'auto-draft', 'trash' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
			)
		);
		$removed = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				++$removed;
			}
		}
	} while ( $ids && $removed );
	$terms = get_terms(
		array(
			'taxonomy'   => 'easyformly_safety',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			wp_delete_term( $term, 'easyformly_safety' );
		}
	}
	delete_option( 'easyformly_review_terms_version' );
	delete_option( 'easyformly_settings' );
}

/**
 * Manage plugin-specific native WordPress data.
 *
 * @return void Nothing.
 */
function uninstall_all(): void {
	if ( ! is_multisite() ) {
		uninstall_site();
		return;
	}
	$offset = 0;
	do {
		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $offset,
			)
		);
		foreach ( $sites as $site_id ) {
			switch_to_blog( (int) $site_id );
			uninstall_site();
			restore_current_blog();
		}
		$offset    += 100;
		$site_count = count( $sites );
	} while ( 100 === $site_count );
}

uninstall_all();
