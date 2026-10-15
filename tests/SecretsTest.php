<?php
/**
 * Integration secrets stay on the server.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\Forms\Admin\Editor;
use EasyFormly\Forms\Renderer;
use EasyFormly\Privacy\Privacy;
use EasyFormly\Settings\Admin\Page;
use EasyFormly\Settings\Settings;
use EasyFormly\Submissions\Endpoint;

/**
 * The MailerLite token, the Turnstile secret and the Slack webhook never reach the page, REST, email,
 * the privacy export or the admin screens (CLAUDE.md, "Sicurezza").
 */
class SecretsTest extends TestCase {

	private const SECRETS = array(
		'mailerlite_token' => 'ml-secret-fixture',
		'turnstile_secret' => 'ts-secret-fixture',
		'slack_webhook'    => 'https://hooks.slack.com/services/T0SECRET/B0SECRET/WEBHOOKSECRET',
	);

	/**
	 * Everything the plugin prints or sends, for a form that uses all three secrets.
	 */
	public function test_secrets_never_leave_the_server(): void {
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::get(),
				array(
					'mailerlite_token'   => self::SECRETS['mailerlite_token'],
					'turnstile_site_key' => 'site-key_123',
					'turnstile_secret'   => self::SECRETS['turnstile_secret'],
				)
			),
			false
		);
		$config     = array(
			'slack_enabled'      => true,
			'slack_webhook'      => self::SECRETS['slack_webhook'],
			'slack_fields'       => 'nome,messaggio',
			'mailerlite_enabled' => true,
			'mailerlite_email'   => 'email',
			'mailerlite_name'    => 'nome',
			'mailerlite_consent' => 'newsletter',
			'mailerlite_groups'  => '1',
		);
		$definition = $this->definition( $config );
		$id         = $this->form( $definition );
		$mails      = array();
		add_filter(
			'pre_wp_mail',
			static function ( $result, $atts ) use ( &$mails ) {
				$mails[] = $atts;
				return $result;
			},
			5,
			2
		);
		$output = array();

		$captcha           = $this->definition( array_merge( $config, array( 'captcha' => true ) ) );
		$output['page']    = Renderer::render( $this->form( $captcha ), $captcha );
		$output['token']   = Endpoint::issue( $id );
		$output['submit']  = $this->submit( $id, $this->input( $id, $this->values( array( 'newsletter' => '1' ) ) ) );
		$output['mail']    = $mails;
		$output['privacy'] = Privacy::export( 'Maria+preventivo@example.org' );

		$this->login_admin();
		$post = get_post( $id );
		ob_start();
		Editor::integrations_box( $post );
		Editor::options_box( $post );
		Page::settings();
		Page::settings_page();
		$output['admin'] = ob_get_clean();

		$this->assertSame( 1, count( $mails ), 'The notification is sent' );
		$this->assertStringContainsString( 'Configured; leave empty to keep it', $output['admin'], 'The admin shows that a secret is saved' );
		$this->assertStringContainsString( 'easyformly_settings[mailerlite_token]', $output['admin'], 'The settings fields are printed' );
		foreach ( $output as $where => $value ) {
			$text = is_string( $value ) ? $value : (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			foreach ( self::SECRETS as $name => $secret ) {
				$this->assertFalse( str_contains( $text, $secret ), $name . ' appears in ' . $where );
			}
		}
	}
}
