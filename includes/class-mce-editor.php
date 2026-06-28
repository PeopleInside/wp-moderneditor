<?php

/*
 * This file is part of Modern Classic Editor.
 *
 * Modern Classic Editor is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * Modern Classic Editor is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Modern Classic Editor. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Sostituisce TinyMCE bundlato in WordPress con una versione moderna (v7)
 * caricata da CDN, applicando dark mode e toolbar configurabile.
 *
 * @package ModernClassicEditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCE_Editor {

	private static ?MCE_Editor $instance = null;

	/**
	 * jsDelivr: CDN pubblico, gratuito, senza limiti di caricamenti/account,
	 * a differenza di cdn.tiny.cloud che richiede una API key per uso continuativo.
	 */
	const CDN_BASE = 'https://cdn.jsdelivr.net/npm/tinymce@' . MCE_TINYMCE_CDN_VERSION . '/tinymce.min.js';

	/**
	 * Root della stessa versione CDN, senza il file finale, usata come
	 * base_url per la risoluzione di temi/skin/icone/plugin lato JS.
	 */
	const JSDELIVR_BASE_URL = 'https://cdn.jsdelivr.net/npm/tinymce@' . MCE_TINYMCE_CDN_VERSION;

	public static function instance(): MCE_Editor {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// PROBLEMA REALE: WordPress concatena gli script admin in un'unica
		// richiesta servita da wp-admin/load-scripts.php, un entry point
		// "leggero" che NON carica i plugin (rifà solo wp_default_scripts()
		// da zero). Per questo, modificare ->src nel nostro hook normale non
		// basta: quando 'editor' finisce nel bundle concatenato, viene preso
		// dal file originale comunque. La soluzione corretta è escludere
		// 'editor' e 'wp-tinymce' dalla concatenazione con js_do_concat,
		// così WordPress li stampa come <script src="..."> individuali
		// nella richiesta normale (dove il nostro swap della src ha effetto).
		add_filter( 'js_do_concat', array( $this, 'exclude_legacy_editor_from_concat' ), 10, 2 );

		// Svuota la sorgente degli script core 'editor' (editor.min.js) e
		// 'wp-tinymce' (TinyMCE 4 legacy con jQuery integrato), agendo
		// direttamente sul registro di WP_Scripts mentre viene costruito.
		add_action( 'wp_default_scripts', array( $this, 'neutralize_legacy_editor_scripts' ), 100 );

		// Carica il nostro TinyMCE da CDN + script di inizializzazione, solo dove serve.
		// Priorità alta (100) per essere certi di girare DOPO eventuali enqueue di temi/plugin.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_modern_tinymce' ), 100 );

		add_filter( 'wp_editor_settings', array( $this, 'filter_editor_settings' ), 10, 2 );
	}

	/**
	 * Esclude 'editor' e 'wp-tinymce' dalla concatenazione admin di WP,
	 * così vengono stampati come tag <script> individuali nella richiesta
	 * normale invece di passare per load-scripts.php (che non carica i
	 * plugin e quindi servirebbe sempre il file originale incompatibile).
	 */
	public function exclude_legacy_editor_from_concat( bool $do_concat, string $handle ): bool {
		if ( ! $this->should_load_modern_editor() ) {
			return $do_concat;
		}

		if ( in_array( $handle, array( 'editor', 'wp-tinymce' ), true ) ) {
			return false;
		}

		return $do_concat;
	}

	/**
	 * Sostituisce la src di 'editor' e 'wp-tinymce' con un file JS inerte,
	 * solo nelle pagine dove carichiamo TinyMCE 7. Gli script restano
	 * "registrati" (niente notice per altri plugin che controllano
	 * wp_script_is()), ma non scaricano/eseguono più codice legacy.
	 * 'quicktags' viene lasciato intatto: non dipende dall'API TinyMCE
	 * legacy ed è usato anche da plugin terzi (es. SiteOrigin Page Builder).
	 */
	public function neutralize_legacy_editor_scripts( WP_Scripts $scripts ): void {
		if ( ! $this->should_load_modern_editor() ) {
			return;
		}

		$noop_src = MCE_PLUGIN_URL . 'assets/js/noop.js';

		foreach ( array( 'editor', 'wp-tinymce' ) as $handle ) {
			if ( isset( $scripts->registered[ $handle ] ) ) {
				$scripts->registered[ $handle ]->src  = $noop_src;
				$scripts->registered[ $handle ]->deps = array();
				$scripts->registered[ $handle ]->ver  = MCE_PLUGIN_VERSION;
			}
		}
	}

	/**
	 * Determina se la pagina admin corrente è una pagina di editing
	 * dove ha senso caricare l'editor classico (post.php, post-new.php,
	 * o pagine con wp_editor() come widget testo, ecc.).
	 *
	 * IMPORTANTE: su post.php/post-new.php non basta controllare la
	 * pagina ($pagenow): se il post type corrente usa Gutenberg, qui
	 * deve restituire false. Altrimenti gli script legacy 'editor' e
	 * 'wp-tinymce' verrebbero svuotati anche dentro l'editor a blocchi,
	 * rompendo il blocco nativo "Editor classico" (core/freeform), che
	 * dipende proprio da quegli script per funzionare.
	 */
	private function should_load_modern_editor(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		global $pagenow;
		$editor_pages = array( 'post.php', 'post-new.php', 'widgets.php', 'customize.php' );

		if ( ! in_array( $pagenow, $editor_pages, true ) ) {
			return false;
		}

		if ( in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
			$post_type = $this->get_current_post_type_from_request();
			if ( '' !== $post_type && ! $this->uses_classic_editor( $post_type ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Recupera il post type della schermata corrente di editing,
	 * senza richiedere get_current_screen() (non sempre disponibile
	 * nei hook precoci come wp_default_scripts).
	 */
	private function get_current_post_type_from_request(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lettura per contesto, nessuna azione eseguita.
		if ( isset( $_GET['post'] ) ) {
			$post = get_post( absint( $_GET['post'] ) );
			return $post ? $post->post_type : '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lettura per contesto, nessuna azione eseguita.
		if ( isset( $_GET['post_type'] ) ) {
			return sanitize_key( wp_unslash( $_GET['post_type'] ) );
		}

		global $pagenow;
		if ( 'post-new.php' === $pagenow ) {
			return 'post'; // Default di WordPress quando non è specificato post_type.
		}

		return '';
	}

	/**
	 * Carica TinyMCE (da CDN o da file locali, in base alle impostazioni)
	 * e lo script di inizializzazione del plugin, passando in JS le
	 * impostazioni salvate (dark mode, toolbar, ecc.).
	 */
	public function enqueue_modern_tinymce( string $hook ): void {
		if ( ! $this->should_load_modern_editor() ) {
			return;
		}

		// Carica solo se l'editor classico è effettivamente in uso per questo schermo.
		$screen    = get_current_screen();
		$post_type = $screen && isset( $screen->post_type ) ? $screen->post_type : '';

		if ( $post_type && ! $this->uses_classic_editor( $post_type ) ) {
			return;
		}

		$settings    = MCE_Settings::get();
		$use_local   = 'local' === $settings['editor_source'];
		$vendor      = MCE_Vendor::instance();
		$local_info  = $vendor->get_active_local_version();
		$source_url  = self::CDN_BASE;
		$base_url    = self::JSDELIVR_BASE_URL;

		if ( $use_local ) {
			if ( $vendor->is_version_complete( $local_info['dir'] ) ) {
				$source_url = $local_info['url'] . 'tinymce.min.js';
				$base_url   = $local_info['url'];
			} else {
				// Sicurezza: se la modalità locale è selezionata ma il bundle
				// risultasse incompleto (es. cartella uploads danneggiata),
				// torniamo al CDN piuttosto che rompere l'editor.
				$use_local = false;
			}
		}

		wp_enqueue_script(
			'mce-modern-tinymce',
			$source_url,
			array(),
			$use_local ? $local_info['version'] : MCE_PLUGIN_VERSION,
			false // in head: TinyMCE deve essere disponibile prima dell'init di WP.
		);

		wp_enqueue_script(
			'mce-modern-tinymce-init',
			MCE_PLUGIN_URL . 'assets/js/editor-init.js',
			array( 'mce-modern-tinymce' ),
			MCE_PLUGIN_VERSION,
			true
		);

		wp_enqueue_style(
			'mce-modern-tinymce-admin',
			MCE_PLUGIN_URL . 'assets/css/editor-admin.css',
			array(),
			MCE_PLUGIN_VERSION
		);

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lettura per contesto, nessuna azione eseguita.

		wp_localize_script(
			'mce-modern-tinymce-init',
			'mceModernSettings',
			array(
				'darkMode'       => $settings['dark_mode'],
				'toolbarMode'    => $settings['toolbar_mode'],
				'enableMenubar'  => (bool) $settings['enable_menubar'],
				'toolbarPresets' => $this->get_toolbar_presets(),
				'oembedProxyUrl' => rest_url( 'oembed/1.0/proxy' ),
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'postId'         => $post_id,
				'embedPreviewPluginUrl' => MCE_PLUGIN_URL . 'assets/js/tinymce-embed-preview.js',
				// base_url indica a TinyMCE da dove caricare dinamicamente
				// temi, skin, icone e plugin: di norma li risolve in modo
				// relativo allo script principale, ma quando serviamo il
				// file locale da uploads conviene essere espliciti.
				'editorBaseUrl'  => untrailingslashit( $base_url ),
			)
		);
	}

	/**
	 * Verifica se per il post type indicato è attivo l'editor classico
	 * (perché disattivato Gutenberg da questo plugin, o perché il tipo
	 * di contenuto non supporta comunque l'editor a blocchi).
	 */
	private function uses_classic_editor( string $post_type ): bool {
		if ( ! post_type_supports( $post_type, 'editor' ) ) {
			return false;
		}
		return ! use_block_editor_for_post_type( $post_type );
	}

	/**
	 * Preset di toolbar in stile "TinyMCE Advanced", selezionabili dalle impostazioni.
	 */
	private function get_toolbar_presets(): array {
		return array(
			'standard' => 'undo redo | bold italic | bullist numlist | link unlink | blockquote',
			'extended' => 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link unlink image media table | removeformat | code fullscreen',
			'full'     => 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough subscript superscript | forecolor backcolor | alignleft aligncenter alignright alignjustify lineheight | bullist numlist outdent indent | link unlink image media table charmap emoticons | removeformat | code preview fullscreen | searchreplace visualblocks',
		);
	}

	/**
	 * WordPress, quando genera le impostazioni per wp_editor(), include
	 * tinymce => true di default: lo lasciamo intatto, ma assicuriamoci
	 * che quicktags resti disponibile come fallback (pulsante "Testo").
	 */
	public function filter_editor_settings( array $settings, string $editor_id ): array {
		if ( ! $this->should_load_modern_editor() ) {
			return $settings;
		}
		$settings['quicktags'] = true;
		return $settings;
	}
}
