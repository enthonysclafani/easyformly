<?php
/**
 * Disposable-domain list: bundled snapshot, manual synchronization and its safeguards.
 *
 * Converted from the former tests/disposable-domains.php (run with `studio wp eval-file`).
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\AntiSpam\DisposableDomains;
use EasyFormly\Settings\Admin\Page;
use RuntimeException;
use WP_Error;

/**
 * The list is used locally on every check and downloaded only when an administrator asks.
 */
class DisposableDomainsTest extends TestCase {

	/**
	 * Requests made to the two public sources.
	 *
	 * @var int
	 */
	private int $downloads = 0;

	/**
	 * How the broken source answers: valid, network, status, html, empty, short, malformed, oversized.
	 *
	 * @var string
	 */
	private string $mode = 'valid';

	/**
	 * Source that answers according to $mode.
	 *
	 * @var string
	 */
	private string $broken = 'aggregate';

	/**
	 * Body served by each source when it works.
	 *
	 * @var array<string, string>
	 */
	private array $feeds = array();

	/**
	 * A thousand shared domains, the minimum a complete source must list.
	 *
	 * @var list<string>
	 */
	private array $base = array();

	public function set_up(): void {
		parent::set_up();
		$this->base  = array_map( static fn( $i ) => 'throwaway-' . $i . '.example.test', range( 1, 1000 ) );
		$this->feeds = array(
			'community' => implode( "\n", array_merge( $this->base, array( 'MAILINATOR.COM', 'retired.example.test', 'mailinator.com' ) ) ),
			'aggregate' => implode( "\n", array_merge( $this->base, array( 'mailinator.com', 'retired.example.test', 'only-second.example.test' ) ) ),
		);
		add_filter( 'pre_http_request', array( $this, 'serve_sources' ), 5, 3 );
		add_filter(
			'wp_die_handler',
			static fn() => static function () {
				throw new RuntimeException( 'denied' );
			}
		);
		delete_option( DisposableDomains::OPTION );
	}

	/**
	 * Serve the two public lists; everything else falls through to the parent interceptor.
	 *
	 * @param mixed  $preempt Previous short-circuit value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return mixed
	 */
	public function serve_sources( $preempt, $args, $url ) {
		foreach ( DisposableDomains::sources() as $key => $source ) {
			if ( $url !== $source['url'] ) {
				continue;
			}
			++$this->downloads;
			if ( $key === $this->broken && 'network' === $this->mode ) {
				return new WP_Error( 'test_network', 'Simulated network failure.' );
			}
			$body = $this->feeds[ $key ];
			if ( $key === $this->broken ) {
				$body = match ( $this->mode ) {
					'html'      => '<html><body>Provider unavailable</body></html>',
					'empty'     => '',
					'short'     => "mailinator.com\nretired.example.test",
					'malformed' => $body . "\nhttps://example.org/path",
					'oversized' => str_repeat( 'a', 8 * 1024 * 1024 ),
					default     => $body,
				};
			}
			return $this->http_response( $key === $this->broken && 'status' === $this->mode ? 503 : 200, $body );
		}
		return $preempt;
	}

	public function test_bundled_snapshot_works_offline(): void {
		$status = DisposableDomains::status();
		$this->assertGreaterThan( 90000, $status['count'], 'Bundled public snapshot works before first synchronization' );
		$this->assertFalse( $status['synced'], 'Bundled snapshot is not marked as synchronized' );
		$this->assertTrue( DisposableDomains::contains( 'INBOX.MAILINATOR.COM' ), 'Bundled domains include case-insensitive parent matches' );
		$this->assertFalse( DisposableDomains::contains( 'easyformly-unrelated-mailinator.com' ), 'Unrelated suffixes do not match' );
		foreach ( array( 'gmail.com', 'outlook.com', 'proton.me', 'icloud.com', 'yahoo.com' ) as $provider ) {
			$this->assertFalse( DisposableDomains::contains( $provider ), 'Established mail providers remain outside the list: ' . $provider );
		}
		$this->assertSame( 0, $this->downloads, 'Reading the list and checking email domains perform no HTTP requests' );
	}

	public function test_synchronization_requires_an_administrator_post_and_nonce(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		wp_set_current_user( 0 );
		$this->assertDenied( 'Unauthenticated synchronization is rejected before HTTP' );

		$this->login_admin();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertDenied( 'Synchronization requires POST' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertDenied( 'Synchronization requires a valid administrator nonce' );
		$this->assertSame( 0, $this->downloads, 'Refused synchronizations download nothing' );

		Page::settings();
		ob_start();
		Page::settings_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Synchronize list', $html, 'Settings offer the manual synchronization' );
		$this->assertStringContainsString( 'form="easyformly-disposable-sync"', $html, 'The button submits a separate form' );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html, 'The separate form posts to admin-post.php' );
		$this->assertStringNotContainsString( 'blocked_email_domains', $html, 'Settings have no manual domain fields' );
		$this->assertStringNotContainsString( 'allowed_email_domains', $html, 'Settings have no manual domain fields' );
		$this->assertSame( 0, $this->downloads, 'Settings perform no background download' );
	}

	public function test_synchronization_replaces_the_list_only_with_two_valid_sources(): void {
		$cron   = get_option( 'cron' );
		$result = DisposableDomains::sync();
		$this->assertNotWPError( $result, 'Both public feeds are accepted' );
		$this->assertSame( 1003, $result['count'], 'Both public feeds are normalized, combined and deduplicated' );
		$this->assertSame( 2, $this->downloads, 'One request per source' );
		$this->assertTrue( $result['synced'], 'Synchronization is recorded' );
		$this->assertGreaterThan( 0, $result['updated_at'], 'Synchronization records its time' );
		$this->assertSame(
			array(
				'community' => 1002,
				'aggregate' => 1003,
			),
			$result['sources'],
			'Synchronization records source counts'
		);
		$saved = get_option( DisposableDomains::OPTION );
		$this->assertFalse( wp_load_alloptions()[ DisposableDomains::OPTION ] ?? false, 'Large downloaded lists are not autoloaded' );
		$this->assertTrue( DisposableDomains::contains( 'retired.example.test' ), 'Freshly synchronized domains are available' );
		$this->assertTrue( DisposableDomains::contains( 'sub.only-second.example.test' ), 'Freshly synchronized parent domains match' );

		foreach ( array( 'network', 'status', 'html', 'empty', 'short', 'malformed', 'oversized' ) as $mode ) {
			$this->mode = $mode;
			$this->assertWPError( DisposableDomains::sync(), 'A failed or invalid second feed is refused: ' . $mode );
			$this->assertSame( $saved, get_option( DisposableDomains::OPTION ), 'The last successful list is preserved: ' . $mode );
			$this->assertTrue( DisposableDomains::contains( 'retired.example.test' ), 'The last successful list stays active: ' . $mode );
		}

		$this->mode   = 'network';
		$this->broken = 'community';
		$before       = $this->downloads;
		$this->assertWPError( DisposableDomains::sync(), 'A failed first feed is refused' );
		$this->assertSame( $before + 1, $this->downloads, 'A failed first feed stops before fetching the next' );
		$this->assertSame( $saved, get_option( DisposableDomains::OPTION ), 'A failed first feed preserves the previous list' );

		$this->mode               = 'valid';
		$this->feeds['community'] = "\xEF\xBB\xBF# Public list\r\n" . implode( "\r\n", array_merge( $this->base, array( 'mailinator.com' ) ) ) . "\r\n";
		$this->feeds['aggregate'] = implode( "\n", array_merge( $this->base, array( 'mailinator.com', 'new.example.test' ) ) );
		$result                   = DisposableDomains::sync();
		$this->assertNotWPError( $result, 'A later synchronization accepts feeds with comments and BOM' );
		$this->assertSame( 1002, $result['count'], 'The later list replaces the previous one' );
		$this->assertFalse( DisposableDomains::contains( 'retired.example.test' ), 'Provider removals are respected' );
		$this->assertFalse( DisposableDomains::contains( 'only-second.example.test' ), 'Provider removals invalidate the local cache' );
		$this->assertTrue( DisposableDomains::contains( 'new.example.test' ), 'Provider additions are respected' );

		$before = $this->downloads;
		DisposableDomains::contains( 'new.example.test' );
		DisposableDomains::status();
		$this->assertSame( $before, $this->downloads, 'Using the list downloads nothing' );
		$this->assertSame( $cron, get_option( 'cron' ), 'Synchronizing creates no cron job' );
	}

	/**
	 * The manual synchronization is refused through wp_die() before any download.
	 *
	 * @param string $message Assertion message.
	 */
	private function assertDenied( string $message ): void {
		try {
			Page::sync_disposable_domains();
			$this->fail( $message );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'denied', $error->getMessage(), $message );
		}
	}

	public function tear_down(): void {
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}
}
