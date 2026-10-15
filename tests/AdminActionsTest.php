<?php
/**
 * Classic admin forms posted to admin-post.php.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\Forms\Admin\Editor;
use EasyFormly\Plugin;
use EasyFormly\Review\Admin\Screen;
use EasyFormly\Review\Review;
use EasyFormly\Settings\Admin\Page;
use EasyFormly\Submissions\Admin\EntryScreen;
use RuntimeException;

/**
 * Every admin action needs manage_options and a nonce for the same object (CLAUDE.md, "Sicurezza").
 */
class AdminActionsTest extends TestCase {

	/**
	 * Turn wp_die() and the final redirect into exceptions, so a handler stops before exit.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter(
			'wp_die_handler',
			static fn() => static function () {
				throw new RuntimeException( 'denied' );
			}
		);
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . esc_url_raw( $location ) );
			}
		);
	}

	/**
	 * Clear the simulated request.
	 */
	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		$GLOBALS['current_screen'] = null;
		parent::tear_down();
	}

	/**
	 * Every action registered on admin_post_* is one this test covers, and none is open to visitors.
	 */
	public function test_every_admin_action_is_covered(): void {
		global $wp_filter;

		// Register the plugin as wp-admin does, so an action added to any part of it shows up here.
		set_current_screen( 'dashboard' );
		Plugin::register();
		$actions = array();
		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( str_starts_with( $hook, 'admin_post_' ) && str_contains( $hook, 'easyformly' ) && has_action( $hook ) ) {
				$actions[] = $hook;
			}
		}
		sort( $actions );

		$this->assertSame(
			array(
				'admin_post_easyformly_approve',
				'admin_post_easyformly_classify',
				'admin_post_easyformly_preview',
				'admin_post_easyformly_retry',
				// The public form submission without JavaScript, covered by SubmissionTest and RestTest.
				'admin_post_easyformly_submit',
				'admin_post_easyformly_sync_disposable_domains',
				'admin_post_nopriv_easyformly_submit',
			),
			$actions
		);
	}

	/**
	 * Classification changes only with an administrator and the nonce of that entry.
	 */
	public function test_classification_needs_capability_and_nonce(): void {
		$entry = $this->entry_for_review();
		$other = $this->entry_for_review();
		$post  = array(
			'action'                   => 'easyformly_classify',
			'easyformly_review_entry'  => (string) $entry,
			'easyformly_review_status' => 'spam',
		);

		$this->assert_denied( array( Screen::class, 'action' ), $post, 'easyformly_review_' . $entry, 'easyformly_review_nonce', 'editor' );
		$this->assert_denied( array( Screen::class, 'action' ), $post, '', 'easyformly_review_nonce' );
		$this->assert_denied( array( Screen::class, 'action' ), $post, 'easyformly_review_' . $other, 'easyformly_review_nonce' );
		$this->assertSame( 'accepted', Review::category( $entry ), 'Refused requests change nothing' );

		$this->assert_redirect( array( Screen::class, 'action' ), $post, 'easyformly_review_' . $entry, 'easyformly_review_nonce', 'post.php?post=' . $entry . '&action=edit' );
		$this->assertSame( 'spam', Review::category( $entry ) );
	}

	/**
	 * Approval of a held entry needs an administrator and the nonce of that entry.
	 */
	public function test_approval_needs_capability_and_nonce(): void {
		$entry  = $this->entry_for_review( 'filled by bot' );
		$before = $this->calls['mail'];
		$this->assertTrue( Review::held( $entry ), 'Fixture entry is held as spam' );
		$post = array(
			'action'                  => 'easyformly_approve',
			'easyformly_review_entry' => (string) $entry,
		);

		$this->assert_denied( array( Screen::class, 'action' ), $post, 'easyformly_review_' . $entry, 'easyformly_review_nonce', 'editor' );
		$this->assert_denied( array( Screen::class, 'action' ), $post, '', 'easyformly_review_nonce' );
		$this->assertTrue( Review::held( $entry ), 'Refused approvals release nothing' );
		$this->assertSame( $before, $this->calls['mail'], 'Refused approvals deliver nothing' );

		$this->assert_redirect( array( Screen::class, 'action' ), $post, 'easyformly_review_' . $entry, 'easyformly_review_nonce', 'post.php?post=' . $entry . '&action=edit' );
		$this->assertFalse( Review::held( $entry ), 'Approval releases the entry' );
		$this->assertSame( $before + 1, $this->calls['mail'], 'Approval delivers the email' );
	}

	/**
	 * A manual retry needs an administrator and the nonce of that entry.
	 */
	public function test_retry_needs_capability_and_nonce(): void {
		$this->slack_code = 429;
		$entry            = $this->entry_for_review(
			'',
			array(
				'slack_enabled' => true,
				'slack_webhook' => 'https://hooks.slack.com/services/TEST/TEST/TEST',
			)
		);
		$this->slack_code = 200;
		$before           = $this->calls['slack'];
		$post             = array( 'easyformly_retry_entry' => (string) $entry );

		$this->assert_denied( array( EntryScreen::class, 'retry' ), $post, 'easyformly_retry_' . $entry, 'easyformly_retry_nonce', 'editor' );
		$this->assert_denied( array( EntryScreen::class, 'retry' ), $post, '', 'easyformly_retry_nonce' );
		$this->assert_denied( array( EntryScreen::class, 'retry' ), $post, 'easyformly_review_' . $entry, 'easyformly_retry_nonce' );
		$this->assertSame( $before, $this->calls['slack'], 'Refused retries deliver nothing' );
		$this->assertNotFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $entry ) ), 'Refused retries keep the scheduled attempt' );

		$this->assert_redirect( array( EntryScreen::class, 'retry' ), $post, 'easyformly_retry_' . $entry, 'easyformly_retry_nonce', 'post.php?post=' . $entry . '&action=edit' );
		$this->assertSame( $before + 1, $this->calls['slack'], 'The retry delivers the failed destination' );
	}

	/**
	 * The preview opens only for an administrator with the nonce of that form.
	 */
	public function test_preview_needs_capability_and_nonce(): void {
		$form  = $this->form( $this->definition() );
		$other = $this->form( $this->definition() );
		$get   = array( 'id' => (string) $form );

		$this->assert_denied( array( Editor::class, 'preview' ), $get, 'easyformly_preview_' . $form, '_wpnonce', 'editor', 'GET' );
		$this->assert_denied( array( Editor::class, 'preview' ), $get, '', '_wpnonce', 'administrator', 'GET' );
		$this->assert_denied( array( Editor::class, 'preview' ), $get, 'easyformly_preview_' . $other, '_wpnonce', 'administrator', 'GET' );
		$this->assert_denied( array( Editor::class, 'preview' ), array( 'id' => (string) $this->entry_for_review() ), 'easyformly_preview_' . $form, '_wpnonce', 'administrator', 'GET' );

		// The preview ends with exit: stop it when it reads the charset for its headers, after every check.
		add_filter(
			'pre_option_blog_charset',
			static function () {
				throw new RuntimeException( 'allowed' );
			}
		);
		$this->assertSame( 'allowed', $this->run_handler( array( Editor::class, 'preview' ), $get, 'easyformly_preview_' . $form, '_wpnonce', 'administrator', 'GET' ), 'An administrator with the nonce of the form opens the preview' );
	}

	/**
	 * The list synchronization needs an administrator, POST and its nonce; DisposableDomainsTest covers the rest.
	 */
	public function test_synchronization_refuses_editors(): void {
		$this->assert_denied( array( Page::class, 'sync_disposable_domains' ), array(), 'easyformly_sync_disposable_domains', '_wpnonce', 'editor' );
	}

	/**
	 * An archived entry from a real submission: accepted, or held as spam when the honeypot is filled.
	 *
	 * @param string $trap   Honeypot value.
	 * @param array  $config Form configuration overrides.
	 * @return int Entry ID.
	 */
	private function entry_for_review( string $trap = '', array $config = array() ): int {
		$id     = $this->form( $this->definition( $config ) );
		$result = $this->submit( $id, $this->input( $id, $this->values(), $trap ) );
		$this->assertNotWPError( $result, 'Fixture submission is accepted' );
		return $this->entry();
	}

	/**
	 * Run a handler as a request and require that it stops with wp_die().
	 *
	 * @param callable $handler Admin action handler.
	 * @param array    $fields  Request fields.
	 * @param string   $nonce   Nonce action to sign; empty for no nonce.
	 * @param string   $field   Nonce field name.
	 * @param string   $role    Role of the current user.
	 * @param string   $method  HTTP method.
	 */
	private function assert_denied( callable $handler, array $fields, string $nonce, string $field, string $role = 'administrator', string $method = 'POST' ): void {
		$this->assertSame( 'denied', $this->run_handler( $handler, $fields, $nonce, $field, $role, $method ), $role . ' with nonce "' . $nonce . '"' );
	}

	/**
	 * Run a handler as an administrator and require the redirect back to the given admin path.
	 *
	 * @param callable $handler Admin action handler.
	 * @param array    $fields  Request fields.
	 * @param string   $nonce   Nonce action to sign.
	 * @param string   $field   Nonce field name.
	 * @param string   $path    Expected admin path.
	 */
	private function assert_redirect( callable $handler, array $fields, string $nonce, string $field, string $path ): void {
		$this->assertSame( 'redirect:' . admin_url( $path ), $this->run_handler( $handler, $fields, $nonce, $field, 'administrator', 'POST' ) );
	}

	/**
	 * Simulate the request and return how the handler stopped.
	 *
	 * @param callable $handler Admin action handler.
	 * @param array    $fields  Request fields.
	 * @param string   $nonce   Nonce action to sign; empty for no nonce.
	 * @param string   $field   Nonce field name.
	 * @param string   $role    Role of the current user.
	 * @param string   $method  HTTP method.
	 * @return string "denied" or "redirect:" and the location.
	 */
	private function run_handler( callable $handler, array $fields, string $nonce, string $field, string $role, string $method ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		if ( '' !== $nonce ) {
			$fields[ $field ] = wp_create_nonce( $nonce );
		}
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = 'GET' === $method ? $fields : array();
		$_POST                     = 'POST' === $method ? $fields : array();
		$_REQUEST                  = $fields;
		try {
			call_user_func( $handler );
		} catch ( RuntimeException $stop ) {
			return $stop->getMessage();
		}
		$this->fail( 'The handler returned without stopping' );
	}
}
