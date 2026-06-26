<?php
/**
 * Gestisce la versione "locale" (offline) di TinyMCE: la versione
 * bundlata nello zip del plugin, l'eventuale versione più recente
 * scaricata dall'utente in wp-content/uploads, il controllo di nuove
 * versioni su npm/jsDelivr e il download on-demand.
 *
 * Note legali: TinyMCE è distribuito sotto licenza GPLv2+ (GNU General
 * Public License versione 2 o successiva). Il bundle incluso in questo
 * plugin e quelli scaricabili da qui provengono dal pacchetto ufficiale
 * "tinymce" pubblicato da Tiny Technologies su npm/jsDelivr, senza
 * modifiche al codice dell'editor. La licenza GPL viene dichiarata
 * tramite l'opzione license_key: 'gpl' nell'inizializzazione JS
 * (vedi assets/js/editor-init.js) e i file di licenza/note originali
 * sono conservati nella cartella della versione bundlata.
 *
 * @package ModernClassicEditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCE_Vendor {

	private static ?MCE_Vendor $instance = null;

	const CRON_HOOK     = 'mce_check_tinymce_update';
	const AJAX_DOWNLOAD = 'mce_download_tinymce';
	const AJAX_CHECK    = 'mce_check_tinymce_version';

	/**
	 * Versione bundlata direttamente nello zip del plugin (sempre
	 * disponibile, anche offline al 100%, senza alcuna richiesta di rete).
	 */
	const BUNDLED_VERSION = '7.9.3';

	/**
	 * npm e jsDelivr servono gli stessi file: usiamo jsDelivr come CDN
	 * "di lettura" sia per il caricamento (modalità CDN) sia come fonte
	 * per il download della versione offline (modalità locale), e l'API
	 * pubblica di npm solo per interrogare l'elenco versioni disponibili.
	 */
	const NPM_REGISTRY_URL = 'https://registry.npmjs.org/tinymce';
	const JSDELIVR_BASE     = 'https://cdn.jsdelivr.net/npm/tinymce@';

	/**
	 * File minificati che compongono il bundle minimo necessario al
	 * plugin (stessa lista usata da scripts/vendor-tinymce.sh). Path
	 * relativi alla root di una versione di TinyMCE.
	 */
	const REQUIRED_FILES = array(
		'tinymce.min.js',
		'models/dom/model.min.js',
		'themes/silver/theme.min.js',
		'icons/default/icons.min.js',
		'skins/ui/oxide/skin.min.css',
		'skins/ui/oxide/content.min.css',
		'skins/ui/oxide-dark/skin.min.css',
		'skins/ui/oxide-dark/content.min.css',
		'skins/content/default/content.min.css',
		'skins/content/dark/content.min.css',
		'plugins/advlist/plugin.min.js',
		'plugins/autolink/plugin.min.js',
		'plugins/lists/plugin.min.js',
		'plugins/link/plugin.min.js',
		'plugins/image/plugin.min.js',
		'plugins/charmap/plugin.min.js',
		'plugins/preview/plugin.min.js',
		'plugins/searchreplace/plugin.min.js',
		'plugins/visualblocks/plugin.min.js',
		'plugins/code/plugin.min.js',
		'plugins/fullscreen/plugin.min.js',
		'plugins/insertdatetime/plugin.min.js',
		'plugins/media/plugin.min.js',
		'plugins/table/plugin.min.js',
		'plugins/wordcount/plugin.min.js',
		'plugins/emoticons/plugin.min.js',
		'plugins/emoticons/js/emojis.min.js',
	);

	public static function instance(): MCE_Vendor {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_' . self::AJAX_DOWNLOAD, array( $this, 'ajax_download_version' ) );
		add_action( 'wp_ajax_' . self::AJAX_CHECK, array( $this, 'ajax_check_latest_version' ) );

		add_action( self::CRON_HOOK, array( $this, 'cron_check_for_update' ) );
		add_action( 'update_option_' . MCE_Settings::OPTION_KEY, array( $this, 'sync_cron_schedule' ), 10, 2 );

		register_deactivation_hook( MCE_PLUGIN_FILE, array( $this, 'clear_cron' ) );
	}

	/**
	 * Cartella in uploads dove vengono salvate le versioni di TinyMCE
	 * scaricate dall'utente (fuori dalla cartella del plugin, così
	 * sopravvivono agli aggiornamenti/reinstallazioni del plugin stesso).
	 */
	public function get_uploads_dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'modern-classic-editor/tinymce/';
	}

	public function get_uploads_url(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . 'modern-classic-editor/tinymce/';
	}

	/**
	 * Percorso del bundle incluso direttamente nello zip del plugin.
	 */
	private function get_bundled_dir(): string {
		return MCE_PLUGIN_DIR . 'assets/vendor/tinymce/' . self::BUNDLED_VERSION . '/';
	}

	/**
	 * Versione locale attualmente disponibile per l'uso (la più recente
	 * tra quella bundlata nel plugin e quella eventualmente scaricata
	 * dall'utente in uploads), con percorso/URL di base.
	 *
	 * @return array{version: string, dir: string, url: string, source: string}
	 */
	public function get_active_local_version(): array {
		$downloaded = $this->get_downloaded_version();

		if ( $downloaded && version_compare( $downloaded, self::BUNDLED_VERSION, '>' ) ) {
			return array(
				'version' => $downloaded,
				'dir'     => $this->get_uploads_dir() . $downloaded . '/',
				'url'     => $this->get_uploads_url() . $downloaded . '/',
				'source'  => 'downloaded',
			);
		}

		return array(
			'version' => self::BUNDLED_VERSION,
			'dir'     => $this->get_bundled_dir(),
			'url'     => MCE_PLUGIN_URL . 'assets/vendor/tinymce/' . self::BUNDLED_VERSION . '/',
			'source'  => 'bundled',
		);
	}

	/**
	 * Legge la versione scaricata dall'utente, se presente e completa
	 * (verifica la presenza del file core, non solo della cartella).
	 */
	public function get_downloaded_version(): ?string {
		$marker = $this->get_uploads_dir() . 'current-version.json';
		if ( ! file_exists( $marker ) ) {
			return null;
		}

		$data = json_decode( (string) file_get_contents( $marker ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			return null;
		}

		$version  = (string) $data['version'];
		$core_file = $this->get_uploads_dir() . $version . '/tinymce.min.js';

		return file_exists( $core_file ) ? $version : null;
	}

	/**
	 * Verifica se per la versione indicata sono presenti tutti i file
	 * richiesti, sia nel bundle del plugin sia in una versione scaricata.
	 */
	public function is_version_complete( string $dir ): bool {
		foreach ( self::REQUIRED_FILES as $relative_path ) {
			if ( ! file_exists( trailingslashit( $dir ) . $relative_path ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Interroga il registro npm per l'elenco delle versioni 7.x
	 * disponibili e restituisce la più recente. npm e jsDelivr
	 * pubblicano gli stessi pacchetti, quindi qualunque versione
	 * trovata qui è scaricabile da jsDelivr.
	 *
	 * Resta sulla major 7 (quella per cui il plugin è scritto):
	 * TinyMCE 8 introduce breaking changes (license key obbligatoria
	 * in un formato diverso, struttura DOM dei toolbar button cambiata)
	 * che richiederebbero un adeguamento del codice JS del plugin.
	 *
	 * @return array{version: string, checked_at: string}|WP_Error
	 */
	public function fetch_latest_version() {
		$response = wp_remote_get(
			self::NPM_REGISTRY_URL,
			array(
				'timeout' => 8,
				// Il formato "abbreviated" di npm restituisce solo nome,
				// dist-tags e l'elenco versioni (senza changelog/readme per
				// ogni release), riducendo la risposta di circa il 50%.
				'headers' => array( 'Accept' => 'application/vnd.npm.install-v1+json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'mce_npm_http_error', sprintf(
				/* translators: %d: codice di stato HTTP */
				__( 'Il registro npm ha risposto con codice %d.', 'modern-classic-editor' ),
				$code
			) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['versions'] ) || ! is_array( $body['versions'] ) ) {
			return new WP_Error( 'mce_npm_bad_response', __( 'Risposta del registro npm non valida.', 'modern-classic-editor' ) );
		}

		$candidates = array();
		foreach ( array_keys( $body['versions'] ) as $version ) {
			// Solo versioni stabili della major 7 (niente alpha/beta/rc, niente major 8+).
			if ( preg_match( '/^7\.\d+\.\d+$/', $version ) ) {
				$candidates[] = $version;
			}
		}

		if ( empty( $candidates ) ) {
			return new WP_Error( 'mce_npm_no_candidates', __( 'Nessuna versione 7.x trovata su npm.', 'modern-classic-editor' ) );
		}

		usort( $candidates, 'version_compare' );
		$latest = end( $candidates );

		$result = array(
			'version'    => $latest,
			'checked_at' => current_time( 'mysql' ),
		);

		update_option( 'mce_tinymce_latest_known_version', $result, false );

		return $result;
	}

	/**
	 * Scarica e installa in uploads una versione di TinyMCE da jsDelivr,
	 * prendendo solo i file minificati elencati in REQUIRED_FILES.
	 *
	 * @return true|WP_Error
	 */
	public function download_version( string $version ) {
		if ( ! preg_match( '/^7\.\d+\.\d+$/', $version ) ) {
			return new WP_Error( 'mce_invalid_version', __( 'Numero di versione non valido.', 'modern-classic-editor' ) );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$target_dir = $this->get_uploads_dir() . $version . '/';
		wp_mkdir_p( $target_dir );

		// WP_Filesystem() può richiedere credenziali FTP in alcuni setup
		// (permessi di scrittura diretta non disponibili a PHP). Se non è
		// utilizzabile in modalità "direct", ricadiamo su rename() nativo:
		// in un'installazione WordPress normale (la grande maggioranza),
		// PHP ha già i permessi di scrittura su wp-content/uploads.
		global $wp_filesystem;
		$use_wp_filesystem = false;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			if ( WP_Filesystem() && isset( $wp_filesystem ) && 'direct' === $wp_filesystem->method ) {
				$use_wp_filesystem = true;
			}
		} elseif ( 'direct' === $wp_filesystem->method ) {
			$use_wp_filesystem = true;
		}

		foreach ( self::REQUIRED_FILES as $relative_path ) {
			$remote_url  = self::JSDELIVR_BASE . $version . '/' . $relative_path;
			$destination = $target_dir . $relative_path;

			$tmp_file = download_url( $remote_url, 15 );
			if ( is_wp_error( $tmp_file ) ) {
				return new WP_Error(
					'mce_download_failed',
					sprintf(
						/* translators: %s: percorso del file che non è stato scaricato */
						__( 'Download non riuscito per il file: %s', 'modern-classic-editor' ),
						$relative_path
					)
				);
			}

			wp_mkdir_p( dirname( $destination ) );
			$moved = $use_wp_filesystem
				? $wp_filesystem->move( $tmp_file, $destination, true )
				: @rename( $tmp_file, $destination ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename

			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}

			if ( ! $moved ) {
				return new WP_Error(
					'mce_move_failed',
					sprintf(
						/* translators: %s: percorso del file che non è stato salvato */
						__( 'Impossibile salvare il file scaricato: %s', 'modern-classic-editor' ),
						$relative_path
					)
				);
			}
		}

		// Licenza GPLv2+ e note di terze parti, scaricate anch'esse da jsDelivr
		// così come distribuite da Tiny, per restare in regola con i termini GPL.
		foreach ( array( 'notices.txt' ) as $extra_file ) {
			$tmp_file = download_url( self::JSDELIVR_BASE . $version . '/' . $extra_file, 10 );
			if ( ! is_wp_error( $tmp_file ) ) {
				$extra_destination = $target_dir . $extra_file;
				if ( $use_wp_filesystem ) {
					$wp_filesystem->move( $tmp_file, $extra_destination, true );
				} else {
					@rename( $tmp_file, $extra_destination ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				}
				if ( file_exists( $tmp_file ) ) {
					wp_delete_file( $tmp_file );
				}
			}
		}

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$target_dir . 'LICENSE.txt',
			"TinyMCE è distribuito sotto licenza GNU General Public License v2.0 o successiva (GPLv2+).\n" .
			"Testo completo: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html\n" .
			"Copyright (c) Tiny Technologies, Inc.\n" .
			"Scaricato da jsDelivr (cdn.jsdelivr.net/npm/tinymce), pacchetto npm ufficiale, versione {$version}, senza modifiche al codice.\n"
		);

		if ( ! $this->is_version_complete( $target_dir ) ) {
			return new WP_Error( 'mce_incomplete_download', __( 'Il download è incompleto: alcuni file richiesti risultano mancanti.', 'modern-classic-editor' ) );
		}

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->get_uploads_dir() . 'current-version.json',
			wp_json_encode(
				array(
					'version'       => $version,
					'downloaded_at' => current_time( 'mysql' ),
				)
			)
		);

		return true;
	}

	/**
	 * Endpoint AJAX: download manuale dichiarato dall'amministratore
	 * dalla pagina impostazioni (bottone "Aggiorna ora").
	 */
	public function ajax_download_version(): void {
		check_ajax_referer( 'mce_vendor_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'modern-classic-editor' ) ), 403 );
		}

		$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		if ( '' === $version ) {
			wp_send_json_error( array( 'message' => __( 'Versione non specificata.', 'modern-classic-editor' ) ), 400 );
		}

		$result = $this->download_version( $version );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: numero di versione scaricato */
					__( 'TinyMCE %s scaricato e installato correttamente.', 'modern-classic-editor' ),
					$version
				),
				'version' => $version,
			)
		);
	}

	/**
	 * Endpoint AJAX: controllo manuale dell'ultima versione disponibile
	 * (bottone "Controlla aggiornamenti").
	 */
	public function ajax_check_latest_version(): void {
		check_ajax_referer( 'mce_vendor_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'modern-classic-editor' ) ), 403 );
		}

		$result = $this->fetch_latest_version();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		$active = $this->get_active_local_version();

		wp_send_json_success(
			array(
				'latest_version' => $result['version'],
				'checked_at'     => $result['checked_at'],
				'has_update'     => version_compare( $result['version'], $active['version'], '>' ),
			)
		);
	}

	/**
	 * Controllo automatico via wp-cron, eseguito solo se l'utente ha
	 * attivato esplicitamente l'opzione corrispondente nelle impostazioni
	 * (nessuna richiesta di rete in background senza consenso esplicito).
	 * Se trova una versione più recente, la scarica e installa da solo,
	 * così l'utente la trova già pronta al prossimo accesso.
	 */
	public function cron_check_for_update(): void {
		$settings = MCE_Settings::get();
		if ( empty( $settings['auto_check_tinymce_updates'] ) ) {
			return;
		}

		$result = $this->fetch_latest_version();
		if ( is_wp_error( $result ) ) {
			return;
		}

		$active = $this->get_active_local_version();
		if ( version_compare( $result['version'], $active['version'], '>' ) ) {
			$this->download_version( $result['version'] );
		}
	}

	/**
	 * Attiva/disattiva il controllo periodico via wp-cron in base
	 * all'opzione "auto_check_tinymce_updates" salvata dall'utente.
	 *
	 * @param array $old_value Valore precedente dell'opzione mce_settings.
	 * @param array $new_value Nuovo valore salvato.
	 */
	public function sync_cron_schedule( $old_value, $new_value ): void {
		$enabled = ! empty( $new_value['auto_check_tinymce_updates'] );

		if ( $enabled && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
		} elseif ( ! $enabled ) {
			$this->clear_cron();
		}
	}

	public function clear_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}
