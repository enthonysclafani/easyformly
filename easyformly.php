<?php
/**
 * Plugin Name: EasyFormly
 * Description: Contact forms with local anti-spam, international phone numbers, conditional fields, MailerLite, Slack and Turnstile.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Update URI: false
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: easyformly
 * Domain Path: /languages
 *
 * @package EasyFormly
 */

defined( 'ABSPATH' ) || exit;

define( 'EASYFORMLY_VERSION', '1.0.0' );
define( 'EASYFORMLY_FILE', __FILE__ );
define( 'EASYFORMLY_DIR', plugin_dir_path( __FILE__ ) );
define( 'EASYFORMLY_URL', plugin_dir_url( __FILE__ ) );

require_once EASYFORMLY_DIR . 'src/autoload.php';

register_activation_hook( __FILE__, array( 'EasyFormly\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EasyFormly\\Plugin', 'deactivate' ) );
EasyFormly\Plugin::register();
