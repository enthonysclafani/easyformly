<?php
/**
 * Lazy maintenance without WP-Cron (D1): retention, expired locks and states, retries left after an update.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Submissions;

defined( 'ABSPATH' ) || exit;

/**
 * Run bounded batches on `admin_init` and after a submission, at most once a day once nothing is pending.
 *
 * The cursors live in one non-autoloaded option, so a page without a form never reads it.
 */
final class Maintenance {
	public const OPTION = 'easyformly_maintenance';

	/**
	 * Seconds between two complete runs.
	 */
	private const INTERVAL = 86400;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_init', array( self::class, 'run' ) );
	}

	/**
	 * Run at the end of a request that processed a submission, after its response is built.
	 *
	 * @return void
	 */
	public static function after_submission(): void {
		if ( ! has_action( 'shutdown', array( self::class, 'run' ) ) ) {
			add_action( 'shutdown', array( self::class, 'run' ) );
		}
	}

	/**
	 * Ask the next run to reschedule the retries left by an interrupted request, after an update or activation.
	 *
	 * @return void
	 */
	public static function resume_retries(): void {
		$state           = self::state();
		$state['resume'] = 0;
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Run one bounded batch of every pending task, under a lock so two requests never overlap.
	 *
	 * @return void
	 */
	public static function run(): void {
		$state = self::state();
		$due   = time() >= $state['due'];
		if ( ! $due && null === $state['resume'] ) {
			return;
		}
		$owner = State::claim( 'maintenance', 120 );
		if ( null === $owner ) {
			return;
		}
		try {
			// Read again under the lock: a competing run may have just finished.
			$state = self::state();
			if ( null !== $state['resume'] ) {
				$state['resume'] = Submission::resume( $state['resume'] );
			}
			if ( time() >= $state['due'] ) {
				$entries        = Entries::cleanup();
				$state['state'] = State::cleanup( $state['state'] ?? 0 );
				if ( ! $entries && null === $state['state'] ) {
					$state['due'] = time() + self::INTERVAL;
				}
			}
			update_option( self::OPTION, $state, false );
		} finally {
			State::release( 'maintenance', $owner );
		}
	}

	/**
	 * Stored cursors with defaults: due now, no state cursor, no retries to resume.
	 *
	 * @return array{due: int, state: int|null, resume: int|null} Result of this operation.
	 */
	private static function state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'due'    => isset( $stored['due'] ) && is_int( $stored['due'] ) ? $stored['due'] : 0,
			'state'  => isset( $stored['state'] ) && is_int( $stored['state'] ) ? $stored['state'] : null,
			'resume' => isset( $stored['resume'] ) && is_int( $stored['resume'] ) ? $stored['resume'] : null,
		);
	}
}
