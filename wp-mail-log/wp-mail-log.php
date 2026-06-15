<?php

/**
 * Plugin Name: WP Mail Log
 * Description: WP Mail Log helps you to Log and view all emails from WordPress.
 * Plugin URI: https://wpvibes.com/
 * Author: WPVibes
 * Version: 1.1.5
 * Author URI: https://wpvibes.com/
 * License:      GNU General Public License v2 or later
 * License URI:  http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpv-wml
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


define( 'WML_URL', plugins_url( '/', __FILE__ ) );
define( 'WML_PATH', plugin_dir_path( __FILE__ ) );
define( 'WML_BASE', plugin_basename( __FILE__ ) );
define( 'WML_FILE', __FILE__ );
define( 'WML_VERSION', '1.1.5' );

if ( file_exists( WML_PATH . 'vendor-prefixed/autoload.php' ) ) {
	require WML_PATH . 'vendor-prefixed/autoload.php';
}

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( \WPVibes\WPMailLog\Vendor\WPVibes\ReviewReminder\ReviewReminder::class ) ) {
		return;
	}
	\WPVibes\WPMailLog\Vendor\WPVibes\ReviewReminder\ReviewReminder::register(
		[
			'plugin_slug'   => 'wp-mail-log',
			'plugin_name'   => 'WP Mail Log',
			'plugin_file'   => WML_FILE,
			'text_domain'   => 'wpv-wml',
			'triggers'      => [
				'time'  => 5 * DAY_IN_SECONDS,
				'usage' => [
					'option_key' => 'emails_logged',
					'threshold'  => 10,
				],
			],
			'trigger_logic' => 'OR',
			'screens'       => [ 'toplevel_page_wp-mail-log' ],
			'capability'    => 'manage_options',
			'icon_url'      => WML_URL . 'assets/images/wp-mail-log-logo.svg',
			'message_heading' => 'Enjoying WP Mail Log?',
			'message_body'    => "Glad it's helping you keep an eye on your emails! If it's been useful, a quick review on WordPress.org would mean a lot to our team and helps other folks discover the plugin.",
		]
	);
} );

require WML_PATH . 'includes/bootstrap.php';
