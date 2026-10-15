<?php
/**
 * Public REST routes of the form: what an anonymous visitor can and cannot do.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\Plugin;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The two routes are public by design (forms are public); every check lives in the callbacks.
 */
class RestTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_token_is_issued_only_for_published_forms_and_never_cached(): void {
		$published = $this->form( $this->definition() );
		$draft     = self::factory()->post->create(
			array(
				'post_type'   => Plugin::FORM,
				'post_status' => 'draft',
			)
		);
		$other     = self::factory()->post->create();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/easyformly/v1/forms/' . $published . '/token' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store, private, max-age=0', $response->get_headers()['Cache-Control'] );
		$this->assertSame( array( 'nonce', 'ticket', 'expires', 'honeypot', 'clock' ), array_keys( $response->get_data() ) );

		foreach ( array( $draft, $other, 999999 ) as $id ) {
			$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/easyformly/v1/forms/' . $id . '/token' ) )->get_status(), 'No token for post ' . $id );
		}
	}

	public function test_submission_refuses_missing_tokens_and_oversized_bodies(): void {
		$id = $this->form( $this->definition() );

		$request = new WP_REST_Request( 'POST', '/easyformly/v1/forms/' . $id . '/submit' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'fields' => $this->values() ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status(), 'Without nonce and ticket the session is expired' );

		$request->set_body( (string) wp_json_encode( array( 'fields' => array( 'messaggio' => str_repeat( 'a', 50001 ) ) ) ) );
		$this->assertSame( 413, rest_do_request( $request )->get_status(), 'Bodies over 50 KB are refused' );
		$this->assertSame( 0, array_sum( $this->calls ), 'Nothing is delivered' );
	}

	public function test_routes_declare_typed_args_and_refuse_malformed_parameters(): void {
		$id     = $this->form( $this->definition() );
		$routes = rest_get_server()->get_routes();
		foreach ( array( 'token', 'submit' ) as $name ) {
			$endpoint = $routes[ '/easyformly/v1/forms/(?P<id>\\d+)/' . $name ][0];
			$this->assertSame( '__return_true', $endpoint['permission_callback'], 'Public by design: forms are shown to anonymous visitors' );
			$this->assertSame( 'integer', $endpoint['args']['id']['type'] );
		}

		$this->assertSame( 400, rest_do_request( new WP_REST_Request( 'GET', '/easyformly/v1/forms/0/token' ) )->get_status(), 'Form IDs start at 1' );
		$token = new WP_REST_Request( 'GET', '/easyformly/v1/forms/' . $id . '/token' );
		$token->set_query_params( array( 'clock' => str_repeat( '1', 201 ) ) );
		$this->assertSame( 400, rest_do_request( $token )->get_status(), 'An oversized clock is refused' );
		$this->assertSame( 2048, $routes['/easyformly/v1/forms/(?P<id>\\d+)/submit'][0]['args']['captcha']['maxLength'], 'The CAPTCHA token has the same limit as Delivery::captcha()' );

		$request = new WP_REST_Request( 'POST', '/easyformly/v1/forms/' . $id . '/submit' );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( array(
			'nonce'    => array( 'a' ),
			'ticket'   => 123,
			'fields'   => 'text',
			'honeypot' => 'text',
		) as $param => $value ) {
			$request->set_body( (string) wp_json_encode( array( $param => $value ) ) );
			$response = rest_do_request( $request );
			$this->assertSame( 400, $response->get_status(), 'Wrong type for ' . $param );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		}
		$this->assertSame( 0, array_sum( $this->calls ), 'Nothing is delivered' );
	}

	public function test_forms_and_entries_are_not_exposed_by_core_routes(): void {
		$routes = rest_get_server()->get_routes();
		foreach ( array_keys( $routes ) as $route ) {
			$this->assertStringNotContainsString( 'easyformly_', $route, 'No core route for the private post types' );
		}
		$this->assertFalse( get_post_type_object( Plugin::FORM )->show_in_rest );
		$this->assertFalse( get_post_type_object( Plugin::ENTRY )->show_in_rest );
		$this->assertFalse( get_post_type_object( Plugin::ENTRY )->publicly_queryable );
	}
}
