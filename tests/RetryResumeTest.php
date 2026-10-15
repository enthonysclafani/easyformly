<?php
/**
 * Resuming interrupted delivery retries after an update.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\Plugin;
use EasyFormly\Submissions\Submission;

/**
 * Submission::resume() walks pending retry jobs in batches and schedules each one once.
 */
class RetryResumeTest extends TestCase {

	/**
	 * Archived entries with a pending retry job.
	 *
	 * @param int $count Entries to create.
	 * @return list<int> Entry IDs, ascending.
	 */
	private function pending( int $count ): array {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$id = self::factory()->post->create(
				array(
					'post_type'   => Plugin::ENTRY,
					'post_status' => 'private',
				)
			);
			update_post_meta(
				$id,
				'_easyformly_retry',
				array(
					'hash'     => 'fixture',
					'attempts' => 1,
					'expires'  => time() + HOUR_IN_SECONDS,
				)
			);
			$ids[] = $id;
		}
		return $ids;
	}

	public function test_resume_schedules_every_pending_job_in_batches(): void {
		$ids     = $this->pending( 51 );
		$without = self::factory()->post->create(
			array(
				'post_type'   => Plugin::ENTRY,
				'post_status' => 'private',
			)
		);
		$foreign = self::factory()->post->create();
		update_post_meta( $foreign, '_easyformly_retry', array( 'hash' => 'fixture' ) );

		$cursor = Submission::resume();
		foreach ( array_slice( $ids, 0, 50 ) as $id ) {
			$this->assertNotFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $id ) ), 'The first batch schedules its retries' );
		}
		$this->assertFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $ids[50] ) ), 'A batch holds at most 50 jobs' );
		$this->assertSame( $ids[49], $cursor, 'The next batch starts after the last processed entry' );
		$this->assertFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $without ) ), 'Entries without a job are ignored' );
		$this->assertFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $foreign ) ), 'Posts that are not entries are ignored' );

		// Retries that finish before the next batch delete their job: the cursor must not skip anything.
		foreach ( array_slice( $ids, 0, 10 ) as $id ) {
			Submission::cancel( $id );
		}
		$this->assertNull( Submission::resume( $ids[49] ), 'A short batch is the last one' );
		$this->assertNotFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $ids[50] ) ), 'The second batch picks up the remaining job' );
	}

	public function test_resume_does_not_schedule_a_job_twice(): void {
		$ids = $this->pending( 1 );
		Submission::resume();
		Submission::resume();
		$crons = _get_cron_array();
		$count = 0;
		foreach ( $crons as $events ) {
			$count += count( $events['easyformly_retry_delivery'] ?? array() );
		}
		$this->assertSame( 1, $count, 'One scheduled retry per entry' );
		$this->assertNotFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $ids[0] ) ) );
	}
}
