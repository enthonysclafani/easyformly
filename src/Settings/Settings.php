<?php
/**
 * Site settings: one non-autoloaded option with conservative defaults.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The `easyformly_settings` option, read with defaults for missing keys.
 */
final class Settings {
	public const OPTION = 'easyformly_settings';

	/**
	 * Return conservative defaults for site settings.
	 *
	 * @return array<string, mixed> Result of this operation.
	 */
	public static function defaults(): array {
		return array(
			'store_entries'       => false,
			'retention_days'      => 30,
			'spam_retention_days' => 14,
			'antispam_level'      => 2,
			'antispam_observe'    => false,
			'quarantine_entries'  => false,
			'quarantine_limit'    => 500,
			'email_dns_check'     => false,
			'phone_country'       => '',
			'spam_phrases'        => '',
			'allow_erasure'       => true,
			'delete_on_uninstall' => false,
			'mailerlite_token'    => '',
			'turnstile_site_key'  => '',
			'turnstile_secret'    => '',
		);
	}

	/**
	 * JSON schema of the option: every key, its type, its bounds and its default.
	 *
	 * The option holds the MailerLite token and the Turnstile secret, so it is never shown in REST: the schema checks
	 * what `Page::sanitize_settings()` saves instead.
	 *
	 * @return array<string, mixed> Result of this operation.
	 */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => self::properties(),
		);
	}

	/**
	 * Schema of each setting, keyed by setting name, with its default.
	 *
	 * @return array<string, array<string, mixed>> Result of this operation.
	 */
	public static function properties(): array {
		$defaults   = self::defaults();
		$properties = array(
			'store_entries'       => array( 'type' => 'boolean' ),
			'retention_days'      => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 3650,
			),
			'spam_retention_days' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 3650,
			),
			'antispam_level'      => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 3,
			),
			'antispam_observe'    => array( 'type' => 'boolean' ),
			'quarantine_entries'  => array( 'type' => 'boolean' ),
			'quarantine_limit'    => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 5000,
			),
			'email_dns_check'     => array( 'type' => 'boolean' ),
			// Empty: the country follows the site language.
			'phone_country'       => array(
				'type'    => 'string',
				'pattern' => '^([A-Z]{2})?$',
			),
			// At most 30 lines of 4 to 150 characters (`AntiSpam::phrase_list()`).
			'spam_phrases'        => array(
				'type'      => 'string',
				'maxLength' => 5000,
			),
			'allow_erasure'       => array( 'type' => 'boolean' ),
			'delete_on_uninstall' => array( 'type' => 'boolean' ),
			'mailerlite_token'    => array(
				'type'      => 'string',
				'maxLength' => 2000,
			),
			'turnstile_site_key'  => array(
				'type'      => 'string',
				'maxLength' => 256,
				'pattern'   => '^[A-Za-z0-9_-]*$',
			),
			'turnstile_secret'    => array(
				'type'      => 'string',
				'maxLength' => 2000,
			),
		);
		foreach ( $properties as $key => $property ) {
			$properties[ $key ]['default'] = $defaults[ $key ];
		}
		return $properties;
	}

	/**
	 * Read site settings with defaults.
	 *
	 * @return array<string, mixed> Result of this operation.
	 */
	public static function get(): array {
		$settings = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $settings ) ? $settings : array() );
	}
}
