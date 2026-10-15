<?php
/**
 * Explainable, local anti-spam rules. No AI, telemetry or remote reputation service.
 *
 * @package EasyFormly
 */

namespace EasyFormly\AntiSpam;

use EasyFormly\Phone\Phone;
use EasyFormly\Settings\Settings;
use EasyFormly\Submissions\State;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Score a submission from independent local signals.
 */
final class AntiSpam {
	/**
	 * Resolve the protection level of a form, 1 to 3.
	 *
	 * @param array<string, mixed> $config Validated form configuration.
	 * @return int Protection level.
	 */
	public static function level( array $config ): int {
		$value = $config['antispam_level'] ?? 'inherit';
		return max( 1, min( 3, (int) ( 'inherit' === $value ? Settings::get()['antispam_level'] : $value ) ) );
	}

	/**
	 * Resolve an inherited yes/no preference of a form.
	 *
	 * @param array<string, mixed> $config Validated form configuration.
	 * @param string               $key    Preference name.
	 * @return bool Whether the preference is on.
	 */
	public static function enabled( array $config, string $key ): bool {
		$value = $config[ $key ] ?? 'inherit';
		return 'inherit' === $value ? (bool) Settings::get()[ $key ] : 'yes' === $value;
	}

	/**
	 * Preserve the local part, plus aliases and dots; canonicalize only the domain.
	 *
	 * @param string $value Submitted address.
	 * @return string|false Canonical address, or false when malformed.
	 */
	public static function email( string $value ) {
		$value = trim( $value );
		$parts = explode( '@', $value );
		if ( preg_match( '/[\s\x00-\x1f\x7f]/u', $value ) || 2 !== count( $parts ) || strlen( $parts[0] ) > 64 || str_starts_with( $parts[0], '.' ) || str_ends_with( $parts[0], '.' ) || str_contains( $parts[0], '..' ) ) {
			return false;
		}
		$domain = self::domain( $parts[1] );
		$email  = $parts[0] . '@' . $domain;
		return $domain && strlen( $email ) <= 254 && is_email( $email ) ? $email : false;
	}

	/**
	 * Lowercase ASCII form of a domain name, or an empty string when it is not one.
	 *
	 * @param string $value Domain, possibly internationalized.
	 * @return string Canonical domain or ''.
	 */
	public static function domain( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( function_exists( 'idn_to_ascii' ) && preg_match( '/[^\x00-\x7f]/', $value ) ) {
			$value = idn_to_ascii( $value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$value = is_string( $value ) ? $value : '';
		}
		if ( strlen( $value ) > 253 || ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Optional DNS checks are cached; inconclusive resolver failures are allowed.
	 *
	 * @param string $domain Canonical domain.
	 * @return string 'valid', 'invalid' or 'unknown'.
	 */
	public static function mail_domain( string $domain ): string {
		$key    = 'easyformly_dns_' . substr( hash_hmac( 'sha256', $domain, wp_salt( 'auth' ) ), 0, 32 );
		$cached = get_transient( $key );
		if ( in_array( $cached, array( 'valid', 'invalid', 'unknown' ), true ) ) {
			return $cached;
		}
		$result = 'unknown';
		if ( function_exists( 'dns_get_record' ) ) {
			// Resolver errors must not block legitimate visitors.
			$records = @dns_get_record( $domain, DNS_MX | DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Resolver warnings become an 'unknown' result.
			if ( is_array( $records ) ) {
				$mx      = array_values( array_filter( $records, static fn( $record ) => 'MX' === ( $record['type'] ?? '' ) ) );
				$null_mx = 1 === count( $mx ) && in_array( $mx[0]['target'] ?? '', array( '', '.' ), true );
				$result  = $null_mx || ! $records ? 'invalid' : 'valid';
			}
		}
		set_transient( $key, $result, 'unknown' === $result ? MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Validate and canonicalize suspect phrases, one per line.
	 *
	 * @param string $value Phrases as typed by the administrator.
	 * @return string|WP_Error Lowercase unique phrases, or the validation error.
	 */
	public static function phrase_list( string $value ) {
		$lines = preg_split( '/[\r\n]+/', sanitize_textarea_field( $value ) );
		if ( false === $lines || strlen( $value ) > 5000 || count( $lines ) > 30 ) {
			return new WP_Error( 'phrases', __( 'Enter at most 30 short phrases, one per line.', 'easyformly' ) );
		}
		$phrases = array();
		foreach ( $lines as $line ) {
			$line = mb_strtolower( trim( $line ), 'UTF-8' );
			if ( '' === $line ) {
				continue;
			}
			if ( mb_strlen( $line, 'UTF-8' ) < 4 || mb_strlen( $line, 'UTF-8' ) > 150 ) {
				return new WP_Error( 'phrases', __( 'Each phrase must be 4 to 150 characters long.', 'easyformly' ) );
			}
			$phrases[] = $line;
		}
		return implode( "\n", array_unique( $phrases ) );
	}

	/**
	 * Site-wide and form lines of a list preference, combined.
	 *
	 * @param array<string, mixed> $config Validated form configuration.
	 * @param string               $key    Preference name.
	 * @return array<int, string> Non-empty lines.
	 */
	private static function listing( array $config, string $key ): array {
		$lines = preg_split( '/[\r\n]+/', Settings::get()[ $key ] . "\n" . ( $config[ $key ] ?? '' ) );
		return false === $lines ? array() : array_filter( $lines );
	}

	/**
	 * Assess once per new ticket; receipt retries reuse this assessment and its counters.
	 *
	 * @param int                   $form_id    Published form ID.
	 * @param array<string, mixed>  $definition Validated form definition.
	 * @param array<string, string> $values     Sanitized visible field values.
	 * @param array<string, mixed>  $context    Request signals: honeypot, elapsed seconds.
	 * @return array{status: string, detected: string, score: int, reasons: list<string>, level: int, observe: bool} Status, detected category, score, reasons, level and observation flag.
	 */
	public static function assess( int $form_id, array $definition, array $values, array $context = array() ): array {
		$config   = $definition['config'];
		$level    = self::level( $config );
		$families = array();
		$reasons  = array();
		$forced   = false;
		$add      = static function ( string $family, int $points, string $reason ) use ( &$families, &$reasons ): void {
			$families[ $family ] = max( $families[ $family ] ?? 0, $points );
			$reasons[]           = $reason;
		};
		if ( ! empty( $context['honeypot'] ) ) {
			$add( 'trap', 100, 'honeypot' );
			$forced = true;
		}
		if ( $level >= 2 ) {
			if ( isset( $context['elapsed'] ) && $context['elapsed'] < 2 ) {
				$add( 'timing', 10, 'fast' );
			}
			$text = array();
			foreach ( $values as $name => $value ) {
				$type = $definition['schema']['fields'][ $name ]['type'];
				if ( 'email' === $type && '' !== $value ) {
					[ $alias, $domain ] = explode( '@', $value, 2 );
					if ( DisposableDomains::contains( $domain ) ) {
						$add( 'email', 30, 'disposable' );
					}
					// Only recognizable repetitions/keyboards: apparent randomness is not proof of spam.
					if ( preg_match( '/^(?:([a-z0-9])\1{5,}|(?:asdf|qwerty|zxcv|123456)[a-z0-9]*)$/iD', $alias ) ) {
						$add( 'email', 5, 'alias_pattern' );
					}
					$count = State::count( 'email_' . $form_id, strtolower( $value ), 10 * MINUTE_IN_SECONDS, 20 );
					if ( null !== $count && $count >= ( 3 === $level ? 4 : 6 ) ) {
						$add( 'frequency', 25, 'email_frequency' );
					}
				}
				if ( 'tel' === $type && '' !== $value ) {
					if ( empty( $definition['schema']['fields'][ $name ]['phone_allow_repeated'] ) && Phone::repetitive( $value ) ) {
						$add( 'phone', 35, 'repetitive_phone' );
					}
					$count = State::count( 'phone_' . $form_id, $value, 10 * MINUTE_IN_SECONDS, 20 );
					if ( null !== $count && $count >= ( 3 === $level ? 4 : 6 ) ) {
						$add( 'frequency', 20, 'phone_frequency' );
					}
				}
				if ( in_array( $type, array( 'text', 'textarea' ), true ) ) {
					$lower = mb_strtolower( trim( $value ), 'UTF-8' );
					if ( preg_match( '/^(?:(.)\1{7,}|(?:asdf|qwerty|zxcv){2,})$/uD', $lower ) ) {
						$add( 'content', 35, 'repetitive_text' );
					}
				}
				if ( 'textarea' === $type && '' !== trim( $value ) ) {
					$text[] = $value;
				}
			}
			$message = mb_strtolower( implode( "\n", $text ), 'UTF-8' );
			if ( '' !== $message ) {
				preg_match_all( '~(?:https?://|www\.)[^\s<>]+~iu', $message, $matches );
				$links   = count( $matches[0] );
				$density = strlen( implode( '', $matches[0] ) ) / max( 1, strlen( $message ) );
				if ( $links >= ( 3 === $level ? 3 : 4 ) || ( $links >= 2 && $density > 0.6 ) ) {
					$add( 'content', 30, 'links' );
				}
				$promotions = array( 'buy now', 'guaranteed rankings', 'increase your website traffic', 'backlink packages', 'guest post service', 'casino bonus', 'crypto investment', 'guadagno garantito', 'acquista subito', 'pacchetti backlink', 'traffico garantito', 'investimento garantito' );
				$hits       = 0;
				foreach ( $promotions as $phrase ) {
					$hits += str_contains( $message, $phrase ) ? 1 : 0;
				}
				if ( $hits >= 2 || ( $hits >= 1 && $links >= 2 ) ) {
					$add( 'content', 40, 'promotion' );
				}
				foreach ( self::listing( $config, 'spam_phrases' ) as $phrase ) {
					if ( str_contains( $message, $phrase ) ) {
						$add( 'content', 40, 'custom_phrase' );
						break;
					}
				}
				$template = preg_replace( array( '~(?:https?://|www\.)[^\s<>]+~iu', '/[\p{N}\p{P}\p{Z}\s]+/u' ), array( ' link ', ' ' ), $message ) ?? '';
				if ( mb_strlen( trim( $template ), 'UTF-8' ) >= 20 ) {
					$count = State::count( 'message_' . $form_id, trim( $template ), 30 * MINUTE_IN_SECONDS, 30 );
					if ( null !== $count && $count >= ( 3 === $level ? 3 : 4 ) ) {
						$add( 'frequency', 40, 'duplicate' );
					}
				}
			}
		}
		$score  = min( 100, array_sum( $families ) );
		$status = 'accepted';
		if ( $forced || ( $score >= ( 3 === $level ? 40 : 60 ) && count( $families ) >= 2 ) ) {
			$status = 'spam';
		} elseif ( $level >= 2 && $score >= ( 3 === $level ? 20 : 30 ) ) {
			$status = 'suspect';
		}
		$observe = self::enabled( $config, 'antispam_observe' ) && ! $forced;
		return array(
			'status'   => $observe ? 'accepted' : $status,
			'detected' => $status,
			'score'    => $score,
			'reasons'  => array_values( array_unique( $reasons ) ),
			'level'    => $level,
			'observe'  => $observe,
		);
	}

	/**
	 * Localized labels of the assessment reasons.
	 *
	 * @return array<string, string> Labels keyed by reason.
	 */
	public static function reason_labels(): array {
		return array(
			'honeypot'         => __( 'Anti-bot field filled in', 'easyformly' ),
			'fast'             => __( 'Extremely fast completion', 'easyformly' ),
			'disposable'       => __( 'Disposable email domain', 'easyformly' ),
			'blocked_domain'   => __( 'Email domain blocked in the settings', 'easyformly' ),
			'alias_pattern'    => __( 'Email with a repetitive pattern', 'easyformly' ),
			'email_frequency'  => __( 'Many submissions from the same email', 'easyformly' ),
			'phone_frequency'  => __( 'Many submissions from the same phone', 'easyformly' ),
			'repetitive_phone' => __( 'Fully repetitive phone number', 'easyformly' ),
			'repetitive_text'  => __( 'Text made of repetitions', 'easyformly' ),
			'links'            => __( 'High number of links', 'easyformly' ),
			'promotion'        => __( 'Recurring promotional pattern', 'easyformly' ),
			'custom_phrase'    => __( 'Phrase flagged in the form rules', 'easyformly' ),
			'duplicate'        => __( 'Content repeated across submissions', 'easyformly' ),
		);
	}
}
