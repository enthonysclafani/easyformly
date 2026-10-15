<?php
/**
 * Private post types, site lifecycle and hook registration.
 *
 * @package EasyFormly
 */

namespace EasyFormly;

use EasyFormly\Admin\Admin;
use EasyFormly\Forms\Admin\Editor;
use EasyFormly\Forms\Forms;
use EasyFormly\Forms\Renderer;
use EasyFormly\Phone\Phone;
use EasyFormly\Privacy\Privacy;
use EasyFormly\Review\Admin\Screen;
use EasyFormly\Review\Review;
use EasyFormly\Settings\Admin\Page;
use EasyFormly\Settings\Settings;
use EasyFormly\Submissions\Admin\EntryScreen;
use EasyFormly\Submissions\Endpoint;
use EasyFormly\Submissions\Maintenance;
use EasyFormly\Submissions\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Register post types and every part of the plugin, and own activation and deactivation.
 */
final class Plugin {
	public const FORM  = 'easyformly_form';
	public const ENTRY = 'easyformly_entry';

	/**
	 * Register the hooks of every part of the plugin, one line each.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'init' ) );
		Submission::boot();
		Maintenance::boot();
		Renderer::boot();
		Endpoint::boot();
		Privacy::boot();
		Phone::boot();
		if ( is_admin() ) {
			Page::boot();
			Editor::boot();
			Admin::boot();
			EntryScreen::boot();
			Screen::boot();
		}
	}

	/**
	 * Initialize non-autoloaded settings.
	 *
	 * @return void
	 */
	public static function activate(): void {
		add_option( Settings::OPTION, Settings::defaults(), '', false );
	}

	/**
	 * Stop scheduled work on the selected sites.
	 *
	 * @param bool $network_wide Whether deactivation applies to the whole network.
	 * @return void
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			self::unschedule();
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
				self::unschedule();
				restore_current_blog();
			}
			$offset    += 100;
			$site_count = count( $sites );
		} while ( 100 === $site_count );
	}

	/**
	 * Stop all plugin jobs while retaining preferences and archived data.
	 *
	 * @return void
	 */
	private static function unschedule(): void {
		wp_unschedule_hook( 'easyformly_retry_delivery' );
		delete_option( 'easyformly_version' );
	}

	/**
	 * Load translations, register private post types and initialize each site lazily.
	 *
	 * @return void
	 */
	public static function init(): void {
		// The Italian translation ships with the plugin (D9); files in wp-content/languages still take precedence.
		// Registering the folder only when it has the current locale spares other sites a lookup on every string.
		if ( is_readable( plugin_dir_path( EASYFORMLY_FILE ) . 'languages/easyformly-' . determine_locale() . '.mo' ) ) {
			load_plugin_textdomain( 'easyformly', false, dirname( plugin_basename( EASYFORMLY_FILE ) ) . '/languages' );
		}
		$capabilities = array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ), 'manage_options' );
		$shared       = array(
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_in_rest'        => false,
			'show_ui'             => true,
			'can_export'          => false,
			'rewrite'             => false,
			'query_var'           => false,
			'map_meta_cap'        => false,
			'capabilities'        => $capabilities,
		);
		register_post_type(
			self::FORM,
			array_merge(
				$shared,
				array(
					'labels'    => array(
						'name'          => __( 'Forms', 'easyformly' ),
						'singular_name' => __( 'Form', 'easyformly' ),
						'menu_name'     => 'EasyFormly',
						'add_new_item'  => __( 'Add a form', 'easyformly' ),
						'edit_item'     => __( 'Edit form', 'easyformly' ),
						'all_items'     => __( 'Forms', 'easyformly' ),
					),
					'menu_icon' => 'dashicons-email',
					'supports'  => array( 'title' ),
				)
			)
		);
		$capabilities['create_posts'] = 'do_not_allow';
		register_post_type(
			self::ENTRY,
			array_merge(
				$shared,
				array(
					'labels'       => array(
						'name'          => __( 'Submissions', 'easyformly' ),
						'singular_name' => __( 'Submission', 'easyformly' ),
						'edit_item'     => __( 'Submission details', 'easyformly' ),
						'all_items'     => __( 'Submissions', 'easyformly' ),
					),
					'show_in_menu' => 'edit.php?post_type=' . self::FORM,
					'capabilities' => $capabilities,
					'supports'     => false,
				)
			)
		);
		Forms::register_meta();
		Review::register();
		// The version is autoloaded, so the check costs no query; setup runs only after an update.
		if ( EASYFORMLY_VERSION !== get_option( 'easyformly_version' ) ) {
			self::setup();
		}
	}

	/**
	 * Create missing data and resume archive jobs after activation or an update.
	 *
	 * Lazy setup also covers sites reached after network activation.
	 */
	private static function setup(): void {
		add_option( Settings::OPTION, Settings::defaults(), '', false );
		if ( ! Review::create_terms() ) {
			// Retried on the next request.
			return;
		}
		update_option( 'easyformly_version', EASYFORMLY_VERSION, true );
		Maintenance::resume_retries();
	}
}
