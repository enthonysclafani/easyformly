<?php
/**
 * Uninstall: technical data always goes, forms and entries only on explicit opt-in.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\AntiSpam\DisposableDomains;
use EasyFormly\Plugin;
use EasyFormly\Review\Review;
use EasyFormly\Settings\Settings;
use EasyFormly\Submissions\Entries;
use EasyFormly\Submissions\Maintenance;
use EasyFormly\Submissions\State;

/**
 * Every datum listed in docs/data-model.md is covered here, on every site of a network too.
 */
class UninstallTest extends TestCase {

	public function test_technical_data_is_removed_and_user_data_is_kept_by_default(): void {
		$site = $this->seed();

		$this->uninstall();

		$this->assertTechnicalDataRemoved();
		$this->assertNotNull( get_post( $site['form'] ), 'Forms are kept without opt-in' );
		$this->assertNotNull( get_post( $site['entry'] ), 'Entries are kept without opt-in' );
		$this->assertIsArray( get_option( Settings::OPTION ), 'Settings are kept without opt-in' );
	}

	public function test_opt_in_removes_everything_the_plugin_created(): void {
		$site = $this->seed( true );

		$this->uninstall();

		$this->assertTechnicalDataRemoved();
		$this->assertEverythingRemoved( $site );
	}

	/**
	 * WordPress runs uninstall.php once: it must clean every site of the network.
	 *
	 * @group multisite
	 */
	public function test_opt_in_removes_everything_on_every_site_of_a_network(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$main  = $this->seed( true );
		$other = self::factory()->blog->create();
		switch_to_blog( $other );
		Plugin::init();
		$second = $this->seed( true );
		restore_current_blog();

		$this->uninstall();

		$this->assertEverythingRemoved( $main );
		switch_to_blog( $other );
		$this->assertTechnicalDataRemoved();
		$this->assertEverythingRemoved( $second );
		restore_current_blog();
	}

	/**
	 * Create a form, an entry and every kind of technical datum on the current site.
	 *
	 * @param bool $delete Whether the administrator opted into data removal.
	 * @return array{form: int, entry: int}
	 */
	private function seed( bool $delete = false ): array {
		Plugin::init();
		update_option( Settings::OPTION, array_merge( Settings::get(), array( 'delete_on_uninstall' => $delete ) ), false );
		$definition = $this->definition();
		$form       = $this->form( $definition );
		$entry      = Entries::store( $form, $definition, $this->values(), $this->assessment( 'accepted' ) );
		update_option( DisposableDomains::OPTION, array( 'version' => 1 ), false );
		State::write( 'uninstall-fixture', array( 'entry' => $entry ), time() + HOUR_IN_SECONDS );
		State::claim( 'uninstall-fixture' );
		Maintenance::resume_retries();
		set_transient( 'easyformly_uninstall_fixture', 1, HOUR_IN_SECONDS );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'easyformly_retry_delivery', array( $entry ) );
		return array(
			'form'  => $form,
			'entry' => $entry,
		);
	}

	/**
	 * Run uninstall.php as WordPress does: its functions exist after the first run.
	 */
	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'easyformly/easyformly.php' );
		}
		if ( function_exists( 'EasyFormly\uninstall_all' ) ) {
			\EasyFormly\uninstall_all();
			return;
		}
		require dirname( __DIR__ ) . '/uninstall.php';
	}

	/**
	 * Names of the options of the plugin left on the current site, transients included.
	 *
	 * @return list<string>
	 */
	private function options_left(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tests inspect the raw table on purpose.
		return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'easyformly_' ) . '%', $wpdb->esc_like( '_transient_easyformly_' ) . '%', $wpdb->esc_like( '_transient_timeout_easyformly_' ) . '%' ) );
	}

	private function assertTechnicalDataRemoved(): void {
		$left = array_diff( $this->options_left(), array( Settings::OPTION, 'easyformly_review_terms_version' ) );
		$this->assertSame( array(), array_values( $left ), 'Locks, receipts, transients, version and list cache are removed' );
		$this->assertFalse( $this->scheduled( 'easyformly_retry_delivery' ), 'No scheduled event left' );
	}

	/**
	 * Every form, entry, meta, category and option of the plugin is gone.
	 *
	 * @param array{form: int, entry: int} $site Seeded IDs.
	 */
	private function assertEverythingRemoved( array $site ): void {
		$this->assertSame( array(), $this->options_left(), 'No option of the plugin survives' );
		$this->assertNull( get_post( $site['form'] ), 'Forms are removed' );
		$this->assertNull( get_post( $site['entry'] ), 'Entries are removed' );
		$this->assertSame( array(), get_post_meta( $site['entry'] ), 'Entry meta is removed' );
		register_taxonomy( Review::TAXONOMY, Plugin::ENTRY );
		$this->assertSame(
			array(),
			get_terms(
				array(
					'taxonomy'   => Review::TAXONOMY,
					'hide_empty' => false,
				)
			),
			'Review categories are removed'
		);
	}

	/**
	 * Whether any event of a hook is scheduled, whatever its arguments.
	 *
	 * @param string $hook Hook name.
	 */
	private function scheduled( string $hook ): bool {
		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ $hook ] ) ) {
				return true;
			}
		}
		return false;
	}
}
