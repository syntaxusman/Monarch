<?php
/**
 * Plugin Name:       ProBackup – Site Migration
 * Description:       Lightweight whole-site migration. Chunked, resumable database export/import that never times out, plus encrypted peer-to-peer transfer powered by croc.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            StratonAlly
 * License:           GPL-2.0-or-later
 * Text Domain:       probackup
 *
 * @package ProBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PROBACKUP_VERSION', '1.0.0' );
define( 'PROBACKUP_FILE', __FILE__ );
define( 'PROBACKUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'PROBACKUP_URL', plugin_dir_url( __FILE__ ) );
define( 'PROBACKUP_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'PB_' ) ) {
			return;
		}
		$file = PROBACKUP_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		PB_Storage::ensure();
	}
);

add_action(
	'plugins_loaded',
	function () {
		PB_Plugin::instance();
	}
);
