<?php
/**
 * Form definitions: tag template, parsed schema, conditional rules and per-form configuration.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Forms;

use EasyFormly\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Read a form definition with the defaults of its configuration.
 */
final class Forms {
	/**
	 * Register the form meta: private to the plugin, out of REST and of the generic custom-field screens.
	 *
	 * Runs on `init` after the post types. Without `default`, a missing meta still reads as an empty string.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		foreach ( array(
			'_easyformly_definition'    => 'Template, parsed schema, conditional rules and configuration of the form.',
			'_easyformly_last_delivery' => 'Outcome of the latest delivery, shown in the form list.',
		) as $key => $description ) {
			register_post_meta(
				Plugin::FORM,
				$key,
				array(
					'type'          => 'object',
					'description'   => $description,
					'single'        => true,
					'show_in_rest'  => false,
					// Written only by the form editor and by deliveries, never through the generic meta APIs.
					'auth_callback' => '__return_false',
				)
			);
		}
	}

	/**
	 * Return the initial minimal form template.
	 *
	 * @return string Result of this operation.
	 */
	public static function default_template(): string {
		// Field names stay fixed; only the labels follow the site language. Quotes would end a label early.
		$label = static fn( string $text ): string => '"' . str_replace( array( '"', '[', ']' ), '', $text ) . '"';
		return '[text* nome ' . $label( __( 'Name', 'easyformly' ) ) . ']' . "\n"
			. '[email* email ' . $label( __( 'Email', 'easyformly' ) ) . ']' . "\n"
			. '[select* motivo ' . $label( __( 'Reason', 'easyformly' ) ) . ' ' . $label( __( 'Information', 'easyformly' ) ) . ' ' . $label( __( 'Quote', 'easyformly' ) ) . ']' . "\n"
			. '[number budget ' . $label( __( 'Estimated budget', 'easyformly' ) ) . ' min:0 step:1]' . "\n"
			. '[textarea* messaggio ' . $label( __( 'Message', 'easyformly' ) ) . ']' . "\n"
			. '[checkbox newsletter ' . $label( __( 'I would like to receive the newsletter', 'easyformly' ) ) . ']' . "\n"
			. '[acceptance* privacy ' . $label( __( 'I have read the privacy policy', 'easyformly' ) ) . ']' . "\n"
			. '[submit ' . $label( __( 'Send message', 'easyformly' ) ) . ']';
	}

	/**
	 * Return defaults for a new form.
	 *
	 * @return array<string, mixed> Result of this operation.
	 */
	public static function config_defaults(): array {
		return array(
			'mail_enabled'       => true,
			'mail_to'            => get_option( 'admin_email' ),
			'mail_subject'       => __( 'New message: [form]', 'easyformly' ),
			'mail_reply_to'      => 'email',
			'mail_body'          => '[fields]',
			'store_entries'      => 'inherit',
			'conditions_match'   => 'all',
			'captcha'            => false,
			'antispam_level'     => 'inherit',
			'antispam_observe'   => 'inherit',
			'quarantine_entries' => 'inherit',
			'email_dns_check'    => 'inherit',
			'phone_country'      => '',
			'spam_phrases'       => '',
			'mailerlite_enabled' => false,
			'mailerlite_email'   => 'email',
			'mailerlite_name'    => 'nome',
			'mailerlite_consent' => 'newsletter',
			'mailerlite_groups'  => '',
			'slack_enabled'      => false,
			'slack_webhook'      => '',
			'slack_fields'       => 'nome,email,messaggio',
			'success_message'    => __( 'Thank you, your message has been received.', 'easyformly' ),
		);
	}

	/**
	 * Read a saved form definition.
	 *
	 * @param int $form_id Published form ID.
	 * @return array<string, mixed>|null Validated definition, or null when the form has none.
	 */
	public static function definition( int $form_id ): ?array {
		$definition = get_post_meta( $form_id, '_easyformly_definition', true );
		if ( is_array( $definition ) && isset( $definition['schema']['fields'], $definition['conditions']['order'], $definition['config'] ) ) {
			$definition['config'] = array_merge( self::config_defaults(), $definition['config'] );
			return $definition;
		}
		return null;
	}

	/**
	 * Read a saved form definition, or the default form for the editor.
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, mixed> Saved or default definition.
	 */
	public static function editor_definition( int $form_id ): array {
		$definition = self::definition( $form_id );
		if ( null !== $definition ) {
			return $definition;
		}
		$template   = self::default_template();
		$schema     = Schema::parse( $template );
		$conditions = is_wp_error( $schema ) ? $schema : Schema::rules(
			$schema['fields'],
			array(
				array(
					'target'   => 'budget',
					'source'   => 'motivo',
					'operator' => 'equals',
					'value'    => 'Preventivo',
				),
			)
		);
		if ( is_wp_error( $schema ) || is_wp_error( $conditions ) ) {
			// Only a broken translation of the default labels gets here: the editor then starts empty.
			$template   = '';
			$schema     = array(
				'fields' => array(),
				'items'  => array(),
				'submit' => '',
			);
			$conditions = array(
				'rules' => array(),
				'order' => array(),
			);
		}
		return array(
			'template'   => $template,
			'schema'     => $schema,
			'conditions' => $conditions,
			'config'     => self::config_defaults(),
		);
	}
}
