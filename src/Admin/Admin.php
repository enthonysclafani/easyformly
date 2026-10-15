<?php
/**
 * Shared administration: menu, assets, support page and row actions.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Admin;

use EasyFormly\Plugin;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Share the plugin menu, assets and list-table actions between forms and entries.
 */
final class Admin {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
	}

	/**
	 * Remove the form creation shortcut and add the support submenu.
	 *
	 * @return void
	 */
	public static function menu(): void {
		remove_submenu_page( 'edit.php?post_type=' . Plugin::FORM, 'post-new.php?post_type=' . Plugin::FORM );
		add_submenu_page( 'edit.php?post_type=' . Plugin::FORM, __( 'Support', 'easyformly' ), __( 'Support', 'easyformly' ), 'manage_options', 'easyformly-support', array( self::class, 'support_page' ) );
	}

	/**
	 * Enqueue administrative assets only on this plugin’s screens.
	 *
	 * @return void
	 */
	public static function assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || ( ! in_array( $screen->post_type, array( Plugin::FORM, Plugin::ENTRY ), true ) && ! str_contains( $screen->id, 'easyformly-settings' ) && ! str_contains( $screen->id, 'easyformly-support' ) ) ) {
			return;
		}
		wp_enqueue_style( 'easyformly-admin', plugins_url( 'assets/admin.css', EASYFORMLY_FILE ), array(), EASYFORMLY_VERSION );
		if ( str_contains( $screen->id, 'easyformly-support' ) ) {
			return;
		}
		wp_enqueue_script( 'easyformly-admin', plugins_url( 'assets/admin.js', EASYFORMLY_FILE ), array(), EASYFORMLY_VERSION, true );
		wp_localize_script(
			'easyformly-admin',
			'easyformlyAdmin',
			array(
				'optionOne' => __( 'Option 1', 'easyformly' ),
				'optionTwo' => __( 'Option 2', 'easyformly' ),
			)
		);
	}

	/**
	 * Render form syntax and examples on the authorized support page.
	 *
	 * @return void
	 */
	public static function support_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap easyformly-support">
			<h1>EasyFormly · <?php esc_html_e( 'Support', 'easyformly' ); ?></h1>
			<h2><?php esc_html_e( 'Syntax and examples', 'easyformly' ); ?></h2>
			<p><?php esc_html_e( 'Separate fields on the same line with | to place them in equal-width columns. Start a new line for a new row. An asterisk makes the field required. Save the form to update the fields available in the rules.', 'easyformly' ); ?></p>
			<p><?php esc_html_e( 'Two columns (50% each):', 'easyformly' ); ?></p>
			<pre><?php /* translators: Example form syntax: translate only the labels in quotes. */ echo esc_html__( '[text* name "Name"] | [email* email "Email"]', 'easyformly' ); ?></pre>
			<p><?php esc_html_e( 'Three columns (33.33% each):', 'easyformly' ); ?></p>
			<pre><?php /* translators: Example form syntax: translate only the labels in quotes. */ echo esc_html__( '[text* name "Name"] | [text* surname "Surname"] | [email* email "Email"]', 'easyformly' ); ?></pre>
			<p><?php esc_html_e( 'Widths account for the space between columns. On small screens fields stack vertically. Notes and the button must stay on their own line.', 'easyformly' ); ?></p>
			<pre><?php /* translators: Example form syntax, one tag per line: translate only the labels in quotes. */ echo esc_html__( "[select reason \"Reason\" \"Information\" \"Quote\"]\n[number budget \"Budget\" min:0 max:100000 step:1]\n[checkbox newsletter \"I would like to receive the newsletter\"]\n[acceptance* privacy \"I have read the privacy policy\"]\n[note \"A short hint\"]\n[submit \"Send\"]", 'easyformly' ); ?></pre>
			<pre><?php /* translators: Example form syntax, one tag per line: translate only the labels in quotes. */ echo esc_html__( "[tel phone \"Phone\"]\n[tel* cell \"Mobile\" mobile country:IT]\n[tel contact \"Contact number\" allow-repeated]", 'easyformly' ); ?></pre>
			<p><?php esc_html_e( 'The phone field includes a country selector. country:IT sets the initial country, mobile requires a mobile number and allow-repeated accepts fully repetitive numbers. email, textarea and url are also available. Use unique lowercase names.', 'easyformly' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Remove unsupported inline editing and public view actions.
	 *
	 * @param array<string, string> $actions Native row actions.
	 * @param WP_Post               $post Native WordPress post.
	 * @return array<string, string> Result of this operation.
	 */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( in_array( $post->post_type, array( Plugin::FORM, Plugin::ENTRY ), true ) ) {
			unset( $actions['inline hide-if-no-js'], $actions['view'] );
		}
		return $actions;
	}
}
