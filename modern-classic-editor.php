<?php
/**
 * Plugin Name:       Modern Classic Editor
 * Plugin URI:         https://github.com/PeopleInside/wp-moderneditor
 * Description:       Disattiva Gutenberg e sostituisce l'editor classico di WordPress con TinyMCE 7 moderno (caricato via CDN), con supporto dark mode e toolbar avanzata.
 * Version:           1.0.3
 * Requires at least: 6.0
 * Requires PHP:       7.4
 * Author:             PeopleInside
 * License:             GPL v2 or later
 * License URI:        https://github.com/PeopleInside/wp-moderneditor/blob/main/LICENSE
 * Text Domain:         modern-classic-editor
 */

// Evita l'accesso diretto al file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCE_PLUGIN_VERSION', '1.0.1' );
define( 'MCE_PLUGIN_FILE', __FILE__ );
define( 'MCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Versione di TinyMCE caricata da CDN (jsDelivr, GPL, nessuna API key richiesta).
define( 'MCE_TINYMCE_CDN_VERSION', '7' );

require_once MCE_PLUGIN_DIR . 'includes/class-mce-settings.php';
require_once MCE_PLUGIN_DIR . 'includes/class-mce-gutenberg.php';
require_once MCE_PLUGIN_DIR . 'includes/class-mce-editor.php';

/**
 * Bootstrap del plugin.
 */
final class Modern_Classic_Editor {

	private static ?Modern_Classic_Editor $instance = null;

	public static function instance(): Modern_Classic_Editor {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		MCE_Settings::instance();
		MCE_Gutenberg::instance();
		MCE_Editor::instance();

		register_activation_hook( MCE_PLUGIN_FILE, array( $this, 'on_activate' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	public function on_activate(): void {
		$defaults = MCE_Settings::get_defaults();
		if ( false === get_option( MCE_Settings::OPTION_KEY ) ) {
			add_option( MCE_Settings::OPTION_KEY, $defaults );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'modern-classic-editor', false, dirname( plugin_basename( MCE_PLUGIN_FILE ) ) . '/languages' );
	}
}

Modern_Classic_Editor::instance();
