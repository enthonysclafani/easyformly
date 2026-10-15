<?php
/**
 * Enforces the architectural invariants listed in CLAUDE.md.
 *
 * Usage: php tools/check-architecture.php
 *
 * Adapted from EasyRankly (branch Refactory). Every allowlist below describes the state of
 * EasyContact 1.0.0: an entry marked TODO is an exception to remove or to confirm with the
 * decision named next to it (docs/decisioni.md). Adding an entry needs Enthony's approval.
 *
 * Development-only file: excluded from the distributable (see .gitattributes).
 *
 * @package EasyFormly
 */

declare( strict_types=1 );

namespace EasyFormly\Tools;

/**
 * Scans the plugin source for constructs that CLAUDE.md forbids.
 *
 * The checks work on PHP tokens, not on raw text, so names inside comments
 * never trigger a violation and method calls are never mistaken for functions.
 */
final class ArchitectureChecker {

	/**
	 * The only stylesheets the plugin ships: one for its admin screens, one for the printed form.
	 *
	 * @var list<string>
	 */
	private const STYLESHEETS = array( 'assets/admin.css', 'assets/frontend.css' );

	/**
	 * Files and directories scanned, relative to the root.
	 *
	 * @var list<string>
	 */
	private const SCANNED_PATHS = array( 'easyformly.php', 'uninstall.php', 'src', 'includes' );

	/**
	 * Directories never scanned: bundled upstream libraries and data (THIRD-PARTY.md).
	 *
	 * @var list<string>
	 */
	private const SKIPPED_PATHS = array( 'includes/vendor/', 'includes/data/' );

	/**
	 * Files allowed to touch `$wpdb`. Each entry has a comment there explaining why the APIs fall short.
	 *
	 * @var list<string>
	 */
	private const WPDB_ALLOWLIST = array(
		// Atomic leases (INSERT IGNORE on the unique option_name), cache-bypassing reads and the bounded cleanup of
		// expired leases and states: D6. Rate counters use no lease with a persistent object cache.
		'src/Submissions/State.php',
		// Runs once: finds this plugin's dynamic option names (leases, states, transients) by prefix and deletes them
		// through the Options API. An index option would cost a write on every submission instead (plan point 2.3).
		'uninstall.php',
	);

	/**
	 * Files allowed to schedule or clear WP-Cron events.
	 *
	 * @var list<string>
	 */
	private const CRON_ALLOWLIST = array(
		// Removes the retry events on deactivation.
		'src/Plugin.php',
		// D1: `easyformly_retry_delivery`, the only event, for the bounded delivery retries.
		'src/Submissions/Submission.php',
		// Removes the events above.
		'uninstall.php',
	);

	/**
	 * Files allowed to load or print scripts and styles (D2): the form renderer and the admin.
	 *
	 * @var list<string>
	 */
	private const ASSET_ALLOWLIST = array( 'src/Forms/Renderer.php', 'src/Admin/Admin.php' );

	/**
	 * Files allowed to make HTTP requests (D3): delivery on submission and the manual list synchronization.
	 *
	 * @var list<string>
	 */
	private const HTTP_ALLOWLIST = array( 'src/Delivery/Delivery.php', 'src/AntiSpam/DisposableDomains.php' );

	/**
	 * Functions forbidden everywhere, grouped by the rule they break.
	 *
	 * @var array<string, list<string>>
	 */
	private const FORBIDDEN_FUNCTIONS = array(
		'no-custom-tables' => array( 'dbdelta', 'maybe_create_table', 'maybe_add_column' ),
		'no-disk-writes'   => array(
			'file_put_contents',
			'fopen',
			'fwrite',
			'fputs',
			'mkdir',
			'wp_mkdir_p',
			'rmdir',
			'unlink',
			'rename',
			'copy',
			'touch',
			'move_uploaded_file',
		),
		'no-raw-http'      => array( 'curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'stream_socket_client' ),
		'forbidden-php'    => array( 'create_function', 'extract', 'unserialize', 'assert' ),
	);

	/**
	 * Functions that hook into WordPress: under `src/` they are called from `boot()`/`register()` or from a callback
	 * they register, never at file level.
	 *
	 * @var list<string>
	 */
	private const HOOK_FUNCTIONS = array( 'add_action', 'add_filter', 'add_shortcode' );

	/**
	 * Functions allowed only in the files of an allowlist, by rule.
	 *
	 * @var array<string, list<string>>
	 */
	private const RESTRICTED_FUNCTIONS = array(
		'cron'   => array(
			'wp_schedule_event',
			'wp_schedule_single_event',
			'wp_reschedule_event',
			'wp_unschedule_event',
			'wp_unschedule_hook',
			'wp_clear_scheduled_hook',
			'as_schedule_single_action',
			'as_schedule_recurring_action',
			'as_enqueue_async_action',
		),
		'assets' => array(
			'wp_enqueue_script',
			'wp_enqueue_style',
			'wp_register_script',
			'wp_register_style',
			'wp_enqueue_script_module',
			'wp_add_inline_script',
			'wp_add_inline_style',
			'wp_localize_script',
			'wp_print_scripts',
			'wp_print_styles',
		),
		'http'   => array(
			'wp_remote_get',
			'wp_remote_post',
			'wp_remote_head',
			'wp_remote_request',
			'wp_safe_remote_get',
			'wp_safe_remote_post',
			'wp_safe_remote_head',
			'wp_safe_remote_request',
			'download_url',
		),
	);

	/**
	 * Hooks that load assets on every page; forms enqueue their assets only when printed (D2).
	 *
	 * @var list<string>
	 */
	private const FRONTEND_ASSET_HOOKS = array( 'wp_enqueue_scripts', 'enqueue_block_assets', 'wp_print_scripts', 'wp_print_styles' );

	/**
	 * Tokens that, right before a name, mean the name is not a global function call.
	 *
	 * @var list<int>
	 */
	private const NOT_A_FUNCTION_CALL = array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST );

	/**
	 * Absolute path of the plugin root, without trailing slash.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Violations found so far.
	 *
	 * @var list<array{file: string, line: int, rule: string, message: string}>
	 */
	private array $violations = array();

	/**
	 * Sets the plugin root to scan.
	 *
	 * @param string $root Absolute path of the plugin root.
	 */
	public function __construct( string $root ) {
		$this->root = rtrim( $root, '/' );
	}

	/**
	 * Runs every check and returns the violations.
	 *
	 * @return list<array{file: string, line: int, rule: string, message: string}>
	 */
	public function check(): array {
		$this->violations = array();

		foreach ( $this->collect_files() as $relative ) {
			$this->check_php_file( $relative );
		}

		$this->check_composer_json();
		$this->check_stylesheets();

		return $this->violations;
	}

	/**
	 * Lists scanned PHP files relative to the root, sorted for stable output.
	 *
	 * @return list<string>
	 */
	private function collect_files(): array {
		$files = array();

		foreach ( self::SCANNED_PATHS as $path ) {
			$absolute = $this->root . '/' . $path;

			if ( is_file( $absolute ) ) {
				$files[] = $path;
				continue;
			}

			if ( ! is_dir( $absolute ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $absolute, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || 'php' !== $file->getExtension() ) {
					continue;
				}

				$relative = substr( $file->getPathname(), strlen( $this->root ) + 1 );
				foreach ( self::SKIPPED_PATHS as $skipped ) {
					if ( str_starts_with( $relative, $skipped ) ) {
						continue 2;
					}
				}
				$files[] = $relative;
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Checks one PHP file token by token.
	 *
	 * @param string $relative Path relative to the root.
	 */
	private function check_php_file( string $relative ): void {
		$code = (string) file_get_contents( $this->root . '/' . $relative );

		try {
			$tokens = token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			$this->add( $relative, $error->getLine(), 'syntax', $error->getMessage() );
			return;
		}

		$this->check_direct_access_guard( $relative, $code );

		$count = count( $tokens );
		// One entry per open brace: whether it opens the body of a named function or method.
		$braces = array();
		$named  = false;

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				$braces[] = '{' === $token && $named;
				$named    = false;
			} elseif ( '}' === $token ) {
				array_pop( $braces );
			} elseif ( ';' === $token ) {
				// An abstract or interface method has no body.
				$named = false;
			} elseif ( is_array( $token ) && T_FUNCTION === $token[0] ) {
				$next  = $this->next_significant( $tokens, $i );
				$named = null !== $next && is_array( $tokens[ $next ] ) && T_STRING === $tokens[ $next ][0];
			}

			if ( ! is_array( $token ) ) {
				continue;
			}

			list( $id, $text, $line ) = $token;

			if ( T_EVAL === $id ) {
				$this->add( $relative, $line, 'forbidden-php', '`eval` is forbidden.' );
				continue;
			}

			if ( T_VARIABLE === $id && '$wpdb' === $text && ! in_array( $relative, self::WPDB_ALLOWLIST, true ) ) {
				$this->add( $relative, $line, 'no-direct-db', '`$wpdb` is forbidden here: use WP_Query, the meta APIs or the Options API.' );
				continue;
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $id || T_ENCAPSED_AND_WHITESPACE === $id || T_INLINE_HTML === $id ) {
				$this->check_string( $relative, $line, $text );
				continue;
			}

			if ( T_STRING !== $id && T_NAME_FULLY_QUALIFIED !== $id ) {
				continue;
			}

			$next = $this->next_significant( $tokens, $i );
			if ( null === $next || '(' !== $tokens[ $next ] ) {
				continue;
			}

			$previous = $this->previous_significant( $tokens, $i );
			if ( null !== $previous && is_array( $tokens[ $previous ] ) && in_array( $tokens[ $previous ][0], self::NOT_A_FUNCTION_CALL, true ) ) {
				continue;
			}

			$function_name = strtolower( ltrim( $text, '\\' ) );
			if ( in_array( $function_name, self::HOOK_FUNCTIONS, true ) && str_starts_with( $relative, 'src/' ) && ! in_array( true, $braces, true ) ) {
				$this->add( $relative, $line, 'hooks-in-register', "`{$function_name}()` at file level: hook from `boot()`/`register()` or from a callback they register." );
				continue;
			}

			$this->check_function_call( $relative, $line, $function_name );
		}
	}

	/**
	 * Checks a call to a global function.
	 *
	 * @param string $relative      Path relative to the root.
	 * @param int    $line          Line of the call.
	 * @param string $function_name Lowercase function name.
	 */
	private function check_function_call( string $relative, int $line, string $function_name ): void {
		foreach ( self::FORBIDDEN_FUNCTIONS as $rule => $functions ) {
			if ( in_array( $function_name, $functions, true ) ) {
				$this->add( $relative, $line, $rule, "`{$function_name}()` is forbidden (see CLAUDE.md)." );
				return;
			}
		}

		$allowlists = array(
			'cron'   => self::CRON_ALLOWLIST,
			'assets' => self::ASSET_ALLOWLIST,
			'http'   => self::HTTP_ALLOWLIST,
		);
		foreach ( self::RESTRICTED_FUNCTIONS as $rule => $functions ) {
			if ( in_array( $function_name, $functions, true ) && ! in_array( $relative, $allowlists[ $rule ], true ) ) {
				$this->add( $relative, $line, $rule, "`{$function_name}()` is allowed only in " . implode( ', ', $allowlists[ $rule ] ) . '.' );
				return;
			}
		}
	}

	/**
	 * Checks a string literal for SQL schema changes, inline styles and frontend asset hooks.
	 *
	 * @param string $relative Path relative to the root.
	 * @param int    $line     Line of the string.
	 * @param string $text     Raw token text, quotes included.
	 */
	private function check_string( string $relative, int $line, string $text ): void {
		if ( preg_match( '/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', $text ) ) {
			$this->add( $relative, $line, 'no-custom-tables', 'SQL schema changes are forbidden: store data with the native APIs.' );
			return;
		}

		if ( preg_match( '/\sstyle\s*=|<style\b/i', $text ) ) {
			$this->add( $relative, $line, 'no-inline-css', 'Inline CSS is forbidden: use the plugin stylesheets.' );
			return;
		}

		$value = trim( $text, '\'"' );
		if ( in_array( $value, self::FRONTEND_ASSET_HOOKS, true ) ) {
			$this->add( $relative, $line, 'assets', "The `{$value}` hook loads assets on every page: enqueue them when the form is printed." );
		}
	}

	/**
	 * Requires the direct-access guard at the top of every runtime PHP file.
	 *
	 * @param string $relative Path relative to the root.
	 * @param string $code     File contents.
	 */
	private function check_direct_access_guard( string $relative, string $code ): void {
		$constant = 'uninstall.php' === $relative ? 'WP_UNINSTALL_PLUGIN' : 'ABSPATH';

		if ( ! preg_match( '/defined\(\s*\'' . $constant . '\'\s*\)\s*\|\|\s*exit\s*;/', $code ) ) {
			$this->add( $relative, 1, 'direct-access-guard', "Missing `defined( '{$constant}' ) || exit;`." );
		}
	}

	/**
	 * Forbids runtime Composer dependencies: only PHP and extensions may be required.
	 */
	private function check_composer_json(): void {
		$path = $this->root . '/composer.json';
		if ( ! is_file( $path ) ) {
			return;
		}

		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			$this->add( 'composer.json', 1, 'syntax', 'Invalid JSON.' );
			return;
		}

		$require = isset( $data['require'] ) && is_array( $data['require'] ) ? $data['require'] : array();

		foreach ( array_keys( $require ) as $package ) {
			if ( 'php' !== $package && ! str_starts_with( (string) $package, 'ext-' ) ) {
				$this->add( 'composer.json', 1, 'no-runtime-deps', "Runtime dependency `{$package}` is forbidden: move it to require-dev or remove it." );
			}
		}
	}

	/**
	 * Allows only the listed stylesheets, each with every selector scoped to the plugin's classes or IDs.
	 */
	private function check_stylesheets(): void {
		$assets = $this->root . '/assets';
		if ( is_dir( $assets ) ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $assets, \FilesystemIterator::SKIP_DOTS ) );
			$found    = array();
			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && $file->isFile() && in_array( $file->getExtension(), array( 'css', 'scss', 'less' ), true ) ) {
					$found[] = substr( $file->getPathname(), strlen( $this->root ) + 1 );
				}
			}
			sort( $found );
			foreach ( array_diff( $found, self::STYLESHEETS ) as $relative ) {
				$this->add( $relative, 1, 'one-stylesheet', 'Only ' . implode( ', ', self::STYLESHEETS ) . ' are allowed: add the rules there.' );
			}
		}

		foreach ( self::STYLESHEETS as $relative ) {
			$path = $this->root . '/' . $relative;
			if ( ! is_file( $path ) ) {
				continue;
			}
			// Blank out comments, keeping their newlines so line numbers stay right.
			$css = (string) preg_replace_callback( '~/\*.*?\*/~s', static fn( array $comment ): string => (string) preg_replace( '/[^\n]/', ' ', $comment[0] ), (string) file_get_contents( $path ) );
			preg_match_all( '/([^{}]+)\{/', $css, $blocks, PREG_OFFSET_CAPTURE );
			foreach ( $blocks[1] as $block ) {
				$prelude = trim( $block[0] );
				if ( str_starts_with( $prelude, '@' ) || preg_match( '/^(from|to|\d+(\.\d+)?%)(\s*,\s*(from|to|\d+(\.\d+)?%))*$/', $prelude ) ) {
					continue;
				}
				foreach ( explode( ',', $prelude ) as $selector ) {
					if ( ! preg_match( '/[.#]easyformly[\w-]*/', $selector ) ) {
						$line = substr_count( $css, "\n", 0, $block[1] + strlen( $block[0] ) - strlen( ltrim( $block[0] ) ) ) + 1;
						$this->add( $relative, $line, 'one-stylesheet', 'Selector `' . trim( $selector ) . '` must be scoped to an .easyformly class or #easyformly ID.' );
					}
				}
			}
		}
	}

	/**
	 * Index of the next token that is not whitespace or a comment.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $index  Starting index.
	 */
	private function next_significant( array $tokens, int $index ): ?int {
		$count = count( $tokens );
		for ( $i = $index + 1; $i < $count; $i++ ) {
			if ( ! $this->is_insignificant( $tokens[ $i ] ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Index of the previous token that is not whitespace or a comment.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $index  Starting index.
	 */
	private function previous_significant( array $tokens, int $index ): ?int {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( ! $this->is_insignificant( $tokens[ $i ] ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Whether a token is whitespace or a comment.
	 *
	 * @param mixed $token Token.
	 */
	private function is_insignificant( $token ): bool {
		return is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
	}

	/**
	 * Records a violation.
	 *
	 * @param string $file    Path relative to the root.
	 * @param int    $line    Line number.
	 * @param string $rule    Rule identifier.
	 * @param string $message Explanation.
	 */
	private function add( string $file, int $line, string $rule, string $message ): void {
		$this->violations[] = array(
			'file'    => $file,
			'line'    => $line,
			'rule'    => $rule,
			'message' => $message,
		);
	}
}

// Run only when executed directly, so tests can load the class without side effects.
if ( 'cli' === PHP_SAPI && realpath( get_included_files()[0] ) === __FILE__ ) {
	$easyformly_violations = ( new ArchitectureChecker( dirname( __DIR__ ) ) )->check();

	foreach ( $easyformly_violations as $easyformly_violation ) {
		fwrite( STDERR, sprintf( "%s:%d  [%s] %s\n", $easyformly_violation['file'], $easyformly_violation['line'], $easyformly_violation['rule'], $easyformly_violation['message'] ) );
	}

	if ( array() !== $easyformly_violations ) {
		fwrite( STDERR, sprintf( "\n%d architecture violation(s). See CLAUDE.md, section \"Invarianti architetturali\".\n", count( $easyformly_violations ) ) );
		exit( 1 );
	}

	fwrite( STDOUT, "Architecture invariants: OK\n" );
}
