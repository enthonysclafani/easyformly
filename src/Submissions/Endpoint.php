<?php
/**
 * Public REST and form-post submission endpoints with signed tickets.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Submissions;

use EasyFormly\Forms\Forms;
use EasyFormly\Forms\Schema;
use EasyFormly\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Issue signed tickets and accept anonymous submissions.
 */
final class Endpoint {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_post_easyformly_submit', array( self::class, 'native_submit' ) );
		add_action( 'admin_post_nopriv_easyformly_submit', array( self::class, 'native_submit' ) );
	}

	/**
	 * Register the two anonymous form routes.
	 *
	 * Both are public by design: a published form is shown to visitors who are not logged in, so
	 * `permission_callback` is `__return_true`. The callbacks accept only published forms and check the rate limit,
	 * the nonce, the signed ticket, the honeypot and the clock; `args` declare the shape of each parameter, and the
	 * body is still validated by `submit()`, shared with the classic POST fallback.
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			'easyformly/v1',
			'/forms/(?P<id>\d+)/token',
			array(
				'methods'             => 'GET',
				// Public forms: anonymous visitors need a ticket. See the method docblock.
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'rest_token' ),
				'args'                => array(
					'id'    => self::arg( 'integer', array( 'minimum' => 1 ), true ),
					// Signed start time of a form already shown; an invalid one is replaced.
					'clock' => self::arg( 'string', array( 'maxLength' => 200 ) ),
				),
			)
		);
		register_rest_route(
			'easyformly/v1',
			'/forms/(?P<id>\d+)/submit',
			array(
				'methods'             => 'POST',
				// Public forms: anonymous visitors submit them. See the method docblock.
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'rest_submit' ),
				'args'                => array(
					'id'        => self::arg( 'integer', array( 'minimum' => 1 ), true ),
					// Missing or wrong values are answered by submit(), as for the classic POST.
					'nonce'     => self::arg( 'string', array( 'maxLength' => 64 ) ),
					'ticket'    => self::arg( 'string', array( 'maxLength' => 200 ) ),
					'clock'     => self::arg( 'string', array( 'maxLength' => 200 ) ),
					'captcha'   => self::arg( 'string', array( 'maxLength' => 2048 ) ),
					'fields'    => self::arg( 'object' ),
					'countries' => self::arg( 'object' ),
					'honeypot'  => self::arg( 'object' ),
				),
			)
		);
	}

	/**
	 * Schema of one route parameter, validated and sanitized by the REST API.
	 *
	 * @param string               $type     JSON schema type.
	 * @param array<string, mixed> $schema   Extra schema keywords.
	 * @param bool                 $required Whether the parameter is required.
	 * @return array<string, mixed> Result of this operation.
	 */
	private static function arg( string $type, array $schema = array(), bool $required = false ): array {
		return array(
			'type'              => $type,
			'required'          => $required,
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'rest_sanitize_request_arg',
		) + $schema;
	}

	/**
	 * Check that a published form has a saved definition.
	 *
	 * @param int $id Native WordPress post ID.
	 * @return bool Result of this operation.
	 */
	public static function available( int $id ): bool {
		$post = get_post( $id );
		return $post && Plugin::FORM === $post->post_type && 'publish' === $post->post_status && null !== Forms::definition( $id );
	}

	/**
	 * Issue a fresh signed ticket, nonce and UTC expiry.
	 *
	 * @param int    $id Native WordPress post ID.
	 * @param string $clock Signed start time to keep; a new one is issued when it is invalid.
	 * @return array{nonce: string, ticket: string, expires: int, honeypot: string, clock: string} Result of this operation.
	 */
	public static function issue( int $id, string $clock = '' ): array {
		$issued    = time();
		$uuid      = wp_generate_uuid4();
		$signature = hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . $issued . '|' . $uuid . '|2', wp_salt( 'auth' ) );
		$ticket    = $issued . '.' . $uuid . '.2.' . $signature;
		if ( false === self::elapsed( $id, $clock ) ) {
			$clock = $issued . '.' . $uuid . '.' . hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . $issued . '|' . $uuid . '|clock', wp_salt( 'auth' ) );
		}
		return array(
			'nonce'    => wp_create_nonce( 'easyformly_submit_' . get_current_blog_id() . '_' . $id ),
			'ticket'   => $ticket,
			'expires'  => $issued + HOUR_IN_SECONDS,
			'honeypot' => self::honeypot_name( $id, $ticket ),
			'clock'    => $clock,
		);
	}

	/**
	 * Field name of the honeypot, unpredictable per ticket.
	 *
	 * @param int    $id     Form ID.
	 * @param string $ticket Signed ticket.
	 */
	public static function honeypot_name( int $id, string $ticket ): string {
		return 'contact_' . substr( hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . ( explode( '.', $ticket )[1] ?? '' ), wp_salt( 'auth' ) ), 0, 12 );
	}

	/**
	 * A separate signed start time survives ticket refreshes and validation corrections.
	 *
	 * @param int    $id    Form ID.
	 * @param string $clock Signed start time.
	 * @return int|false Seconds since the form was first shown, or false when the clock is invalid.
	 */
	private static function elapsed( int $id, string $clock ) {
		if ( ! preg_match( '/^(\d{1,11})\.([a-f0-9-]{36})\.([a-f0-9]{64})$/D', $clock, $parts ) || (int) $parts[1] > time() + 60 || time() - (int) $parts[1] > DAY_IN_SECONDS ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . $parts[1] . '|' . $parts[2] . '|clock', wp_salt( 'auth' ) );
		return hash_equals( $expected, $parts[3] ) ? max( 0, time() - (int) $parts[1] ) : false;
	}

	/**
	 * Verify a form-bound ticket and its one-hour lifetime.
	 *
	 * @param int    $id Native WordPress post ID.
	 * @param string $ticket Signed submission ticket.
	 * @return bool Result of this operation.
	 */
	private static function valid_ticket( int $id, string $ticket ): bool {
		if ( ! preg_match( '/^(\d{1,11})\.([a-f0-9-]{36})\.2\.([a-f0-9]{64})$/D', $ticket, $parts ) || (int) $parts[1] > time() + 60 || time() - (int) $parts[1] > HOUR_IN_SECONDS ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . $parts[1] . '|' . $parts[2] . '|2', wp_salt( 'auth' ) );
		return hash_equals( $expected, $parts[3] );
	}

	/**
	 * Issue uncached tokens for a published form.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response|WP_Error Result of this operation.
	 */
	public static function rest_token( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! self::available( $id ) ) {
			return new WP_Error( 'unavailable', __( 'Form not available.', 'easyformly' ), array( 'status' => 404 ) );
		}
		if ( ! State::rate_allowed( $id, 120, 'token' ) ) {
			return new WP_Error( 'rate', __( 'Too many requests in a short time. Wait a few minutes.', 'easyformly' ), array( 'status' => 429 ) );
		}
		$clock    = $request->get_param( 'clock' );
		$response = new WP_REST_Response( self::issue( $id, is_string( $clock ) ? $clock : '' ) );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		return $response;
	}

	/**
	 * Bound the REST body and delegate to common submission validation.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return array<string, mixed>|WP_Error Result of this operation.
	 */
	public static function rest_submit( WP_REST_Request $request ) {
		if ( strlen( $request->get_body() ) > 50000 ) {
			return new WP_Error( 'size', __( 'The message is too long.', 'easyformly' ), array( 'status' => 413 ) );
		}
		return self::submit( (int) $request['id'], (array) $request->get_json_params() );
	}

	/**
	 * Validate availability, nonce, ticket and fields before any delivery.
	 *
	 * @param int                  $id Native WordPress post ID.
	 * @param array<string, mixed> $input Untrusted input to validate.
	 * @return array<string, mixed>|WP_Error Result of this operation.
	 */
	public static function submit( int $id, array $input ) {
		$definition = self::available( $id ) ? Forms::definition( $id ) : null;
		if ( null === $definition ) {
			return new WP_Error( 'unavailable', __( 'Form not available.', 'easyformly' ), array( 'status' => 404 ) );
		}
		if ( ! State::rate_allowed( $id, 40, 'attempt' ) ) {
			return new WP_Error( 'rate', __( 'Too many attempts in a short time. Wait a few minutes.', 'easyformly' ), array( 'status' => 429 ) );
		}
		$nonce  = is_string( $input['nonce'] ?? null ) ? $input['nonce'] : '';
		$ticket = is_string( $input['ticket'] ?? null ) ? $input['ticket'] : '';
		if ( ! wp_verify_nonce( $nonce, 'easyformly_submit_' . get_current_blog_id() . '_' . $id ) || ! self::valid_ticket( $id, $ticket ) ) {
			return new WP_Error( 'expired', __( 'The form session has expired. Reload the page.', 'easyformly' ), array( 'status' => 403 ) );
		}
		if ( ! is_array( $input['fields'] ?? null ) || count( $input['fields'] ) > 80 || strlen( (string) wp_json_encode( $input['fields'] ) ) > 40000 || ( isset( $input['countries'] ) && ( ! is_array( $input['countries'] ) || count( $input['countries'] ) > 40 || strlen( (string) wp_json_encode( $input['countries'] ) ) > 5000 ) ) ) {
			return new WP_Error( 'invalid', __( 'The form is not valid.', 'easyformly' ), array( 'status' => 422 ) );
		}
		$countries = $input['countries'] ?? array();
		foreach ( $countries as $country ) {
			if ( ! is_string( $country ) || strlen( $country ) > 2 ) {
				return new WP_Error( 'invalid', __( 'The selected country is not valid.', 'easyformly' ), array( 'status' => 422 ) );
			}
		}
		$name    = self::honeypot_name( $id, $ticket );
		$traps   = $input['honeypot'] ?? null;
		$elapsed = self::elapsed( $id, is_string( $input['clock'] ?? null ) ? $input['clock'] : '' );
		if ( ! is_array( $traps ) || 1 !== count( $traps ) || ! isset( $traps[ $name ] ) || ! is_string( $traps[ $name ] ) || strlen( $traps[ $name ] ) > 512 || false === $elapsed ) {
			return new WP_Error( 'invalid', __( 'The form is not valid. Reload the page.', 'easyformly' ), array( 'status' => 422 ) );
		}
		$context = array(
			'honeypot' => '' !== $traps[ $name ],
			'elapsed'  => $elapsed,
		);
		$fields  = $input['fields'];
		ksort( $fields, SORT_STRING );
		ksort( $countries, SORT_STRING );
		$encoded = wp_json_encode(
			$countries ? array(
				'fields'    => $fields,
				'countries' => $countries,
			) : $fields
		);
		if ( false === $encoded ) {
			return new WP_Error( 'invalid', __( 'The form is not valid.', 'easyformly' ), array( 'status' => 422 ) );
		}
		$input_fingerprint = hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
		$receipt           = State::read( substr( hash( 'sha256', $ticket ), 0, 40 ) );
		if ( isset( $receipt['response'], $receipt['input_fingerprint'] ) ) {
			return hash_equals( $receipt['input_fingerprint'], $input_fingerprint ) ? $receipt['response'] : new WP_Error( 'used', __( 'This submission has already been processed. Contact the site before sending another message.', 'easyformly' ), array( 'status' => 409 ) );
		}
		$validated = Schema::validate( $definition, $input['fields'], $countries );
		if ( $validated['errors'] ) {
			return new WP_Error(
				'validation',
				__( 'Check the highlighted fields.', 'easyformly' ),
				array(
					'status' => 422,
					'fields' => $validated['errors'],
				)
			);
		}
		$result = Submission::process( $id, $definition, $validated['values'], $ticket, is_string( $input['captcha'] ?? null ) ? $input['captcha'] : '', $input_fingerprint, $context );
		Maintenance::after_submission();
		return $result;
	}

	/**
	 * Handle the classic POST fallback with the same server validation.
	 *
	 * @return void
	 */
	public static function native_submit(): void {
		// WordPress adds slashes only to classic POSTs, never to REST JSON bodies.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- submit() verifies the nonce and signed ticket before processing any fields or sending data.
		$input  = wp_unslash( $_POST );
		$id     = is_scalar( $input['form_id'] ?? null ) ? absint( $input['form_id'] ) : 0;
		$result = self::submit( $id, is_array( $input ) ? $input : array() );
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'Form submission', 'easyformly' ),
				array(
					'response'  => (int) ( $result->get_error_data()['status'] ?? 400 ),
					'back_link' => true,
				)
			);
		}
		$return = is_string( $input['return_url'] ?? null ) ? wp_validate_redirect( $input['return_url'], home_url( '/' ) ) : home_url( '/' );
		wp_safe_redirect( add_query_arg( ! empty( $result['held'] ) ? 'easyformly_held' : 'easyformly_sent', $id, $return ) );
		exit;
	}
}
