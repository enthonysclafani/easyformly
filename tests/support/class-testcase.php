<?php
/**
 * Base test case: form fixtures, simulated visitors and intercepted outbound delivery.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\AntiSpam\DisposableDomains;
use EasyFormly\Forms\Forms;
use EasyFormly\Forms\Schema;
use EasyFormly\Plugin;
use EasyFormly\Settings\Settings;
use EasyFormly\Submissions\Endpoint;
use EasyFormly\Submissions\State;
use WP_Error;
use WP_UnitTestCase;

/**
 * Every test starts from a clean database (the core suite rolls it back) with mail and
 * HTTP intercepted: nothing leaves the test process.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Deliveries seen by the interceptors, per destination.
	 *
	 * @var array<string, int>
	 */
	protected array $calls = array(
		'mail'       => 0,
		'slack'      => 0,
		'mailerlite' => 0,
	);

	/**
	 * HTTP status returned by the simulated Slack webhook.
	 *
	 * @var int
	 */
	protected int $slack_code = 200;

	/**
	 * Ticket of the last simulated submission, used to find its receipt.
	 *
	 * @var string
	 */
	protected string $last_ticket = '';

	/**
	 * Counter that gives every simulated visitor its own address and rate-limit bucket.
	 *
	 * @var int
	 */
	private int $visitor = 0;

	/**
	 * Intercept mail and HTTP, and start from known settings.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter(
			'pre_wp_mail',
			function () {
				++$this->calls['mail'];
				return true;
			}
		);
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'mailerlite_token' => 'fixture-only' ) ), false );
		$GLOBALS['easyformly_test_dns'] = array();
		// The parsed list is kept per request in a static property: start every test without it.
		( new \ReflectionProperty( DisposableDomains::class, 'lists' ) )->setValue( null, array() );
	}

	/**
	 * Clear request globals and queued assets that tests change.
	 */
	public function tear_down(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_REQUEST['_wpnonce'] );
		// A rendered form enqueues its assets: the next test starts without them. The registries
		// stay, because rebuilding them needs built core files that the 6.4 test checkout lacks.
		$queued = array(
			'wp_scripts' => array( 'easyformly-frontend', 'easyformly-turnstile', 'easyformly-admin' ),
			'wp_styles'  => array( 'easyformly-frontend', 'easyformly-admin' ),
		);
		foreach ( $queued as $registry => $handles ) {
			if ( $GLOBALS[ $registry ] ?? null ) {
				$GLOBALS[ $registry ]->dequeue( $handles );
			}
		}
		parent::tear_down();
	}

	/**
	 * Answer Slack and MailerLite like the real services; refuse every other host not already answered.
	 *
	 * @param mixed  $preempt Previous short-circuit value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return array|WP_Error
	 */
	public function intercept_http( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		if ( str_contains( $url, 'hooks.slack.com' ) ) {
			++$this->calls['slack'];
			return $this->http_response( $this->slack_code, 'ok' );
		}
		if ( str_contains( $url, 'connect.mailerlite.com' ) ) {
			++$this->calls['mailerlite'];
			return $this->http_response( 201, '{"data":{"id":"123"}}' );
		}
		return new WP_Error( 'test_external', 'External HTTP disabled in tests.' );
	}

	/**
	 * Build a response array as WP_Http returns it.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 * @return array
	 */
	protected function http_response( int $code, string $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => array(),
			'body'     => $body,
			'cookies'  => array(),
		);
	}

	/**
	 * A form definition with the standard contact fields.
	 *
	 * @param array  $config   Configuration overrides.
	 * @param string $template Tag template; empty for the default fixture.
	 * @return array
	 */
	protected function definition( array $config = array(), string $template = '' ): array {
		$template = '' !== $template ? $template : '[text* nome "Nome"]' . "\n" . '[email* email "Email"]' . "\n" . '[tel telefono "Telefono"]' . "\n" . '[textarea* messaggio "Messaggio"]' . "\n" . '[checkbox newsletter "Newsletter"]';
		$schema   = Schema::parse( $template );
		$this->assertNotWPError( $schema, 'Fixture schema parses' );
		return array(
			'template'   => $template,
			'schema'     => $schema,
			'conditions' => Schema::rules( $schema['fields'], array() ),
			'config'     => array_merge(
				Forms::config_defaults(),
				array(
					'antispam_level'   => '2',
					'antispam_observe' => 'no',
					'email_dns_check'  => 'no',
					'store_entries'    => 'yes',
					'mail_to'          => 'receiver@example.org',
					'phone_country'    => 'IT',
				),
				$config
			),
		);
	}

	/**
	 * Publish a form with the given definition.
	 *
	 * @param array $definition Form definition.
	 * @return int Form ID.
	 */
	protected function form( array $definition ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Plugin::FORM,
				'post_status' => 'publish',
				'post_title'  => 'EasyFormly test form',
			)
		);
		update_post_meta( $id, '_easyformly_definition', wp_slash( $definition ) );
		return $id;
	}

	/**
	 * Valid field values, with overrides.
	 *
	 * @param array $changes Values to replace.
	 * @return array
	 */
	protected function values( array $changes = array() ): array {
		return array_merge(
			array(
				'nome'       => 'Maria Rossi',
				'email'      => 'Maria+preventivo@example.org',
				'telefono'   => '+390236618300',
				'messaggio'  => 'Vorrei conoscere la disponibilità del servizio per il prossimo mese.',
				'newsletter' => '',
			),
			$changes
		);
	}

	/**
	 * The request body a browser sends, with fresh signed tokens.
	 *
	 * @param int    $id     Form ID.
	 * @param array  $values Field values.
	 * @param string $trap   Honeypot value; non-empty for a bot.
	 * @return array
	 */
	protected function input( int $id, array $values, string $trap = '' ): array {
		$token = Endpoint::issue( $id );
		// A signed start time from a human-length interaction, without sleeping.
		$uuid   = wp_generate_uuid4();
		$issued = time() - 10;
		$clock  = $issued . '.' . $uuid . '.' . hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . $issued . '|' . $uuid . '|clock', wp_salt( 'auth' ) );
		return array(
			'fields'    => $values,
			'countries' => array( 'telefono' => 'IT' ),
			'nonce'     => $token['nonce'],
			'ticket'    => $token['ticket'],
			'clock'     => $clock,
			'honeypot'  => array( $token['honeypot'] => $trap ),
		);
	}

	/**
	 * Submit as a new visitor, with its own address.
	 *
	 * @param int   $id    Form ID.
	 * @param array $input Request body.
	 * @return array|WP_Error
	 */
	protected function submit( int $id, array $input ) {
		$this->last_ticket      = $input['ticket'];
		$_SERVER['REMOTE_ADDR'] = '2001:db8::' . dechex( ++$this->visitor );
		return Endpoint::submit( $id, $input );
	}

	/**
	 * The archive entry created by the last submission.
	 *
	 * @return int Entry ID.
	 */
	protected function entry(): int {
		$state = State::read( substr( hash( 'sha256', $this->last_ticket ), 0, 40 ) );
		$entry = (int) ( $state['entry'] ?? 0 );
		$this->assertGreaterThan( 0, $entry, 'Submission has one archive entry' );
		return $entry;
	}

	/**
	 * An assessment as AntiSpam::assess() returns it.
	 *
	 * @param string $status Category.
	 * @return array
	 */
	protected function assessment( string $status ): array {
		return array(
			'status'   => $status,
			'detected' => $status,
			'score'    => 0,
			'level'    => 2,
			'observe'  => false,
			'reasons'  => array(),
		);
	}

	/**
	 * Log in as a new administrator.
	 *
	 * @return int User ID.
	 */
	protected function login_admin(): int {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		return $user;
	}
}
