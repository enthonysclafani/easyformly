<?php
/**
 * Lazy maintenance without WP-Cron (D1).
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\Plugin;
use EasyFormly\Submissions\Entries;
use EasyFormly\Submissions\Maintenance;
use EasyFormly\Submissions\State;

/**
 * Retention, expired locks and resumed retries run in batches on admin_init and after a submission.
 */
class MaintenanceTest extends TestCase {

	/**
	 * An archived entry dated some days ago.
	 *
	 * @param int $age Age in days.
	 * @return int Entry ID.
	 */
	private function old_entry( int $age ): int {
		$definition = $this->definition();
		$entry      = Entries::store( $this->form( $definition ), $definition, $this->values(), $this->assessment( 'accepted' ) );
		$date       = gmdate( 'Y-m-d H:i:s', time() - $age * DAY_IN_SECONDS );
		wp_update_post(
			array(
				'ID'            => $entry,
				'post_date'     => $date,
				'post_date_gmt' => $date,
			)
		);
		return $entry;
	}

	public function test_admin_init_runs_retention_and_lock_cleanup_without_cron(): void {
		$expired = $this->old_entry( 31 );
		$recent  = $this->old_entry( 1 );
		update_option( 'easyformly_lock_expired-fixture', ( time() - 10 ) . '.owner', false );

		// Only the plugin's callback: the whole admin_init would also run the core update checks.
		$this->assertNotFalse( has_action( 'admin_init', array( Maintenance::class, 'run' ) ) );
		Maintenance::run();

		$this->assertNull( get_post( $expired ), 'Expired entries are deleted' );
		$this->assertNotNull( get_post( $recent ), 'Recent entries are kept' );
		$this->assertFalse( get_option( 'easyformly_lock_expired-fixture' ), 'Expired locks are removed' );
		$this->assertGreaterThan( time(), get_option( Maintenance::OPTION )['due'], 'The next complete run is tomorrow' );
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				$this->assertStringStartsNotWith( 'easyformly_', (string) $hook, 'No WP-Cron event' );
			}
		}
	}

	public function test_a_complete_run_waits_a_day_before_the_next(): void {
		Maintenance::run();
		$expired = $this->old_entry( 31 );

		Maintenance::run();

		$this->assertNotNull( get_post( $expired ), 'Nothing runs again before the interval' );
	}

	public function test_retries_left_by_an_update_are_resumed_on_the_next_run(): void {
		Maintenance::run();
		$entry = self::factory()->post->create(
			array(
				'post_type'   => Plugin::ENTRY,
				'post_status' => 'private',
			)
		);
		update_post_meta( $entry, '_easyformly_retry', array( 'hash' => 'fixture' ) );
		Maintenance::resume_retries();

		Maintenance::run();

		$this->assertNotFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $entry ) ), 'The pending retry is scheduled again' );
		$this->assertNull( get_option( Maintenance::OPTION )['resume'], 'Nothing is left to resume' );
	}

	public function test_a_running_maintenance_is_not_started_twice(): void {
		$expired = $this->old_entry( 31 );
		$owner   = State::claim( 'maintenance', 120 );

		Maintenance::run();

		$this->assertNotNull( get_post( $expired ), 'A second request leaves the work to the first one' );
		State::release( 'maintenance', (string) $owner );
	}

	public function test_a_submission_runs_maintenance_at_the_end_of_the_request(): void {
		$id = $this->form( $this->definition() );

		$this->assertNotWPError( $this->submit( $id, $this->input( $id, $this->values() ) ) );

		$this->assertNotFalse( has_action( 'shutdown', array( Maintenance::class, 'run' ) ) );
	}
}
