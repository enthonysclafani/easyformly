<?php
/**
 * Registered schema of the settings and of the form meta.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\Plugin;
use EasyFormly\Settings\Admin\Page;
use EasyFormly\Settings\Settings;

/**
 * The schema describes what is saved; the secrets it covers never reach REST.
 */
class SettingsTest extends TestCase {

	public function test_schema_covers_every_setting_with_its_default(): void {
		$schema   = Settings::schema();
		$defaults = Settings::defaults();

		$this->assertSame( array_keys( $defaults ), array_keys( $schema['properties'] ), 'One property per setting, in order' );
		$this->assertSame( $defaults, array_combine( array_keys( $defaults ), array_column( $schema['properties'], 'default' ) ), 'Every property defaults to the setting default' );
		$this->assertTrue( rest_validate_value_from_schema( $defaults, $schema ), 'The defaults are valid' );
	}

	public function test_saved_settings_match_the_schema(): void {
		$settings = Page::sanitize_settings(
			array(
				'antispam_present'   => '1',
				'store_entries'      => '1',
				'retention_days'     => '99999',
				'antispam_level'     => '3',
				'phone_country'      => 'IT',
				'spam_phrases'       => "Offerta imperdibile\nGuadagna subito",
				'turnstile_site_key' => 'site-key_123',
				'mailerlite_token'   => 'token-value',
			)
		);

		$this->assertTrue( rest_validate_value_from_schema( $settings, Settings::schema() ), 'What is saved matches the schema' );
		$this->assertSame( 3650, $settings['retention_days'], 'Retention is capped as before' );
		$this->assertSame( 3, $settings['antispam_level'] );
		$this->assertSame( 'IT', $settings['phone_country'] );
	}

	public function test_a_stored_value_outside_the_schema_falls_back_to_its_default_without_blocking_the_rest(): void {
		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'phone_country' => 'italy' ) ), false );

		$settings = Page::sanitize_settings( array( 'store_entries' => '1' ) );

		$this->assertSame( '', $settings['phone_country'], 'The invalid stored country is reset' );
		$this->assertTrue( $settings['store_entries'], 'The other settings are still saved' );
	}

	public function test_the_option_with_its_secrets_is_registered_without_rest(): void {
		Page::settings();
		$registered = get_registered_settings()[ Settings::OPTION ];

		$this->assertSame( 'object', $registered['type'] );
		$this->assertFalse( $registered['show_in_rest'], 'The token and the Turnstile secret never reach REST' );
	}

	public function test_form_meta_is_registered_private(): void {
		// The test suite unregisters meta keys after every test: register them again as `init` does.
		Plugin::init();
		$keys = get_registered_meta_keys( 'post', Plugin::FORM );

		foreach ( array( '_easyformly_definition', '_easyformly_last_delivery' ) as $key ) {
			$this->assertArrayHasKey( $key, $keys );
			$this->assertTrue( $keys[ $key ]['single'] );
			$this->assertFalse( $keys[ $key ]['show_in_rest'] );
		}

		$form = $this->form( $this->definition() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $form, '_easyformly_definition' ), 'Not editable through the generic meta APIs' );
		$this->assertIsArray( get_post_meta( $form, '_easyformly_definition', true ), 'The definition still reads as before' );
		$this->assertSame( '', get_post_meta( $form, '_easyformly_last_delivery', true ), 'A missing meta still reads as an empty string' );
	}
}
