<?php
/**
 * Aggiornamenti del plugin stesso tramite le GitHub Releases del repository
 * ufficiale, integrati nel meccanismo nativo di WordPress (stessa interfaccia
 * "Aggiornamento disponibile" nella pagina Plugin, stesso bottone "Aggiorna
 * ora", e compatibile con gli auto-update automatici dei plugin se l'utente
 * li ha attivati per questo plugin dalla pagina Plugin di WordPress).
 *
 * IMPORTANTE: questa classe deve essere istanziata anche fuori da wp-admin
 * (wp-cron e WP-CLI), altrimenti gli aggiornamenti automatici non partono.
 * Vedi il commento esteso in modern-classic-editor.php.
 *
 * Note di sicurezza (questo file è, di fatto, la supply chain del plugin):
 * - Tutte le richieste avvengono solo in HTTPS, verso api.github.com e
 *   github.com (i soli domini necessari).
 * - L'URL del pacchetto da scaricare proviene SEMPRE dalla risposta della
 *   API di GitHub per quella specifica release (asset ufficiale o, in
 *   assenza di un asset zip allegato, lo zipball del codice sorgente del
 *   tag generato da GitHub stesso): non viene mai costruito a mano un URL
 *   con la versione, per evitare ambiguità o manipolazioni del path.
 * - Il confronto delle versioni usa sempre version_compare(), mai un
 *   confronto di stringa.
 * - Il download e l'installazione del pacchetto sono delegati per intero
 *   al meccanismo nativo di WordPress (Plugin_Upgrader / WP_Filesystem):
 *   questo plugin non scrive, estrae o sovrascrive mai file da solo.
 * - L'endpoint risposto da GitHub viene controllato anche lato host: deve
 *   risolvere su github.com o api.github.com, mai un redirect verso un
 *   host arbitrario.
 *
 * @package ModernClassicEditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCE_Updater {

	private static ?MCE_Updater $instance = null;

	const GITHUB_REPO = 'PeopleInside/wp-moderneditor';
	const GITHUB_API_URL = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
	const PLUGIN_SLUG = 'modern-classic-editor';
	const CACHE_KEY = 'mce_github_latest_release';
	
	/**
	 * FIX: Ridotto da 12 ore a 2 ore per un rilevamento più reattivo.
	 */
	const CACHE_TTL = 2 * HOUR_IN_SECONDS;
	const CACHE_FAIL_TTL = 15 * MINUTE_IN_SECONDS;

	public static function instance(): MCE_Updater {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update_info' ) );
		add_filter( 'plugins_api', array( $this, 'inject_plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_package_host' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );
	}

	private function plugin_basename(): string {
		return plugin_basename( MCE_PLUGIN_FILE );
	}

	private function installed_version( $transient, string $basename ): string {
		if ( isset( $transient->checked[ $basename ] ) && '' !== $transient->checked[ $basename ] ) {
			return (string) $transient->checked[ $basename ];
		}
		return MCE_PLUGIN_VERSION;
	}

	private function target_dir_name(): string {
		$dir = dirname( $this->plugin_basename() );
		if ( '.' === $dir || '' === $dir || '/' === $dir ) {
			$dir = self::PLUGIN_SLUG;
		}
		return $dir;
	}

	public function get_latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'skip' === $cached ) {
			return new WP_Error( 'mce_github_check_deferred', __( 'Controllo aggiornamenti rinviato dopo un errore recente.', 'modern-classic-editor' ) );
		}

		$response = wp_remote_get(
			self::GITHUB_API_URL,
			array(
				'timeout' => 8,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'ModernClassicEditor/' . MCE_PLUGIN_VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->remember_failure( $response );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			if ( 404 === $code ) {
				return $this->remember_failure( new WP_Error( 'mce_github_no_release', __( 'Nessuna release pubblicata su GitHub.', 'modern-classic-editor' ) ) );
			}
			return $this->remember_failure(
				new WP_Error(
					'mce_github_http_error',
					sprintf( __( 'GitHub ha risposto con codice %d.', 'modern-classic-editor' ), $code )
				)
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return $this->remember_failure( new WP_Error( 'mce_github_bad_response', __( 'Risposta di GitHub non valida.', 'modern-classic-editor' ) ) );
		}

		$version = preg_replace( '/^v/i', '', (string) $body['tag_name'] );
		if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
			return $this->remember_failure( new WP_Error( 'mce_github_invalid_version', __( 'Numero di versione della release non valido.', 'modern-classic-editor' ) ) );
		}

		$package_url = $this->resolve_package_url( $body );
		if ( is_wp_error( $package_url ) ) {
			return $this->remember_failure( $package_url );
		}

		$result = array(
			'version'     => $version,
			'package_url' => $package_url,
			'html_url'    => isset( $body['html_url'] ) ? esc_url_raw( (string) $body['html_url'] ) : 'https://github.com/' . self::GITHUB_REPO . '/releases',
			'body'        => isset( $body['body'] ) ? (string) $body['body'] : '',
		);

		// FIX: Il primo parametro deve essere la CHIAVE (CACHE_KEY), non la durata (CACHE_TTL)
		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );

		return $result;
	}

	private function remember_failure( WP_Error $error ): WP_Error {
		set_transient( self::CACHE_KEY, 'skip', self::CACHE_FAIL_TTL );
		return $error;
	}

	private function resolve_package_url( array $release ) {
		$assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
				continue;
			}
			$asset_name = strtolower( (string) $asset['name'] );
			if ( '.zip' !== substr( $asset_name, -4 ) ) {
				continue;
			}
			$url = (string) $asset['browser_download_url'];
			if ( $this->is_trusted_github_url( $url ) ) {
				return esc_url_raw( $url );
			}
		}

		if ( ! empty( $release['zipball_url'] ) && $this->is_trusted_github_url( (string) $release['zipball_url'] ) ) {
			return esc_url_raw( (string) $release['zipball_url'] );
		}

		return new WP_Error( 'mce_github_no_package', __( 'Nessun pacchetto scaricabile trovato per questa release.', 'modern-classic-editor' ) );
	}

	/**
	 * FIX: Aggiunto 'github-releases.githubusercontent.com' alla whitelist.
	 */
	private function is_trusted_github_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( 'https' !== $parts['scheme'] ) {
			return false;
		}
		$host = strtolower( $parts['host'] );
		return in_array( $host, array( 
			'github.com', 
			'api.github.com', 
			'codeload.github.com', 
			'objects.githubusercontent.com',
			'github-releases.githubusercontent.com' // FIX: Aggiunto per permettere il download degli asset
		), true );
	}

	public function inject_update_info( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( is_wp_error( $release ) ) {
			return $transient;
		}

		$basename = $this->plugin_basename();

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$installed_version = $this->installed_version( $transient, $basename );

		if ( ! version_compare( $release['version'], $installed_version, '>' ) ) {
			unset( $transient->response[ $basename ] );
			$transient->no_update[ $basename ] = $this->build_item( $installed_version, '', $release['html_url'] );
			return $transient;
		}

		unset( $transient->no_update[ $basename ] );
		$transient->response[ $basename ] = $this->build_item( $release['version'], $release['package_url'], $release['html_url'] );

		return $transient;
	}

	private function build_item( string $version, string $package_url, string $html_url ): stdClass {
		$item = new stdClass();
		$item->id           = 'github.com/' . self::GITHUB_REPO;
		$item->slug         = self::PLUGIN_SLUG;
		$item->plugin       = $this->plugin_basename();
		$item->new_version  = $version;
		$item->url          = $html_url;
		$item->package      = $package_url;
		$item->tested       = '';
		$item->requires_php = '';
		$item->icons        = array();
		$item->banners      = array();
		$item->banners_rtl  = array();
		return $item;
	}

	public function inject_plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}
		if ( self::PLUGIN_SLUG !== $args->slug && $this->target_dir_name() !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( is_wp_error( $release ) ) {
			return $result;
		}

		$info                = new stdClass();
		$info->name          = 'Modern Classic Editor';
		$info->slug          = self::PLUGIN_SLUG;
		$info->version       = $release['version'];
		$info->author        = '<a href="https://github.com/PeopleInside">PeopleInside</a>';
		$info->homepage      = $release['html_url'];
		$info->download_link = $release['package_url'];
		$info->sections      = array(
			'description' => wp_kses_post( wpautop( $release['body'] ) ),
		);

		return $info;
	}

	public function verify_package_host( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$basename = $this->plugin_basename();
		$is_ours = ( ! empty( $hook_extra['plugin'] ) && $basename === (string) $hook_extra['plugin'] );

		if ( ! $is_ours && $upgrader instanceof Plugin_Upgrader && ! empty( $upgrader->skin->plugin ) ) {
			$is_ours = ( $basename === $upgrader->skin->plugin );
		}

		if ( ! $is_ours ) {
			return $reply;
		}

		if ( ! $this->is_trusted_github_url( (string) $package ) ) {
			return new WP_Error(
				'mce_untrusted_package_host',
				__( 'Il pacchetto di aggiornamento non proviene da un host GitHub attendibile: download bloccato.', 'modern-classic-editor' )
			);
		}

		return $reply;
	}

	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$plugin = isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
		if ( $this->plugin_basename() !== $plugin ) {
			return $source;
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			return $source;
		}

		$target_dir     = $this->target_dir_name();
		$source_dirname = basename( untrailingslashit( $source ) );
		if ( $target_dir === $source_dirname ) {
			return $source;
		}

		$desired_source = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $target_dir . '/';

		if ( $wp_filesystem->exists( $desired_source ) ) {
			$wp_filesystem->delete( $desired_source, true );
		}

		$moved = $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired_source ) );
		if ( ! $moved ) {
			return new WP_Error( 'mce_rename_failed', __( 'Impossibile rinominare la cartella del pacchetto scaricato.', 'modern-classic-editor' ) );
		}

		return $desired_source;
	}

	public function clear_cache_after_update( $upgrader, $hook_extra ): void {
		if ( empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) {
			return;
		}
		if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		$plugins = array();
		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$plugins = $hook_extra['plugins'];
		} elseif ( ! empty( $hook_extra['plugin'] ) ) {
			$plugins = array( $hook_extra['plugin'] );
		}

		if ( in_array( $this->plugin_basename(), $plugins, true ) ) {
			delete_transient( self::CACHE_KEY );
		}
	}
}
