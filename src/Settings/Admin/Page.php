<?php
/**
 * Settings page: Settings API registration, fields, validation and the disposable-domain synchronization.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Settings\Admin;

use EasyFormly\AntiSpam\AntiSpam;
use EasyFormly\AntiSpam\DisposableDomains;
use EasyFormly\Phone\Admin\CountrySelect;
use EasyFormly\Phone\Phone;
use EasyFormly\Plugin;
use EasyFormly\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Native settings page under the forms menu, for administrators only.
 */
final class Page {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'settings' ) );
		add_action( 'admin_post_easyformly_sync_disposable_domains', array( self::class, 'sync_disposable_domains' ) );
	}

	/**
	 * Add the settings submenu.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . Plugin::FORM, __( 'Settings', 'easyformly' ), __( 'Settings', 'easyformly' ), 'manage_options', 'easyformly-settings', array( self::class, 'settings_page' ) );
	}

	/**
	 * Register the option, its sections and its fields with the Settings API.
	 *
	 * @return void
	 */
	public static function settings(): void {
		register_setting(
			'easyformly_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'object',
				'description'       => 'EasyFormly site settings (schema: Settings::schema()).',
				'default'           => Settings::defaults(),
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				// Holds the MailerLite token and the Turnstile secret.
				'show_in_rest'      => false,
			)
		);
		foreach ( array(
			'privacy'      => __( 'Form privacy', 'easyformly' ),
			'antispam'     => __( 'Anti-spam and international phone', 'easyformly' ),
			'integrations' => __( 'MailerLite', 'easyformly' ),
			'captcha'      => 'CAPTCHA · Cloudflare Turnstile',
			'uninstall'    => __( 'Uninstall', 'easyformly' ),
		) as $section => $title ) {
			add_settings_section( 'easyformly-' . $section, $title, '__return_false', 'easyformly-settings' );
		}
		$items = array(
			'antispam_level'      => array( 'antispam', __( 'Default level', 'easyformly' ), 'level', __( 'Recommended: 2 — Balanced. Existing forms keep level 1 until it is changed in their options.', 'easyformly' ) ),
			'antispam_observe'    => array( 'antispam', __( 'Observation mode', 'easyformly' ), 'checkbox', __( 'Record heuristic signals without stopping submissions. To review the results, turn on the archive. The honeypot stays on.', 'easyformly' ) ),
			'quarantine_entries'  => array( 'antispam', __( 'Quarantine with the archive off', 'easyformly' ), 'checkbox', __( 'Keep spam and suspicious submissions for review even without the normal archive; notifications and integrations stay held.', 'easyformly' ) ),
			'spam_retention_days' => array( 'antispam', __( 'Spam and suspicious retention', 'easyformly' ), 'number', __( 'Days before spam and likely spam are deleted automatically. Minimum 1; accepted submissions follow the normal retention.', 'easyformly' ) ),
			'quarantine_limit'    => array( 'antispam', __( 'Daily quarantine limit', 'easyformly' ), 'number', __( 'Maximum number of new suspicious submissions that can be archived in a 24-hour window. Beyond the limit they are rejected.', 'easyformly' ) ),
			'email_dns_check'     => array( 'antispam', __( 'Email DNS check', 'easyformly' ), 'checkbox', __( 'Check the domain through the server\'s resolver, with caching and tolerance of temporary errors. It does not check the mailbox.', 'easyformly' ) ),
			'phone_country'       => array( 'antispam', __( 'Initial phone country', 'easyformly' ), 'country', __( 'Automatic selection follows the site language. Visitors can always change the country.', 'easyformly' ) ),
			'disposable_domains'  => array( 'antispam', __( 'Disposable domain list', 'easyformly' ), 'disposable', __( 'Used at levels 2 and 3. Synchronize the public sources on request; the local copy is kept if a source is unavailable. No automatic updates.', 'easyformly' ) ),
			'spam_phrases'        => array( 'antispam', __( 'Suspicious phrases', 'easyformly' ), 'textarea', __( 'One phrase per line, 4 to 150 characters. Raises suspicion; use specific phrases and avoid generic words.', 'easyformly' ) ),
			'store_entries'       => array( 'privacy', __( 'Submission archive', 'easyformly' ), 'checkbox', __( 'Save submissions locally by default. Each form can change this choice.', 'easyformly' ) ),
			'retention_days'      => array( 'privacy', __( 'Retention', 'easyformly' ), 'number', __( 'Days to keep submissions. 0 keeps them until deleted manually. Cleanup runs in batches when an administrator opens the dashboard or after a submission, once a day.', 'easyformly' ) ),
			'allow_erasure'       => array( 'privacy', __( 'Erasure requests', 'easyformly' ), 'checkbox', __( 'Let the WordPress privacy tools delete the submissions linked to the confirmed email address.', 'easyformly' ) ),
			'mailerlite_token'    => array( 'integrations', __( 'API token', 'easyformly' ), 'password', __( 'Token for the current MailerLite version. Leave empty to keep the saved token.', 'easyformly' ) ),
			'turnstile_site_key'  => array( 'captcha', __( 'Site key', 'easyformly' ), 'text', __( 'Turn on the CAPTCHA in each form\'s options.', 'easyformly' ) ),
			'turnstile_secret'    => array( 'captcha', __( 'Secret key', 'easyformly' ), 'password', __( 'Leave empty to keep the saved key.', 'easyformly' ) ),
			'delete_on_uninstall' => array( 'uninstall', __( 'Remove data', 'easyformly' ), 'checkbox', __( 'On uninstall, permanently delete the plugin\'s forms, submissions, settings and credentials. Deactivating alone keeps the data.', 'easyformly' ) ),
		);
		foreach ( $items as $key => $item ) {
			add_settings_field(
				'easyformly-' . $key,
				$item[1],
				array( self::class, 'setting_field' ),
				'easyformly-settings',
				'easyformly-' . $item[0],
				array(
					'key'       => $key,
					'type'      => $item[2],
					'help'      => $item[3],
					'label_for' => 'disposable' === $item[2] ? '' : 'easyformly-setting-' . $key,
				)
			);
		}
	}

	/**
	 * Render a registered Settings API field.
	 *
	 * @param array<string, string> $args Registered field arguments.
	 * @return void
	 */
	public static function setting_field( array $args ): void {
		$settings = Settings::get();
		$key      = $args['key'];
		$type     = $args['type'];
		$name     = Settings::OPTION . '[' . $key . ']';
		if ( 'disposable' === $type ) {
			$status = DisposableDomains::status();
			/* translators: %s: number of domains. */
			echo '<div id="easyformly-disposable-domains"><p><strong>' . esc_html( sprintf( __( '%s domains in the local list', 'easyformly' ), number_format_i18n( $status['count'] ) ) ) . '</strong></p>';
			/* translators: %s: date and time of the synchronization, or date of the bundled snapshot. */
			$label = $status['synced'] ? __( 'Last synchronization: %s', 'easyformly' ) : __( 'Copy included in the plugin, updated on %s', 'easyformly' );
			echo '<p>' . esc_html( sprintf( $label, wp_date( get_option( 'date_format' ) . ( $status['synced'] ? ' ' . get_option( 'time_format' ) : '' ), $status['updated_at'] ) ) ) . '</p><ul>';
			foreach ( DisposableDomains::sources() as $source ) {
				echo '<li><a href="' . esc_url( $source['project'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $source['name'] ) . '</a></li>';
			}
			echo '</ul><p><button type="submit" class="button" id="easyformly-setting-disposable_domains" form="easyformly-disposable-sync">' . esc_html__( 'Synchronize list', 'easyformly' ) . '</button></p><p class="description">' . esc_html( $args['help'] ) . '</p></div>';
			return;
		}
		if ( 'level' === $type ) {
			echo '<input type="hidden" name="' . esc_attr( Settings::OPTION . '[antispam_present]' ) . '" value="1"><select id="easyformly-setting-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( array(
				1 => __( '1 — Basic', 'easyformly' ),
				2 => __( '2 — Balanced', 'easyformly' ),
				3 => __( '3 — Strict', 'easyformly' ),
			) as $level => $label ) {
				echo '<option value="' . (int) $level . '" ' . selected( $settings[ $key ], $level, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select><p class="description">' . esc_html( $args['help'] ) . '</p>';
			return;
		}
		if ( 'country' === $type ) {
			CountrySelect::render( $name, $settings[ $key ], 'easyformly-setting-' . $key, __( 'Automatic from the site language', 'easyformly' ) );
			echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
			return;
		}
		if ( 'textarea' === $type ) {
			echo '<textarea class="large-text" rows="3" id="easyformly-setting-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $settings[ $key ] ) . '</textarea><p class="description">' . esc_html( $args['help'] ) . '</p>';
			return;
		}
		echo '<input id="easyformly-setting-' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '"';
		if ( 'checkbox' === $type ) {
			echo ' value="1" ' . checked( $settings[ $key ], true, false );
		} else {
			echo ' class="regular-text" value="' . esc_attr( 'password' === $type ? '' : (string) $settings[ $key ] ) . '"';
			if ( 'password' === $type ) {
				echo ' autocomplete="new-password" placeholder="' . esc_attr( $settings[ $key ] ? __( 'Configured', 'easyformly' ) : '' ) . '"';
			} elseif ( 'number' === $type ) {
				echo ' min="' . ( in_array( $key, array( 'spam_retention_days', 'quarantine_limit' ), true ) ? '1' : '0' ) . '" max="' . ( 'quarantine_limit' === $key ? '5000' : '3650' ) . '" step="1"';
			}
		}
		echo '><p class="description">' . esc_html( $args['help'] ) . '</p>';
		if ( 'password' === $type ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( Settings::OPTION . '[clear_' . $key . ']' ) . '" value="1"> ' . esc_html__( 'Remove the saved credential', 'easyformly' ) . '</label></p>';
		}
	}

	/**
	 * Validate global preferences and preserve unchanged credentials.
	 *
	 * @param mixed $input Untrusted input to validate.
	 * @return array<string, mixed> Result of this operation.
	 */
	public static function sanitize_settings( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$previous = Settings::get();
		$settings = Settings::defaults();
		foreach ( array( 'store_entries', 'allow_erasure', 'delete_on_uninstall' ) as $key ) {
			$settings[ $key ] = isset( $input[ $key ] ) && '1' === (string) ( is_scalar( $input[ $key ] ) ? $input[ $key ] : '' );
		}
		$settings['retention_days'] = isset( $input['retention_days'] ) && is_scalar( $input['retention_days'] ) ? min( 3650, max( 0, (int) $input['retention_days'] ) ) : 30;
		foreach ( array( 'antispam_observe', 'quarantine_entries', 'email_dns_check' ) as $key ) {
			$settings[ $key ] = empty( $input['antispam_present'] ) ? $previous[ $key ] : ( isset( $input[ $key ] ) && '1' === (string) ( is_scalar( $input[ $key ] ) ? $input[ $key ] : '' ) );
		}
		foreach ( array(
			'antispam_level'      => array( 1, 3 ),
			'spam_retention_days' => array( 1, 3650 ),
			'quarantine_limit'    => array( 1, 5000 ),
		) as $key => $bounds ) {
			$value = $input[ $key ] ?? $previous[ $key ];
			if ( is_scalar( $value ) && preg_match( '/^\d+$/D', (string) $value ) && (int) $value >= $bounds[0] && (int) $value <= $bounds[1] ) {
				$settings[ $key ] = (int) $value;
			} else {
				$settings[ $key ] = $previous[ $key ];
				add_settings_error( Settings::OPTION, $key, __( 'An anti-spam value is not valid; the previous one was kept.', 'easyformly' ) );
			}
		}
		$country                   = $input['phone_country'] ?? $previous['phone_country'];
		$settings['phone_country'] = is_string( $country ) && ( '' === $country || Phone::valid_country( $country ) ) ? $country : $previous['phone_country'];
		$value                     = $input['spam_phrases'] ?? $previous['spam_phrases'];
		$result                    = is_string( $value ) ? AntiSpam::phrase_list( $value ) : new WP_Error( 'type' );
		$settings['spam_phrases']  = is_wp_error( $result ) ? $previous['spam_phrases'] : $result;
		if ( is_wp_error( $result ) ) {
			add_settings_error( Settings::OPTION, 'spam_phrases', __( 'An anti-spam list is not valid; the previous one was kept.', 'easyformly' ) );
		}
		$settings['turnstile_site_key'] = isset( $input['turnstile_site_key'] ) && is_string( $input['turnstile_site_key'] ) ? sanitize_text_field( $input['turnstile_site_key'] ) : '';
		if ( strlen( $settings['turnstile_site_key'] ) > 256 || ! preg_match( '/^[A-Za-z0-9_-]*$/D', $settings['turnstile_site_key'] ) ) {
			$settings['turnstile_site_key'] = $previous['turnstile_site_key'];
			add_settings_error( Settings::OPTION, 'turnstile_site_key', __( 'The site key is not valid; the previous one was kept.', 'easyformly' ) );
		}
		foreach ( array( 'mailerlite_token', 'turnstile_secret' ) as $key ) {
			$value            = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? trim( $input[ $key ] ) : '';
			$settings[ $key ] = ! empty( $input[ 'clear_' . $key ] ) ? '' : ( '' !== $value ? $value : $previous[ $key ] );
			if ( preg_match( '/[\x00-\x20\x7f]/', $settings[ $key ] ) || strlen( $settings[ $key ] ) > 2000 ) {
				$settings[ $key ] = $previous[ $key ];
				add_settings_error( Settings::OPTION, $key, __( 'The credential contains invalid characters; the previous one was kept.', 'easyformly' ) );
			}
		}
		// Every branch above keeps the schema; this guard catches a previous value or a future bound that drifts from
		// it, one key at a time, so an old stored value never blocks saving the others.
		foreach ( Settings::properties() as $key => $property ) {
			if ( true !== rest_validate_value_from_schema( $settings[ $key ], $property, $key ) ) {
				$settings[ $key ] = $property['default'];
				add_settings_error( Settings::OPTION, $key, __( 'A value was not valid and was reset to its default.', 'easyformly' ) );
			}
		}
		return $settings;
	}

	/**
	 * Render the native authorized settings page.
	 *
	 * @return void
	 */
	public static function settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>EasyFormly · ' . esc_html__( 'Settings', 'easyformly' ) . '</h1>';
		settings_errors();
		$notice_key = 'easyformly_disposable_notice_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		if ( is_array( $notice ) ) {
			delete_transient( $notice_key );
			echo '<div class="notice ' . ( $notice['error'] ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		echo '<form method="post" action="options.php">';
		settings_fields( 'easyformly_settings_group' );
		do_settings_sections( 'easyformly-settings' );
		submit_button();
		echo '</form><form id="easyformly-disposable-sync" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="easyformly_sync_disposable_domains">';
		wp_nonce_field( 'easyformly_sync_disposable_domains' );
		echo '</form></div>';
	}

	/**
	 * Manual synchronization of the disposable-domain list: administrators only, POST with nonce.
	 */
	public static function sync_disposable_domains(): void {
		if ( ! current_user_can( 'manage_options' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Action not allowed.', 'easyformly' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'easyformly_sync_disposable_domains' );
		$result = DisposableDomains::sync();
		$error  = is_wp_error( $result );
		/* translators: %s: number of domains. */
		$message = $error ? $result->get_error_message() : sprintf( __( 'Synchronization complete: %s domains in the local list.', 'easyformly' ), number_format_i18n( $result['count'] ) );
		set_transient(
			'easyformly_disposable_notice_' . get_current_user_id(),
			array(
				'error'   => $error,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Plugin::FORM . '&page=easyformly-settings#easyformly-disposable-domains' ) );
		exit;
	}
}
