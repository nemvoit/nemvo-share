<?php
/**
 * Plugin Name:       Nemvo Share
 * Plugin URI:        https://github.com/nemvoit/nemvo-share
 * Description:       Share notes and files through secret links, with optional passwords, expiry and burn after reading. Files are encrypted on disk and never reachable directly.
 * Version:           1.0.2
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Nemvo
 * Author URI:        https://nemvo.eu
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       nemvo-share
 * Domain Path:       /languages
 *
 * @package NemvoShare
 */

defined( 'ABSPATH' ) || exit;

define( 'NEMVO_SHARE_VERSION', '1.0.2' );
define( 'NEMVO_SHARE_FILE', __FILE__ );
define( 'NEMVO_SHARE_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEMVO_SHARE_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoloader: NemvoShare\List_Table => includes/class-list-table.php,
 * NemvoShare\Admin\Editor => includes/admin/class-editor.php.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'NemvoShare\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name  = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
		$dir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$file  = NEMVO_SHARE_DIR . 'includes/' . $dir . $name;
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'NemvoShare\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NemvoShare\\Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'NemvoShare\\Plugin', 'init' ) );
