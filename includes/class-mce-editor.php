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
	 * Ritorna l'ultima versione nota o attiva da usare su CDN per garantire
	 * il puntamento al pacchetto più recente disponibile per la major scelta.
	 */
	private function get_cdn_version(): string {
		$major = $this->current_major();
		$known = get_option( 'mce_tinymce_latest_known_version_' . $major, array() );
		if ( ! empty( $known['version'] ) ) {
			return (string) $known['version'];
		}
		$local = MCE_Vendor::instance()->get_active_local_version( $major );
		if ( ! empty( $local['version'] ) ) {
			return (string) $local['version'];
		}
		return $major;
	}

	/**
	 * jsDelivr: CDN pubblico, gratuito, senza limiti di caricamenti/account,
	 * a differenza di cdn.tiny.cloud che richiede una API key per uso
	 * continuativo. L'URL usa l'ultima versione nota di TinyMCE per la major
	 * selezionata nelle impostazioni, garantendo di caricare sempre la release più recente.
	 */
	private function cdn_base_script_url(): string {
		return 'https://cdn.jsdelivr.net/npm/tinymce@' . $this->get_cdn_version() . '/tinymce.min.js';
	}

	/**
	 * Root della stessa versione CDN, senza il file finale, usata come
	 * base_url per la risoluzione di temi/skin/icone/plugin lato JS.
	 */
	private function cdn_base_url(): string {
		return 'https://cdn.jsdelivr.net/npm/tinymce@' . $this->get_cdn_version();
	}

	/**
	 * Major TinyMCE attualmente selezionata nelle impostazioni del plugin
	 * ('7' o '8'), con fallback sicuro su '7' per qualunque valore non
	 * riconosciuto (stessa validazione applicata in MCE_Vendor).
	 */
	private function current_major(): string {
		$major = (string) MCE_Settings::get_option( 'tinymce_major' );
		return in_array( $major, array( '7', '8' ), true ) ? $major : '7';
	}

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
		add_filter( 'content_save_pre', array( $this, 'normalize_links_on_save' ), 10 );
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
		$source_url  = $this->cdn_base_script_url();
		$base_url    = $this->cdn_base_url();

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

		$post_id      = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lettura per contesto, nessuna azione eseguita.
		$lang         = $this->get_tinymce_language();
		$language_url = $this->get_language_url( $lang, $use_local );

		wp_localize_script(
			'mce-modern-tinymce-init',
			'mceModernSettings',
			array(
				'darkMode'              => $settings['dark_mode'],
				'toolbarMode'           => $settings['toolbar_mode'],
				'enableMenubar'         => (bool) $settings['enable_menubar'],
				'editorHeight'          => (int) ( $settings['editor_height'] ?? 600 ),
				'toolbarPresets'        => $this->get_toolbar_presets(),
				'oembedProxyUrl'        => rest_url( 'oembed/1.0/proxy' ),
				'restNonce'             => wp_create_nonce( 'wp_rest' ),
				'postId'                => $post_id,
				'embedPreviewPluginUrl' => MCE_PLUGIN_URL . 'assets/js/tinymce-embed-preview.js',
				// base_url indica a TinyMCE da dove caricare dinamicamente
				// temi, skin, icone e plugin: di norma li risolve in modo
				// relativo allo script principale, ma quando serviamo il
				// file locale da uploads conviene essere espliciti.
				'editorBaseUrl'         => untrailingslashit( $base_url ),
				'language'              => $lang,
				'languageUrl'           => $language_url,
			)
		);
	}

	/**
	 * Mappa la lingua utente/sito di WordPress nel codice lingua per TinyMCE.
	 */
	private function get_tinymce_language(): string {
		$locale = get_user_locale();
		if ( empty( $locale ) || 'en_US' === $locale ) {
			return 'en';
		}

		$map = array(
			'it_IT'        => 'it',
			'it_IT_formal' => 'it',
			'es_ES'        => 'es',
			'es_CL'        => 'es',
			'es_MX'        => 'es',
			'es_AR'        => 'es',
			'es_CO'        => 'es',
			'es_PE'        => 'es',
			'es_VE'        => 'es',
			'fr_FR'        => 'fr_FR',
			'fr_BE'        => 'fr_FR',
			'fr_CA'        => 'fr_FR',
			'de_DE'        => 'de',
			'de_DE_formal' => 'de',
			'de_CH'        => 'de',
			'de_AT'        => 'de',
			'pt_BR'        => 'pt_BR',
			'pt_PT'        => 'pt_PT',
			'nl_NL'        => 'nl',
			'nl_BE'        => 'nl',
			'ru_RU'        => 'ru',
			'ja'           => 'ja',
			'zh_CN'        => 'zh_CN',
			'zh_TW'        => 'zh_TW',
		);

		if ( isset( $map[ $locale ] ) ) {
			return $map[ $locale ];
		}

		$short = strtolower( substr( $locale, 0, 2 ) );
		return ! empty( $short ) ? $short : 'en';
	}

	/**
	 * Determina l'URL del pacchetto lingua di TinyMCE se disponibile.
	 */
	private function get_language_url( string $lang, bool $use_local ): string {
		if ( 'en' === $lang ) {
			return '';
		}

		$local_file = MCE_PLUGIN_DIR . 'assets/langs/' . $lang . '.js';
		if ( file_exists( $local_file ) ) {
			return MCE_PLUGIN_URL . 'assets/langs/' . $lang . '.js';
		}

		if ( ! $use_local ) {
			return 'https://cdn.jsdelivr.net/npm/tinymce-i18n@latest/langs7/' . $lang . '.js';
		}

		return '';
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

	/**
	 * Corregge automaticamente i link esterni privi di protocollo al salvataggio
	 * (es. <a href="marcoborla.com"> diventa <a href="https://marcoborla.com">),
	 * lasciando intatti i link relativi che iniziano per / (es. /nomecartella/immagine.jpg),
	 * ancore (#), query (?) o URL con schema già definito (http://, mailto:, tel:, ecc.).
	 */
	public function normalize_links_on_save( string $content ): string {
		if ( empty( $content ) || ( false === strpos( $content, '<a ' ) && false === strpos( $content, '<a	' ) ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			'/(<a\s+[^>]*?href\s*=\s*["\'])([^"\']+)(["\'][^>]*?>)/i',
			static function ( array $matches ): string {
				$prefix = $matches[1];
				$url    = trim( $matches[2] );
				$suffix = $matches[3];

				if ( '' === $url ) {
					return $matches[0];
				}

				// Se ha già un protocollo/schema (es. http:, https:, mailto:, tel:, ecc.) non toccare.
				if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url ) ) {
					return $matches[0];
				}

				// Se è un link relativo (/nomecartella/immagine.jpg), ancora (#), query (?) o path (./, ../), non toccare.
				if ( preg_match( '/^[\/#!.?]/', $url ) ) {
					return $matches[0];
				}

				return $prefix . 'https://' . $url . $suffix;
			},
			$content
		);
	}
}
