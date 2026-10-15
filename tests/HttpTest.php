<?php
/**
 * Requests to external services, answered by simulated responses.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\AntiSpam\DisposableDomains;
use EasyFormly\Delivery\Delivery;
use EasyFormly\Forms\Renderer;
use EasyFormly\Settings\Settings;
use WP_Error;

/**
 * Remote calls are bounded and every answer, error included, has a defined result (CLAUDE.md, invariant 5).
 */
class HttpTest extends TestCase {

	/**
	 * Requests seen by the simulated services: URL and arguments.
	 *
	 * @var list<array{url: string, args: array}>
	 */
	private array $requests = array();

	/**
	 * Answer of the simulated service for the next requests; null leaves them to the base interceptor.
	 *
	 * @var array|WP_Error|null
	 */
	private $answer = null;

	/**
	 * Record every request before the base interceptor answers it.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $this->answer ?? $preempt;
			},
			5,
			3
		);
	}

	/**
	 * Every remote call has a short timeout, no redirects and a bounded response.
	 */
	public function test_every_outbound_request_is_bounded(): void {
		$this->captcha_settings();
		$id           = $this->form( $this->definition( array( 'captcha' => true ) ) );
		$this->answer = $this->http_response( 503, '' );

		Delivery::captcha( $id, 'token', wp_generate_uuid4() );
		Delivery::mailerlite( $this->mailerlite_config(), $this->values( array( 'newsletter' => '1' ) ) );
		Delivery::slack( $id, $this->definition( $this->slack_config() ), $this->values() );
		DisposableDomains::sync();

		$hosts = array_map( fn ( $request ) => wp_parse_url( $request['url'], PHP_URL_HOST ), $this->requests );
		$this->assertSame( array( 'challenges.cloudflare.com', 'connect.mailerlite.com', 'hooks.slack.com', 'raw.githubusercontent.com' ), $hosts, 'One request per service; a failed list source stops the synchronization' );
		foreach ( $this->requests as $request ) {
			$this->assertGreaterThan( 0, $request['args']['timeout'], $request['url'] );
			$this->assertLessThanOrEqual( 15, $request['args']['timeout'], 'Timeout of at most 15 seconds: ' . $request['url'] );
			$this->assertSame( 0, $request['args']['redirection'], 'No redirects: ' . $request['url'] );
			$this->assertGreaterThan( 0, $request['args']['limit_response_size'], 'Bounded response: ' . $request['url'] );
			$this->assertTrue( $request['args']['reject_unsafe_urls'], 'Only public hosts: ' . $request['url'] );
		}
	}

	/**
	 * Printing a form, with CAPTCHA too, never contacts a service: only the browser loads the widget.
	 */
	public function test_rendering_a_form_makes_no_request(): void {
		$this->captcha_settings();
		$definition = $this->definition( array_merge( $this->mailerlite_config(), $this->slack_config(), array( 'captcha' => true ) ) );

		$html = Renderer::render( $this->form( $definition ), $definition );

		$this->assertStringContainsString( 'data-easyformly-captcha', $html, 'The CAPTCHA widget is printed' );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * The CAPTCHA passes only with a successful answer for this site and this form.
	 */
	public function test_turnstile_accepts_only_a_matching_answer(): void {
		$id = $this->form( $this->definition( array( 'captcha' => true ) ) );

		$this->assertWPError( Delivery::captcha( $id, 'token' ), 'Without a secret key the CAPTCHA fails' );
		$this->assertSame( array(), $this->requests, 'Without a secret key nothing is sent' );

		$this->captcha_settings();
		$this->assertWPError( Delivery::captcha( $id, '' ), 'An empty token fails' );
		$this->assertWPError( Delivery::captcha( $id, str_repeat( 'a', 2049 ) ), 'An oversized token fails' );
		$this->assertSame( array(), $this->requests, 'Invalid tokens are refused before any request' );

		$valid = array(
			'success'  => true,
			'hostname' => 'EXAMPLE.org',
			'action'   => 'easyformly_form_' . $id,
		);
		$cases = array(
			'valid answer'     => array( $this->turnstile( $valid ), true ),
			'refused token'    => array( $this->turnstile( array_merge( $valid, array( 'success' => false ) ) ), false ),
			'another site'     => array( $this->turnstile( array_merge( $valid, array( 'hostname' => 'example.com' ) ) ), false ),
			'another form'     => array( $this->turnstile( array_merge( $valid, array( 'action' => 'easyformly_form_' . ( $id + 1 ) ) ) ), false ),
			'server error'     => array( $this->http_response( 500, (string) wp_json_encode( $valid ) ), false ),
			'invalid body'     => array( $this->http_response( 200, 'not json' ), false ),
			'unreachable'      => array( new WP_Error( 'http_request_failed', 'timeout' ), false ),
			'missing hostname' => array( $this->turnstile( array_diff_key( $valid, array( 'hostname' => true ) ) ), false ),
			'string success'   => array( $this->turnstile( array_merge( $valid, array( 'success' => 'true' ) ) ), false ),
		);
		foreach ( $cases as $name => list( $answer, $passes ) ) {
			$this->answer = $answer;
			$result       = Delivery::captcha( $id, 'token', wp_generate_uuid4() );
			if ( $passes ) {
				$this->assertTrue( $result, $name );
				continue;
			}
			$this->assertWPError( $result, $name );
			$this->assertSame( 422, $result->get_error_data()['status'], 'The visitor can retry: ' . $name );
		}

		$body = end( $this->requests )['args']['body'];
		$this->assertSame( array( 'secret', 'response', 'idempotency_key' ), array_keys( $body ), 'The visitor address is not sent' );
	}

	/**
	 * Every MailerLite answer becomes a success, a retryable error or a configuration error.
	 */
	public function test_mailerlite_answers_have_a_defined_result(): void {
		$config = $this->mailerlite_config();

		$this->assertSame( 'skipped', Delivery::mailerlite( $config, $this->values() ), 'Without consent there is no subscription' );
		$this->assertSame( array(), $this->requests, 'Without consent nothing is sent' );

		$cases = array(
			'created'          => array( $this->http_response( 201, '{"data":{"id":"123"}}' ), true ),
			'updated'          => array( $this->http_response( 200, '{"data":{"id":456}}' ), true ),
			'no subscriber id' => array( $this->http_response( 200, '{"data":{}}' ), 'mailerlite_uncertain' ),
			'invalid body'     => array( $this->http_response( 201, '<html>' ), 'mailerlite_uncertain' ),
			'validation error' => array( $this->http_response( 422, '{"message":"invalid"}' ), 'mailerlite_http_422' ),
			'rate limit'       => array( $this->http_response( 429, '' ), 'mailerlite_http_429' ),
			'unreachable'      => array( new WP_Error( 'http_request_failed', 'timeout' ), 'mailerlite_network' ),
		);
		foreach ( $cases as $name => list( $answer, $expected ) ) {
			$this->answer = $answer;
			$result       = Delivery::mailerlite( $config, $this->values( array( 'newsletter' => '1' ) ) );
			if ( true === $expected ) {
				$this->assertTrue( $result, $name );
				continue;
			}
			$this->assertWPError( $result, $name );
			$this->assertSame( $expected, $result->get_error_code(), $name );
		}

		$request = end( $this->requests );
		$this->assertSame( 'Bearer fixture-only', $request['args']['headers']['Authorization'], 'The token travels only in the header' );
		$this->assertSame(
			array(
				'email'  => 'Maria+preventivo@example.org',
				'groups' => array( '1' ),
				'fields' => array( 'name' => 'Maria Rossi' ),
			),
			json_decode( $request['args']['body'], true ),
			'Only the address, the name and the groups are sent: no status or resubscribe flag'
		);
	}

	/**
	 * Slack succeeds only on its documented "ok" answer.
	 */
	public function test_slack_answers_have_a_defined_result(): void {
		$id = $this->form( $this->definition() );

		$invalid = $this->definition( array( 'slack_webhook' => 'https://example.org/services/A/B/C' ) );
		$this->assertSame( 'slack_configuration', Delivery::slack( $id, $invalid, $this->values() )->get_error_code(), 'Only hooks.slack.com webhooks' );
		$this->assertSame( array(), $this->requests, 'An invalid webhook is never called' );

		$definition = $this->definition( $this->slack_config() );
		$cases      = array(
			'delivered'      => array( $this->http_response( 200, "ok\n" ), true ),
			'unknown answer' => array( $this->http_response( 200, 'no_text' ), 'slack_uncertain' ),
			'removed hook'   => array( $this->http_response( 404, 'no_service' ), 'slack_http_404' ),
			'rate limit'     => array( $this->http_response( 429, '' ), 'slack_http_429' ),
			'unreachable'    => array( new WP_Error( 'http_request_failed', 'timeout' ), 'slack_uncertain' ),
		);
		foreach ( $cases as $name => list( $answer, $expected ) ) {
			$this->answer = $answer;
			$result       = Delivery::slack( $id, $definition, $this->values() );
			if ( true === $expected ) {
				$this->assertTrue( $result, $name );
				continue;
			}
			$this->assertWPError( $result, $name );
			$this->assertSame( $expected, $result->get_error_code(), $name );
		}
	}

	/**
	 * Turnstile keys in the settings.
	 */
	private function captcha_settings(): void {
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::get(),
				array(
					'turnstile_site_key' => 'site-key_123',
					'turnstile_secret'   => 'fixture-secret',
				)
			),
			false
		);
	}

	/**
	 * Form configuration for MailerLite with the fixture fields.
	 *
	 * @return array
	 */
	private function mailerlite_config(): array {
		return array_merge(
			$this->definition()['config'],
			array(
				'mailerlite_enabled' => true,
				'mailerlite_email'   => 'email',
				'mailerlite_name'    => 'nome',
				'mailerlite_consent' => 'newsletter',
				'mailerlite_groups'  => '1',
			)
		);
	}

	/**
	 * Form configuration for Slack.
	 *
	 * @return array
	 */
	private function slack_config(): array {
		return array(
			'slack_enabled' => true,
			'slack_webhook' => 'https://hooks.slack.com/services/TEST/TEST/TEST',
			'slack_fields'  => 'nome,messaggio',
		);
	}

	/**
	 * A Turnstile verification answer.
	 *
	 * @param array $body Decoded answer.
	 * @return array
	 */
	private function turnstile( array $body ): array {
		return $this->http_response( 200, (string) wp_json_encode( $body ) );
	}
}
