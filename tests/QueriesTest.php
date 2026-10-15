<?php
/**
 * Queries and assets the plugin adds to pages without a form.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\Plugin;
use EasyFormly\Settings\Settings;
use EasyFormly\Submissions\Maintenance;

/**
 * A page without a form costs the same as WordPress without the plugin (CLAUDE.md, "Prestazioni").
 */
class QueriesTest extends TestCase {

	/**
	 * The work the plugin does on `init` of every request reads only autoloaded options.
	 *
	 * The object cache is emptied first, as at the start of a request on a site without a
	 * persistent cache; the autoloaded options are then loaded once, as WordPress always does.
	 */
	public function test_init_runs_no_query_once_the_plugin_is_set_up(): void {
		global $wpdb;

		Plugin::init();
		wp_cache_flush();
		wp_load_alloptions();

		$before = $wpdb->num_queries;
		Plugin::init();

		$this->assertSame( 0, $wpdb->num_queries - $before );
	}

	/**
	 * Setup runs again after an update, then stops costing queries.
	 */
	public function test_setup_runs_again_after_an_update(): void {
		update_option( 'easyformly_version', '0.0.1', false );
		delete_option( Settings::OPTION );

		Plugin::init();

		$this->assertSame( EASYFORMLY_VERSION, get_option( 'easyformly_version' ) );
		$this->assertIsArray( get_option( Settings::OPTION ) );
		$this->assertArrayHasKey( 'easyformly_version', wp_load_alloptions(), 'The version check is free on every request' );
		$this->assertSame( 0, get_option( Maintenance::OPTION )['resume'], 'Retries left by the update are resumed by the next maintenance run' );
		$this->assertArrayNotHasKey( Maintenance::OPTION, wp_load_alloptions(), 'The cursors are not autoloaded' );
	}

	/**
	 * Styles and scripts load only where a form is printed.
	 */
	public function test_pages_without_a_form_load_no_asset(): void {
		$post = self::factory()->post->create( array( 'post_content' => 'Nessun modulo qui.' ) );
		$this->go_to( get_permalink( $post ) );
		do_action( 'wp_enqueue_scripts' );

		$this->assertFalse( wp_style_is( 'easyformly-frontend', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'easyformly-frontend', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'easyformly-turnstile', 'enqueued' ) );
	}
}
