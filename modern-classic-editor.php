<?php
/**
 * Plugin Name:       Modern Classic Editor
 * Plugin URI:         https://github.com/PeopleInside/wp-moderneditor
 * Description:       Disattiva Gutenberg e sostituisce l'editor classico di WordPress con TinyMCE moderno (7 o 8, a scelta), caricato da CDN oppure offline (bundlato/scaricabile), con supporto dark mode e toolbar avanzata.
 * Version:           1.3.9
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

define( 'MCE_PLUGIN_VERSION', '1.3.9' );
define( 'MCE_PLUGIN_FILE', __FILE__ );
define( 'MCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MCE_PLUGIN_DIR . 'includes/class-mce-settings.php';
require_once MCE_PLUGIN_DIR . 'includes/class-mce-vendor.php';
require_once MCE_PLUGIN_DIR . 'includes/class-mce-gutenberg.php';
require_once MCE_PLUGIN_DIR . 'includes/class-mce-editor.php';
require_once MCE_PLUGIN_DIR . 'includes/class-mce-updater.php';

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
		MCE_Vendor::instance();
		MCE_Gutenberg::instance();
		MCE_Editor::instance();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		/*
		 * L'updater NON va limitato a wp-admin.
		 *
		 * Gli aggiornamenti automatici girano dentro wp-cron.php (e, per chi
		 * lo usa, dentro WP-CLI): in entrambi i contesti is_admin() vale
		 * false. WP_Automatic_Updater::run() chiama per prima cosa
		 * wp_update_plugins(), che RISCRIVE il transient "update_plugins" e
		 * quindi fa scattare il filtro pre_set_site_transient_update_plugins.
		 * Se in quel momento l'updater non è registrato, la voce di
		 * aggiornamento iniettata durante una precedente visita in bacheca
		 * viene sovrascritta e sparisce; subito dopo l'auto-updater legge il
		 * transient, non trova nulla per questo plugin e non aggiorna niente,
		 * silenziosamente. Risultato: il plugin appare "da aggiornare" in
		 * bacheca ma l'auto-update non parte mai.
		 *
		 * Registrare sempre l'updater non ha costo sul front-end: tutti i
		 * filtri agganciati sono inerti finché WordPress non aggiorna il
		 * transient degli aggiornamenti o non avvia un upgrade, quindi
		 * nessuna chiamata di rete durante le visite normali al sito.
		 */
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			MCE_Updater::instance();
		}

		// Solo in wp-admin: il link "Impostazioni" nella lista plugin.
		if ( is_admin() ) {
			add_filter( 'plugin_action_links_' . plugin_basename( MCE_PLUGIN_FILE ), array( $this, 'add_plugin_action_links' ) );
		}

		register_activation_hook( MCE_PLUGIN_FILE, array( $this, 'on_activate' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Aggiunge il link "Impostazioni" / "Settings" accanto all'azione di disattivazione
	 * nella schermata Elenco Plugin di WordPress.
	 */
	public function add_plugin_action_links( array $links ): array {
		$locale     = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		$is_italian = ( 0 === strpos( strtolower( $locale ), 'it' ) );
		$label      = $is_italian ? __( 'Impostazioni', 'modern-classic-editor' ) : __( 'Settings', 'modern-classic-editor' );

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . MCE_Settings::PAGE_SLUG ) ),
			esc_html( $label )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	public function on_activate(): void {
		$defaults = MCE_Settings::get_defaults();
		if ( false === get_option( MCE_Settings::OPTION_KEY ) ) {
			add_option( MCE_Settings::OPTION_KEY, $defaults );
		}
		$settings = MCE_Settings::get();
		if ( ! empty( $settings['auto_check_tinymce_updates'] ) && ! wp_next_scheduled( MCE_Vendor::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', MCE_Vendor::CRON_HOOK );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'modern-classic-editor', false, dirname( plugin_basename( MCE_PLUGIN_FILE ) ) . '/languages' );
	}
}

Modern_Classic_Editor::instance();
