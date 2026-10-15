<?php
/**
 * Form editor: native metaboxes, validated saving, saved preview and the form list columns.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Forms\Admin;

use EasyFormly\AntiSpam\AntiSpam;
use EasyFormly\Delivery\Admin\Outcomes;
use EasyFormly\Delivery\Delivery;
use EasyFormly\Forms\Forms;
use EasyFormly\Forms\Renderer;
use EasyFormly\Forms\Schema;
use EasyFormly\Phone\Admin\CountrySelect;
use EasyFormly\Phone\Phone;
use EasyFormly\Plugin;
use EasyFormly\Settings\Settings;
use EasyFormly\Submissions\Entries;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The form edit screen, for administrators only.
 */
final class Editor {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'add_meta_boxes', array( self::class, 'boxes' ) );
		add_action( 'save_post_' . Plugin::FORM, array( self::class, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_action( 'admin_post_easyformly_preview', array( self::class, 'preview' ) );
		add_filter( 'manage_' . Plugin::FORM . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . Plugin::FORM . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
	}

	/**
	 * Register the form metaboxes.
	 *
	 * @return void
	 */
	public static function boxes(): void {
		add_meta_box( 'easyformly-module', __( 'Form', 'easyformly' ), array( self::class, 'module_box' ), Plugin::FORM, 'normal', 'high' );
		add_meta_box( 'easyformly-conditions', __( 'Conditional fields', 'easyformly' ), array( self::class, 'conditions_box' ), Plugin::FORM, 'normal' );
		add_meta_box( 'easyformly-email', __( 'Email', 'easyformly' ), array( self::class, 'email_box' ), Plugin::FORM, 'normal' );
		add_meta_box( 'easyformly-integrations', __( 'Integrations', 'easyformly' ), array( self::class, 'integrations_box' ), Plugin::FORM, 'normal' );
		add_meta_box( 'easyformly-antispam', __( 'Anti-spam and international phone', 'easyformly' ), array( self::class, 'antispam_box' ), Plugin::FORM, 'normal' );
		add_meta_box( 'easyformly-options', __( 'Options', 'easyformly' ), array( self::class, 'options_box' ), Plugin::FORM, 'side' );
	}

	/**
	 * Add the shortcode and last delivery columns to the form list.
	 *
	 * @param array<string, string> $columns Native list-table columns.
	 * @return array<string, string> Result of this operation.
	 */
	public static function columns( array $columns ): array {
		$columns['easyformly-shortcode'] = 'Shortcode';
		$columns['easyformly-delivery']  = __( 'Submission outcome', 'easyformly' );
		return $columns;
	}

	/**
	 * Render a form list column.
	 *
	 * @param string $column Current list-table column name.
	 * @param int    $id Native WordPress post ID.
	 * @return void
	 */
	public static function column( string $column, int $id ): void {
		if ( 'easyformly-shortcode' === $column ) {
			echo '<code>' . esc_html( '[easyformly id="' . $id . '"]' ) . '</code>';
		}
		if ( 'easyformly-delivery' === $column ) {
			$status = get_post_meta( $id, '_easyformly_last_delivery', true );
			if ( is_array( $status ) ) {
				Outcomes::render( $status['outcomes'] );
			}
		}
	}

	/**
	 * Render the restricted tag editor and saved preview link.
	 *
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function module_box( WP_Post $post ): void {
		$definition = Forms::editor_definition( $post->ID );
		wp_nonce_field( 'easyformly_editor_' . $post->ID, 'easyformly_editor_nonce' );
		?>
		<p class="easyformly-toolbar">
		<?php
		foreach ( array(
			'text'       => __( 'Text', 'easyformly' ),
			'email'      => 'Email',
			'textarea'   => __( 'Message', 'easyformly' ),
			'select'     => __( 'Select', 'easyformly' ),
			'number'     => __( 'Number', 'easyformly' ),
			'tel'        => __( 'Phone', 'easyformly' ),
			'checkbox'   => __( 'Checkbox', 'easyformly' ),
			'acceptance' => __( 'Consent', 'easyformly' ),
		) as $type => $label ) :
			?>
	<button type="button" class="button" data-easyformly-insert="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></button> <?php endforeach; ?></p>
		<label class="screen-reader-text" for="easyformly-template"><?php esc_html_e( 'Form definition', 'easyformly' ); ?></label>
		<textarea class="large-text code" id="easyformly-template" name="easyformly_template" rows="13" maxlength="20000" spellcheck="false"><?php echo esc_textarea( $definition['template'] ); ?></textarea>
		<?php
		if ( 'auto-draft' !== $post->post_status ) :
			?>
			<p><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=easyformly_preview&id=' . $post->ID ), 'easyformly_preview_' . $post->ID ) ); ?>"><?php esc_html_e( 'Saved preview', 'easyformly' ); ?></a></p><?php endif; ?>
		<?php
	}

	/**
	 * Render a mapping selector using the saved schema.
	 *
	 * @param string               $key Internal identifier.
	 * @param string               $label Localized visible label.
	 * @param array<string, mixed> $definition Validated form definition.
	 * @param array<int, string>   $types Allowed field types; empty accepts all types.
	 * @param bool                 $allow_empty Whether the empty mapping is available.
	 * @return void
	 */
	private static function field_select( string $key, string $label, array $definition, array $types = array(), bool $allow_empty = true ): void {
		$value = $definition['config'][ $key ];
		echo '<p><label for="easyformly-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><select id="easyformly-' . esc_attr( $key ) . '" name="easyformly_config[' . esc_attr( $key ) . ']">';
		if ( $allow_empty ) {
			echo '<option value="">' . esc_html__( 'None', 'easyformly' ) . '</option>';
		}
		foreach ( $definition['schema']['fields'] as $name => $field ) {
			if ( ! $types || in_array( $field['type'], $types, true ) ) {
				echo '<option value="' . esc_attr( $name ) . '" ' . selected( $value, $name, false ) . '>' . esc_html( $field['label'] . ' (' . $name . ')' ) . '</option>';
			}
		}
		echo '</select></p>';
	}

	/**
	 * Render a configuration field while keeping secrets out of the response.
	 *
	 * @param string               $key Internal identifier.
	 * @param string               $label Localized visible label.
	 * @param array<string, mixed> $config Validated delivery and privacy configuration.
	 * @param string               $type Supported input type.
	 * @param string               $help Localized explanatory text.
	 * @return void
	 */
	private static function input( string $key, string $label, array $config, string $type = 'text', string $help = '' ): void {
		$is_secret = 'password' === $type;
		echo '<p><label for="easyformly-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><input class="large-text" type="' . esc_attr( $type ) . '" id="easyformly-' . esc_attr( $key ) . '" name="easyformly_config[' . esc_attr( $key ) . ']" value="' . esc_attr( $is_secret ? '' : $config[ $key ] ) . '"' . ( $is_secret ? ' autocomplete="new-password" placeholder="' . esc_attr( $config[ $key ] ? __( 'Configured; leave empty to keep it', 'easyformly' ) : '' ) . '"' : '' ) . '></p>';
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Render a boolean form preference.
	 *
	 * @param string               $key Internal identifier.
	 * @param string               $label Localized visible label.
	 * @param array<string, mixed> $config Validated delivery and privacy configuration.
	 * @return void
	 */
	private static function checkbox( string $key, string $label, array $config ): void {
		echo '<p><label><input type="checkbox" name="easyformly_config[' . esc_attr( $key ) . ']" value="1" ' . checked( $config[ $key ], true, false ) . '> ' . esc_html( $label ) . '</label></p>';
	}

	/**
	 * Render plain-text email configuration.
	 *
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function email_box( WP_Post $post ): void {
		$definition = Forms::editor_definition( $post->ID );
		$config     = $definition['config'];
		self::checkbox( 'mail_enabled', __( 'Send an email notification', 'easyformly' ), $config );
		self::input( 'mail_to', __( 'Recipients', 'easyformly' ), $config, 'text', __( 'Comma-separated email addresses.', 'easyformly' ) );
		self::input( 'mail_subject', __( 'Subject', 'easyformly' ), $config );
		self::field_select( 'mail_reply_to', __( 'Reply to the address in the field', 'easyformly' ), $definition, array( 'email' ) );
		echo '<p><label for="easyformly-mail-body">' . esc_html__( 'Message', 'easyformly' ) . '</label><textarea class="large-text code" id="easyformly-mail-body" name="easyformly_config[mail_body]" rows="6">' . esc_textarea( $config['mail_body'] ) . '</textarea></p><p class="description">' . esc_html__( '[fields] includes the visible fields; [form] the form title; [site] the site name. You can also use [field_name].', 'easyformly' ) . '</p>';
	}

	/**
	 * Render per-form consent and destination mappings.
	 *
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function integrations_box( WP_Post $post ): void {
		$definition = Forms::editor_definition( $post->ID );
		$config     = $definition['config'];
		echo '<h3>MailerLite</h3>';
		self::checkbox( 'mailerlite_enabled', __( 'Enable MailerLite for this form', 'easyformly' ), $config );
		self::field_select( 'mailerlite_email', 'Email', $definition, array( 'email' ) );
		self::field_select( 'mailerlite_name', __( 'Name', 'easyformly' ), $definition );
		self::field_select( 'mailerlite_consent', __( 'Newsletter consent field', 'easyformly' ), $definition, array( 'checkbox', 'acceptance' ) );
		self::input( 'mailerlite_groups', __( 'MailerLite group IDs', 'easyformly' ), $config, 'text', __( 'One or more comma-separated IDs. Subscription happens only when consent is selected. Double opt-in is configured in MailerLite, in the API and integrations settings.', 'easyformly' ) );
		echo '<h3>Slack</h3>';
		self::checkbox( 'slack_enabled', __( 'Send a Slack notification', 'easyformly' ), $config );
		self::input( 'slack_webhook', __( 'Channel webhook', 'easyformly' ), $config, 'password' );
		echo '<p><label><input type="checkbox" name="easyformly_config[clear_slack_webhook]" value="1"> ' . esc_html__( 'Remove the saved webhook', 'easyformly' ) . '</label></p>';
		self::input( 'slack_fields', __( 'Fields to include in the notification', 'easyformly' ), $config, 'text', __( 'Comma-separated field names. Only the visible fields chosen here are sent to Slack.', 'easyformly' ) );
		echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Plugin::FORM . '&page=easyformly-settings' ) ) . '">' . esc_html__( 'Configure the MailerLite token and the CAPTCHA in the settings', 'easyformly' ) . '</a></p>';
	}

	/**
	 * Render privacy, CAPTCHA and latest delivery outcomes.
	 *
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function options_box( WP_Post $post ): void {
		$config = Forms::editor_definition( $post->ID )['config'];
		self::checkbox( 'captcha', __( 'Verify with Cloudflare Turnstile', 'easyformly' ), $config );
		echo '<p><label for="easyformly-archive">' . esc_html__( 'Submission archive', 'easyformly' ) . '</label><br><select id="easyformly-archive" name="easyformly_config[store_entries]">';
		foreach ( array(
			'inherit' => __( 'Use the general settings', 'easyformly' ),
			'no'      => __( 'Off', 'easyformly' ),
			'yes'     => __( 'On', 'easyformly' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $config['store_entries'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		self::input( 'success_message', __( 'Confirmation message', 'easyformly' ), $config );
		$status = get_post_meta( $post->ID, '_easyformly_last_delivery', true );
		if ( is_array( $status ) ) {
			echo '<hr><p><strong>' . esc_html__( 'Last outcome', 'easyformly' ) . '</strong><br>' . esc_html( $status['time'] ) . '</p>';
			Outcomes::render( $status['outcomes'] );
		}
	}

	/**
	 * A form setting that can inherit the general settings.
	 *
	 * @param string               $key    Configuration key.
	 * @param string               $label  Visible label.
	 * @param array<string, mixed> $config Form configuration.
	 * @param string               $help   Description below the field.
	 */
	private static function inherited_select( string $key, string $label, array $config, string $help ): void {
		echo '<p><label for="easyformly-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><select id="easyformly-' . esc_attr( $key ) . '" name="easyformly_config[' . esc_attr( $key ) . ']">';
		foreach ( array(
			'inherit' => __( 'Use the general settings', 'easyformly' ),
			'yes'     => __( 'On', 'easyformly' ),
			'no'      => __( 'Off', 'easyformly' ),
		) as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $config[ $key ], $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></p><p class="description">' . esc_html( $help ) . '</p>';
	}

	/**
	 * Anti-spam settings of a form.
	 *
	 * @param WP_Post $post Form.
	 */
	public static function antispam_box( WP_Post $post ): void {
		$config = Forms::editor_definition( $post->ID )['config'];
		echo '<p><label for="easyformly-antispam-level">' . esc_html__( 'Anti-spam level', 'easyformly' ) . '</label><br><select id="easyformly-antispam-level" name="easyformly_config[antispam_level]">';
		foreach ( array(
			'inherit' => __( 'Use the general settings', 'easyformly' ),
			'1'       => __( '1 — Basic', 'easyformly' ),
			'2'       => __( '2 — Balanced', 'easyformly' ),
			'3'       => __( '3 — Strict', 'easyformly' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( $config['antispam_level'], (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p class="description">' . esc_html__( 'Validity checks and the honeypot are always on. Levels 2 and 3 add checks on content, disposable domains, repetitions and frequency. Level 3 is more sensitive and may need more reviews.', 'easyformly' ) . '</p>';
		self::inherited_select( 'quarantine_entries', __( 'Quarantine with the archive off', 'easyformly' ), $config, __( 'Keep spam and suspicious submissions even when the normal archive is off. With the archive on, suspicious submissions are always saved with delivery held. Without storage, they are rejected.', 'easyformly' ) );
		self::inherited_select( 'antispam_observe', __( 'Observation mode', 'easyformly' ), $config, __( 'Calculate the score without stopping submissions for heuristic signals. The honeypot stays on. To review the results, turn on the archive.', 'easyformly' ) );
		self::inherited_select( 'email_dns_check', __( 'Email domain DNS check', 'easyformly' ), $config, __( 'Check that the domain can receive mail. It does not check that the mailbox exists. Temporary resolver errors do not block the submission.', 'easyformly' ) );
		echo '<p><label for="easyformly-phone-country">' . esc_html__( 'Initial phone country', 'easyformly' ) . '</label><br>';
		CountrySelect::render( 'easyformly_config[phone_country]', $config['phone_country'], 'easyformly-phone-country', __( 'Use the general settings or the site language', 'easyformly' ) );
		echo '</p><p class="description">' . esc_html__( 'Visitors can choose any country. For languages without a recognizable country, set an initial value or leave the choice to the visitor.', 'easyformly' ) . '</p>';
		echo '<p>' . esc_html__( 'Disposable domains are recognized through the public lists shared by all forms at levels 2 and 3.', 'easyformly' ) . ' <a href="' . esc_url( admin_url( 'edit.php?post_type=' . Plugin::FORM . '&page=easyformly-settings#easyformly-disposable-domains' ) ) . '">' . esc_html__( 'Manage the list in the settings', 'easyformly' ) . '</a></p>';
		echo '<p><label for="easyformly-spam_phrases">' . esc_html__( 'Suspicious phrases for this form', 'easyformly' ) . '</label><textarea rows="3" class="large-text" id="easyformly-spam_phrases" name="easyformly_config[spam_phrases]">' . esc_textarea( $config['spam_phrases'] ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'One phrase per line. Form phrases add to the general ones and raise the score at levels 2 and 3; on their own they do not prove that a message is spam.', 'easyformly' ) . '</p>';
	}

	/**
	 * Return localized supported comparison labels.
	 *
	 * @return array<string, string> Result of this operation.
	 */
	private static function operators(): array {
		return array(
			'equals'     => __( 'Is equal to', 'easyformly' ),
			'not_equals' => __( 'Is not equal to', 'easyformly' ),
			'contains'   => __( 'Contains', 'easyformly' ),
			'empty'      => __( 'Is empty', 'easyformly' ),
			'not_empty'  => __( 'Is not empty', 'easyformly' ),
			'greater'    => __( 'Is greater than', 'easyformly' ),
			'less'       => __( 'Is less than', 'easyformly' ),
		);
	}

	/**
	 * Render a conditional editor row.
	 *
	 * @param mixed                               $index Unique editor row index.
	 * @param array<string, string>               $rule Validated conditional rule.
	 * @param array<string, array<string, mixed>> $fields Field definitions keyed by name.
	 * @return void
	 */
	private static function rule_row( $index, array $rule, array $fields ): void {
		echo '<tr>';
		foreach ( array(
			'target' => __( 'Field to show', 'easyformly' ),
			'source' => __( 'Field to compare', 'easyformly' ),
		) as $key => $label ) {
			echo '<td><select aria-label="' . esc_attr( $label ) . '" name="easyformly_rules[' . esc_attr( (string) $index ) . '][' . esc_attr( $key ) . ']"><option value="">—</option>';
			foreach ( $fields as $name => $field ) {
				echo '<option value="' . esc_attr( $name ) . '" ' . selected( $rule[ $key ] ?? '', $name, false ) . '>' . esc_html( $field['label'] . ' (' . $name . ')' ) . '</option>';
			}
			echo '</select></td>';
		}
		echo '<td><select aria-label="' . esc_attr__( 'Comparison', 'easyformly' ) . '" name="easyformly_rules[' . esc_attr( (string) $index ) . '][operator]">';
		foreach ( self::operators() as $operator => $label ) {
			echo '<option value="' . esc_attr( $operator ) . '" ' . selected( $rule['operator'] ?? 'equals', $operator, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td><td><input aria-label="' . esc_attr__( 'Value', 'easyformly' ) . '" type="text" name="easyformly_rules[' . esc_attr( (string) $index ) . '][value]" value="' . esc_attr( $rule['value'] ?? '' ) . '" maxlength="500"></td><td><button type="button" class="button-link-delete" data-easyformly-remove>' . esc_html__( 'Remove', 'easyformly' ) . '</button></td></tr>';
	}

	/**
	 * Render conditional rules and matching preferences.
	 *
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function conditions_box( WP_Post $post ): void {
		$definition = Forms::editor_definition( $post->ID );
		echo '<p>' . esc_html__( 'Show a field when the comparison is met. Hidden fields are neither validated nor sent. For a checkbox use the value 1 when checked.', 'easyformly' ) . '</p><p><label>' . esc_html__( 'For rules on the same field require', 'easyformly' ) . ' <select name="easyformly_config[conditions_match]"><option value="all" ' . selected( $definition['config']['conditions_match'], 'all', false ) . '>' . esc_html__( 'all conditions (AND)', 'easyformly' ) . '</option><option value="any" ' . selected( $definition['config']['conditions_match'], 'any', false ) . '>' . esc_html__( 'at least one condition (OR)', 'easyformly' ) . '</option></select></label></p>';
		echo '<div class="easyformly-rule-scroll"><table class="widefat easyformly-rules"><thead><tr><th>' . esc_html__( 'Show', 'easyformly' ) . '</th><th>' . esc_html__( 'When', 'easyformly' ) . '</th><th>' . esc_html__( 'Comparison', 'easyformly' ) . '</th><th>' . esc_html__( 'Value', 'easyformly' ) . '</th><th></th></tr></thead><tbody id="easyformly-rules">';
		foreach ( $definition['conditions']['rules'] as $index => $rule ) {
			self::rule_row( $index, $rule, $definition['schema']['fields'] );
		}
		echo '</tbody></table></div><p><button class="button" type="button" id="easyformly-add-rule">' . esc_html__( 'Add rule', 'easyformly' ) . '</button></p><template id="easyformly-rule-template">';
		self::rule_row( '__INDEX__', array(), $definition['schema']['fields'] );
		echo '</template>';
	}

	/**
	 * Validate an authorized editor submission before persisting it.
	 *
	 * @param int     $id Native WordPress post ID.
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function save( int $id, WP_Post $post ): void {
		$nonce = isset( $_POST['easyformly_editor_nonce'] ) && is_string( $_POST['easyformly_editor_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['easyformly_editor_nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'easyformly_editor_' . $id ) || ! current_user_can( 'manage_options' ) || Plugin::FORM !== $post->post_type || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Schema::parse validates the complete tag language before any persistence.
		$template = isset( $_POST['easyformly_template'] ) && is_string( $_POST['easyformly_template'] ) ? wp_unslash( $_POST['easyformly_template'] ) : '';
		$schema   = Schema::parse( $template );
		if ( is_wp_error( $schema ) ) {
			self::error( $id, $schema );
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Schema::rules sanitizes values and validates every name, operator and dependency.
		$rules_input = isset( $_POST['easyformly_rules'] ) && is_array( $_POST['easyformly_rules'] ) ? wp_unslash( $_POST['easyformly_rules'] ) : array();
		$conditions  = Schema::rules( $schema['fields'], $rules_input );
		if ( is_wp_error( $conditions ) ) {
			self::error( $id, $conditions );
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- self::config sanitizes and validates each supported setting; unknown keys are discarded.
		$config_input = isset( $_POST['easyformly_config'] ) && is_array( $_POST['easyformly_config'] ) ? wp_unslash( $_POST['easyformly_config'] ) : array();
		$config       = self::config( $config_input, Forms::editor_definition( $id )['config'], $schema['fields'] );
		if ( is_wp_error( $config ) ) {
			self::error( $id, $config );
			return;
		}
		$definition = compact( 'template', 'schema', 'conditions', 'config' );
		update_post_meta( $id, '_easyformly_definition', wp_slash( $definition ) );
		if ( get_post_meta( $id, '_easyformly_definition', true ) !== $definition ) {
			self::error( $id, new WP_Error( 'save', __( 'The form could not be saved. Try again.', 'easyformly' ) ) );
		}
	}

	/**
	 * Validate and normalize only supported per-form configuration.
	 *
	 * @param array<mixed>                        $input Untrusted input to validate.
	 * @param array<string, mixed>                $previous Previously validated configuration.
	 * @param array<string, array<string, mixed>> $fields Field definitions keyed by name.
	 * @return array<string, mixed>|WP_Error Result of this operation.
	 */
	public static function config( array $input, array $previous, array $fields ) {
		$config = Forms::config_defaults();
		foreach ( array( 'mail_enabled', 'mailerlite_enabled', 'slack_enabled', 'captcha' ) as $key ) {
			$config[ $key ] = isset( $input[ $key ] ) && '1' === (string) ( is_scalar( $input[ $key ] ) ? $input[ $key ] : '' );
		}
		foreach ( array( 'mail_to', 'mail_subject', 'mail_reply_to', 'mailerlite_email', 'mailerlite_name', 'mailerlite_consent', 'mailerlite_groups', 'slack_fields', 'success_message' ) as $key ) {
			$config[ $key ] = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : '';
			if ( strlen( $config[ $key ] ) > 2000 ) {
				return new WP_Error( 'config', __( 'An option is too long.', 'easyformly' ) );
			}
		}
		$config['mail_body']        = isset( $input['mail_body'] ) && is_string( $input['mail_body'] ) ? sanitize_textarea_field( $input['mail_body'] ) : '[fields]';
		$config['conditions_match'] = 'any' === ( $input['conditions_match'] ?? '' ) ? 'any' : 'all';
		$config['store_entries']    = in_array( $input['store_entries'] ?? '', array( 'inherit', 'yes', 'no' ), true ) ? $input['store_entries'] : 'inherit';
		$level                      = $input['antispam_level'] ?? ( $previous['antispam_level'] ?? 'inherit' );
		if ( ! is_scalar( $level ) || ! in_array( (string) $level, array( 'inherit', '1', '2', '3' ), true ) ) {
			return new WP_Error( 'config', __( 'Select an anti-spam level from 1 to 3.', 'easyformly' ) );
		}
		$config['antispam_level'] = (string) $level;
		foreach ( array( 'antispam_observe', 'quarantine_entries', 'email_dns_check' ) as $key ) {
			$value = $input[ $key ] ?? ( $previous[ $key ] ?? 'inherit' );
			if ( ! in_array( $value, array( 'inherit', 'yes', 'no' ), true ) ) {
				return new WP_Error( 'config', __( 'An anti-spam preference is not valid.', 'easyformly' ) );
			}
			$config[ $key ] = $value;
		}
		$country = $input['phone_country'] ?? ( $previous['phone_country'] ?? '' );
		if ( ! is_string( $country ) || ( '' !== $country && ! Phone::valid_country( $country ) ) ) {
			return new WP_Error( 'config', __( 'Select a valid initial country.', 'easyformly' ) );
		}
		$config['phone_country'] = $country;
		$phrases                 = $input['spam_phrases'] ?? ( $previous['spam_phrases'] ?? '' );
		if ( ! is_string( $phrases ) ) {
			return new WP_Error( 'config', __( 'Anti-spam rules must be text.', 'easyformly' ) );
		}
		$config['spam_phrases'] = AntiSpam::phrase_list( $phrases );
		if ( is_wp_error( $config['spam_phrases'] ) ) {
			return $config['spam_phrases'];
		}
		$config['slack_webhook'] = ! empty( $input['clear_slack_webhook'] ) ? '' : ( isset( $input['slack_webhook'] ) && is_string( $input['slack_webhook'] ) && '' !== trim( $input['slack_webhook'] ) ? trim( $input['slack_webhook'] ) : $previous['slack_webhook'] );
		if ( strlen( $config['slack_webhook'] ) > 512 ) {
			return new WP_Error( 'config', __( 'The Slack webhook is too long.', 'easyformly' ) );
		}
		if ( strlen( $config['mail_body'] ) > 20000 || strlen( $config['mail_subject'] ) > 300 || '' === $config['success_message'] || strlen( $config['success_message'] ) > 500 ) {
			return new WP_Error( 'config', __( 'Check the length of the messages and enter a confirmation for the visitor.', 'easyformly' ) );
		}
		if ( $config['mail_enabled'] ) {
			$addresses = array_map( 'trim', explode( ',', $config['mail_to'] ) );
			if ( ! $addresses || count( $addresses ) > 10 || count( array_filter( $addresses, static fn( $address ) => false !== is_email( $address ) ) ) !== count( $addresses ) || '' === $config['mail_subject'] || '' === $config['mail_body'] || ( '' !== $config['mail_reply_to'] && 'email' !== ( $fields[ $config['mail_reply_to'] ]['type'] ?? '' ) ) ) {
				return new WP_Error( 'config', __( 'Check the recipients, subject, message and Reply-To field of the email notification.', 'easyformly' ) );
			}
			$config['mail_to'] = implode( ',', $addresses );
		}
		if ( $config['mailerlite_enabled'] && ( '' === Settings::get()['mailerlite_token'] || 'email' !== ( $fields[ $config['mailerlite_email'] ]['type'] ?? '' ) || ( '' !== $config['mailerlite_name'] && ! isset( $fields[ $config['mailerlite_name'] ] ) ) || ! in_array( $fields[ $config['mailerlite_consent'] ]['type'] ?? '', array( 'checkbox', 'acceptance' ), true ) || ! preg_match( '/^\d{1,30}(?:\s*,\s*\d{1,30}){0,9}$/D', $config['mailerlite_groups'] ) ) ) {
			return new WP_Error( 'config', __( 'MailerLite needs a token, an email field, a newsletter consent field and at least one group ID.', 'easyformly' ) );
		}
		if ( ( $config['slack_webhook'] && ! Delivery::valid_slack_url( $config['slack_webhook'] ) ) || ( $config['slack_enabled'] && ! $config['slack_webhook'] ) ) {
			return new WP_Error( 'config', __( 'Enter a valid HTTPS webhook from hooks.slack.com.', 'easyformly' ) );
		}
		$slack_fields = array_filter( array_map( 'trim', explode( ',', $config['slack_fields'] ) ) );
		if ( $config['slack_enabled'] && ( ! $slack_fields || array_diff( $slack_fields, array_keys( $fields ) ) ) ) {
			return new WP_Error( 'config', __( 'Slack needs at least one existing field to include.', 'easyformly' ) );
		}
		$config['slack_fields'] = implode( ',', array_unique( $slack_fields ) );
		$settings               = Settings::get();
		if ( $config['captcha'] && ( ! $settings['turnstile_site_key'] || ! $settings['turnstile_secret'] ) ) {
			return new WP_Error( 'config', __( 'Configure both Turnstile keys before turning on the CAPTCHA.', 'easyformly' ) );
		}
		if ( ! $config['mail_enabled'] && ! $config['mailerlite_enabled'] && ! $config['slack_enabled'] && ! Entries::stores_entries( $config ) ) {
			return new WP_Error( 'config', __( 'Turn on at least one destination or the submission archive.', 'easyformly' ) );
		}
		return $config;
	}

	/**
	 * Store a short-lived notice for the current administrator.
	 *
	 * @param int      $id Native WordPress post ID.
	 * @param WP_Error $error Validated error without credentials or response bodies.
	 * @return void
	 */
	private static function error( int $id, WP_Error $error ): void {
		set_transient( 'easyformly_admin_error_' . get_current_user_id() . '_' . $id, $error->get_error_message(), MINUTE_IN_SECONDS );
	}

	/**
	 * Display the current administrator’s pending editor error.
	 *
	 * @return void
	 */
	public static function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads only the current administrator's own expiring notice; does not change form data.
		$id    = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$key   = 'easyformly_admin_error_' . get_current_user_id() . '_' . $id;
		$error = get_transient( $key );
		if ( $error && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The form definition was not updated: ', 'easyformly' ) . esc_html( $error ) . '</p></div>';
			delete_transient( $key );
		}
	}

	/**
	 * Render an authenticated saved preview without sending data.
	 *
	 * @return void
	 */
	public static function preview(): void {
		$id = isset( $_GET['id'] ) && is_scalar( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || ! $id || Plugin::FORM !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Preview not available.', 'easyformly' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'easyformly_preview_' . $id );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		Renderer::preview( $id );
		exit;
	}
}
