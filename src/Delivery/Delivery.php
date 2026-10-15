<?php
/**
 * Outbound adapters use only WordPress HTTP and mail APIs.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Delivery;

use EasyFormly\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Contact documented providers through native WordPress APIs.
 */
final class Delivery {
	/**
	 * Verify CAPTCHA success, hostname and form action.
	 *
	 * @param int    $form_id Published form ID.
	 * @param string $token Turnstile response token.
	 * @param string $idempotency_key Unique UUID for retrying CAPTCHA verification.
	 * @return true|WP_Error Result of this operation.
	 */
	public static function captcha( int $form_id, string $token, string $idempotency_key = '' ) {
		$settings = Settings::get();
		if ( '' === $settings['turnstile_secret'] || '' === $token || strlen( $token ) > 2048 ) {
			return new WP_Error( 'captcha', __( 'Complete the CAPTCHA.', 'easyformly' ), array( 'status' => 422 ) );
		}
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documented CAPTCHA verification API; receives JSON and never loads executable content.
		$response = wp_safe_remote_post(
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documented JSON verification API for the explicitly enabled CAPTCHA; no remote executable code is loaded here.
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout'             => 8,
				'limit_response_size' => 65536,
				'redirection'         => 0,
				'body'                => array(
					'secret'          => $settings['turnstile_secret'],
					'response'        => $token,
					'idempotency_key' => self::captcha_key( $token, $idempotency_key ),
				),
			)
		);
		$body     = ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
		$hostname = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) || true !== ( $body['success'] ?? false ) || ! is_string( $body['hostname'] ?? null ) || strtolower( $body['hostname'] ) !== $hostname || ( $body['action'] ?? '' ) !== 'easyformly_form_' . $form_id ) {
			return new WP_Error( 'captcha', __( 'The CAPTCHA check failed. Try again.', 'easyformly' ), array( 'status' => 422 ) );
		}
		return true;
	}

	/**
	 * Keep verification retries stable while distinguishing a fresh CAPTCHA challenge.
	 *
	 * @param string $token Turnstile response token.
	 * @param string $seed Submission-specific UUID.
	 * @return string Deterministic UUID for this submission and challenge, or a fresh UUID.
	 */
	private static function captcha_key( string $token, string $seed ): string {
		if ( '' === $seed ) {
			return wp_generate_uuid4();
		}
		$hash = hash( 'sha256', $seed . '|' . $token );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-4' . substr( $hash, 13, 3 ) . '-8' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 );
	}

	/**
	 * Pass a plain-text notification to the native WordPress mail API.
	 *
	 * @param int                   $form_id Published form ID.
	 * @param array<string, mixed>  $definition Validated form definition.
	 * @param array<string, string> $values Sanitized visible field values.
	 * @return true|WP_Error Result of this operation.
	 */
	public static function mail( int $form_id, array $definition, array $values ) {
		$config  = $definition['config'];
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$reply   = $values[ $config['mail_reply_to'] ] ?? '';
		if ( is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}
		$subject = sanitize_text_field( self::replace( $config['mail_subject'], $form_id, $definition, $values ) );
		$body    = self::replace( $config['mail_body'], $form_id, $definition, $values );
		return wp_mail( $config['mail_to'], $subject, $body, $headers ) ? true : new WP_Error( 'mail_uncertain', 'mail_uncertain' );
	}

	/**
	 * Replace only documented email placeholders.
	 *
	 * @param string                $template Restricted tag or email template.
	 * @param int                   $form_id Published form ID.
	 * @param array<string, mixed>  $definition Validated form definition.
	 * @param array<string, string> $values Sanitized visible field values.
	 * @return string Result of this operation.
	 */
	private static function replace( string $template, int $form_id, array $definition, array $values ): string {
		$replacements = array(
			'[form]' => sanitize_text_field( get_the_title( $form_id ) ),
			'[site]' => sanitize_text_field( get_bloginfo( 'name' ) ),
		);
		$lines        = array();
		foreach ( $definition['schema']['fields'] as $name => $field ) {
			$value = $values[ $name ] ?? '';
			if ( in_array( $field['type'], array( 'checkbox', 'acceptance' ), true ) ) {
				$value = '1' === $value ? __( 'Yes', 'easyformly' ) : __( 'No', 'easyformly' );
			}
			$replacements[ '[' . $name . ']' ] = isset( $values[ $name ] ) ? $value : '';
			if ( isset( $values[ $name ] ) ) {
				$lines[] = $field['label'] . ': ' . $value;
			}
		}
		$replacements['[fields]'] = implode( "\n\n", $lines );
		return strtr( $template, $replacements );
	}

	/**
	 * Upsert an explicitly consented subscriber without changing opt-out status.
	 *
	 * @param array<string, mixed>  $config Validated delivery and privacy configuration.
	 * @param array<string, string> $values Sanitized visible field values.
	 * @return true|string|WP_Error Result of this operation.
	 */
	public static function mailerlite( array $config, array $values ) {
		// A hidden or unchecked consent field cannot cause a newsletter subscription.
		if ( '1' !== ( $values[ $config['mailerlite_consent'] ] ?? '' ) ) {
			return 'skipped';
		}
		$email = $values[ $config['mailerlite_email'] ] ?? '';
		$token = Settings::get()['mailerlite_token'];
		if ( ! is_email( $email ) || '' === $token ) {
			return new WP_Error( 'mailerlite_configuration', 'mailerlite_configuration' );
		}
		$body = array(
			'email'  => $email,
			'groups' => array_values( array_filter( array_map( 'trim', explode( ',', $config['mailerlite_groups'] ) ) ) ),
		);
		$name = $values[ $config['mailerlite_name'] ] ?? '';
		if ( '' !== $name ) {
			$body['fields'] = array( 'name' => $name );
		}
		$json = wp_json_encode( $body );
		if ( false === $json ) {
			return new WP_Error( 'mailerlite_configuration', 'mailerlite_configuration' );
		}
		// Do not force status=active or resubscribe: existing opt-outs remain intact.
		$response = wp_safe_remote_post(
			'https://connect.mailerlite.com/api/subscribers',
			array(
				'timeout'             => 8,
				'limit_response_size' => 65536,
				'redirection'         => 0,
				'headers'             => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'                => $json,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mailerlite_network', 'mailerlite_network' );
		}
		$code     = wp_remote_retrieve_response_code( $response );
		$received = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( in_array( $code, array( 200, 201 ), true ) ) {
			$id = is_array( $received ) && is_array( $received['data'] ?? null ) ? ( $received['data']['id'] ?? null ) : null;
			return ( is_string( $id ) || is_int( $id ) ) && ctype_digit( (string) $id ) && '0' !== (string) $id ? true : new WP_Error( 'mailerlite_uncertain', 'mailerlite_uncertain' );
		}
		return new WP_Error( 'mailerlite_http_' . $code, 'mailerlite_http_' . $code );
	}

	/**
	 * Allow only the documented HTTPS incoming webhook host and path.
	 *
	 * @param string $url Validated plugin-specific value.
	 * @return bool Result of this operation.
	 */
	public static function valid_slack_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' ) && 'hooks.slack.com' === ( $parts['host'] ?? '' ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['port'] ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] ) && 1 === preg_match( '~^/services/[A-Za-z0-9]+/[A-Za-z0-9]+/[A-Za-z0-9]+$~D', $parts['path'] ?? '' );
	}

	/**
	 * Send selected visible fields as plain text to Slack.
	 *
	 * @param int                   $form_id Published form ID.
	 * @param array<string, mixed>  $definition Validated form definition.
	 * @param array<string, string> $values Sanitized visible field values.
	 * @return true|WP_Error Result of this operation.
	 */
	public static function slack( int $form_id, array $definition, array $values ) {
		$config = $definition['config'];
		if ( ! self::valid_slack_url( $config['slack_webhook'] ) ) {
			return new WP_Error( 'slack_configuration', 'slack_configuration' );
		}
		$title  = mb_substr( __( 'New message: ', 'easyformly' ) . sanitize_text_field( get_the_title( $form_id ) ), 0, 140 );
		$blocks = array(
			array(
				'type' => 'header',
				'text' => array(
					'type' => 'plain_text',
					'text' => $title,
				),
			),
		);
		foreach ( array_filter( array_map( 'trim', explode( ',', $config['slack_fields'] ) ) ) as $name ) {
			if ( ! isset( $values[ $name ], $definition['schema']['fields'][ $name ] ) ) {
				continue;
			}
			$field = $definition['schema']['fields'][ $name ];
			$value = $values[ $name ];
			if ( in_array( $field['type'], array( 'checkbox', 'acceptance' ), true ) ) {
				$value = '1' === $value ? __( 'Yes', 'easyformly' ) : __( 'No', 'easyformly' );
			}
			$blocks[] = array(
				'type' => 'section',
				'text' => array(
					'type' => 'plain_text',
					'text' => mb_substr( $field['label'] . ': ' . $value, 0, 2900 ),
				),
			);
		}
		$json = wp_json_encode(
			array(
				'text'         => str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $title ),
				'blocks'       => $blocks,
				'unfurl_links' => false,
				'unfurl_media' => false,
			)
		);
		if ( false === $json ) {
			return new WP_Error( 'slack_configuration', 'slack_configuration' );
		}
		$response = wp_safe_remote_post(
			$config['slack_webhook'],
			array(
				'timeout'             => 8,
				'limit_response_size' => 65536,
				'redirection'         => 0,
				'headers'             => array( 'Content-Type' => 'application/json' ),
				'body'                => $json,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'slack_uncertain', 'slack_uncertain' );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return 'ok' === trim( wp_remote_retrieve_body( $response ) ) ? true : new WP_Error( 'slack_uncertain', 'slack_uncertain' );
		}
		return new WP_Error( 'slack_http_' . $code, 'slack_http_' . $code );
	}
}
