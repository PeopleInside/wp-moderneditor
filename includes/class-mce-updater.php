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
 * Aggiornamenti del plugin stesso tramite le GitHub Releases del repository
 * ufficiale, integrati nel meccanismo nativo di WordPress (stessa interfaccia
 * "Aggiornamento disponibile" nella pagina Plugin, stesso bottone "Aggiorna
 * ora", e compatibile con gli auto-update automatici dei plugin se l'utente
 * li ha attivati per questo plugin dalla pagina Plugin di WordPress).
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

	/**
	 * Repository GitHub ufficiale del plugin (stesso indicato nell'header
	 * "Plugin URI" e nel README), nel formato "owner/repo".
	 */
	const GITHUB_REPO = 'PeopleInside/wp-moderneditor';

	const GITHUB_API_URL = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

	/**
	 * Nome della cartella in cui il plugin DEVE risiedere in wp-content/plugins,
	 * usato per riconoscere il pacchetto scaricato e, se necessario,
	 * rinominare la cartella estratta dallo zipball del codice sorgente
	 * (che GitHub nomina sempre "<repo>-<tag o hash>", diverso dal nome
	 * della cartella del plugin) prima che WordPress lo installi.
	 */
	const PLUGIN_SLUG = 'modern-classic-editor';

	/**
	 * Cache transient per non interrogare GitHub ad ogni caricamento di
	 * wp-admin: la pagina Plugin di WordPress, da sola, già controlla gli
	 * aggiornamenti al massimo ogni 12 ore per ciascun plugin (vedi
	 * wp_update_plugins()); manteniamo qui una cache equivalente.
	 */
	const CACHE_KEY = 'mce_github_latest_release';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

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

		// Invalida la cache subito dopo un aggiornamento riuscito di questo
		// plugin, così la pagina Plugin non continua a proporre la stessa
		// versione appena installata finché non scade il TTL naturale.
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );
	}

	/**
	 * Percorso del file principale del plugin nel formato richiesto da
	 * WordPress per il transient "update_plugins"
	 * (relativo a wp-content/plugins/, es. "modern-classic-editor/modern-classic-editor.php").
	 */
	private function plugin_basename(): string {
		return plugin_basename( MCE_PLUGIN_FILE );
	}

	/**
	 * Interroga la API di GitHub per l'ultima release pubblicata, con
	 * cache transient. Restituisce solo i campi che servono, già validati,
	 * o WP_Error in caso di problemi.
	 *
	 * @return array{version: string, package_url: string, html_url: string, body: string}|WP_Error
	 */
	public function get_latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::GITHUB_API_URL,
			array(
				'timeout' => 8,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					// Identifica chiaramente il client verso le API di GitHub,
					// come richiesto dalle loro linee guida per le richieste API.
					'User-Agent' => 'ModernClassicEditor/' . MCE_PLUGIN_VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			// 404 è lo stato normale per un repository senza ancora alcuna
			// release pubblicata: non è un errore da segnalare come tale.
			if ( 404 === $code ) {
				return new WP_Error( 'mce_github_no_release', __( 'Nessuna release pubblicata su GitHub.', 'modern-classic-editor' ) );
			}
			return new WP_Error(
				'mce_github_http_error',
				sprintf(
					/* translators: %d: codice di stato HTTP */
					__( 'GitHub ha risposto con codice %d.', 'modern-classic-editor' ),
					$code
				)
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return new WP_Error( 'mce_github_bad_response', __( 'Risposta di GitHub non valida.', 'modern-classic-editor' ) );
		}

		// Il tag può essere prefissato da "v" (convenzione comune, es. "v1.3.0"):
		// per il confronto con MCE_PLUGIN_VERSION normalizziamo togliendo il prefisso.
		$version = preg_replace( '/^v/i', '', (string) $body['tag_name'] );
		if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
			return new WP_Error( 'mce_github_invalid_version', __( 'Numero di versione della release non valido.', 'modern-classic-editor' ) );
		}

		$package_url = $this->resolve_package_url( $body );
		if ( is_wp_error( $package_url ) ) {
			return $package_url;
		}

		$result = array(
			'version'     => $version,
			'package_url' => $package_url,
			'html_url'    => isset( $body['html_url'] ) ? esc_url_raw( (string) $body['html_url'] ) : 'https://github.com/' . self::GITHUB_REPO . '/releases',
			// changelog testuale (markdown grezzo) mostrato nel popup "Visualizza dettagli";
			// è testo descrittivo, non eseguito: nessun rischio nell'usarlo cosi com'è
			// dentro wp_remote_get -> qui viene solo passato a wp_kses_post in fase di output.
			'body'        => isset( $body['body'] ) ? (string) $body['body'] : '',
		);

		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Determina l'URL del pacchetto da scaricare per una release: preferisce
	 * un asset .zip ufficiale allegato alla release (se il maintainer lo
	 * pubblica, è la scelta più robusta perché può contenere solo i file
	 * necessari, già nella struttura di cartella corretta); in assenza di
	 * un asset compatibile, ricade sullo zipball del codice sorgente del
	 * tag, generato automaticamente da GitHub per ogni release (sempre
	 * disponibile, nessuna azione richiesta al maintainer).
	 *
	 * In entrambi i casi l'URL proviene DIRETTAMENTE dalla risposta delle
	 * API di GitHub per questa release (mai costruito a mano), e viene
	 * verificato che punti a un host GitHub legittimo.
	 *
	 * @param array $release Corpo JSON della risposta "releases/latest".
	 * @return string|WP_Error
	 */
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

		// Fallback: zipball del codice sorgente del tag. Generato da GitHub
		// stesso (non dal maintainer), sempre presente per ogni release/tag.
		if ( ! empty( $release['zipball_url'] ) && $this->is_trusted_github_url( (string) $release['zipball_url'] ) ) {
			return esc_url_raw( (string) $release['zipball_url'] );
		}

		return new WP_Error( 'mce_github_no_package', __( 'Nessun pacchetto scaricabile trovato per questa release.', 'modern-classic-editor' ) );
	}

	/**
	 * Verifica che un URL fornito da GitHub punti effettivamente a un host
	 * GitHub legittimo, in HTTPS. Difesa in profondità: la risposta arriva
	 * già da una richiesta HTTPS verso api.github.com, ma non diamo per
	 * scontato il contenuto di un campo URL prima di usarlo per un download.
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
		return in_array( $host, array( 'github.com', 'api.github.com', 'codeload.github.com', 'objects.githubusercontent.com' ), true );
	}

	/**
	 * Inserisce, se disponibile, le informazioni di aggiornamento nel
	 * transient "update_plugins" che WordPress usa per la pagina Plugin,
	 * gli auto-update e la WP-CLI. Stesso meccanismo usato dai plugin
	 * della directory ufficiale, solo con dati provenienti da GitHub
	 * invece che da wordpress.org.
	 *
	 * @param object|false $transient Valore corrente del transient.
	 * @return object|false
	 */
	public function inject_update_info( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( is_wp_error( $release ) ) {
			return $transient;
		}

		$basename = $this->plugin_basename();

		if ( ! version_compare( $release['version'], MCE_PLUGIN_VERSION, '>' ) ) {
			// Nessun aggiornamento: se per qualche motivo un update era stato
			// segnalato in precedenza (es. rollback manuale), lo rimuoviamo.
			if ( isset( $transient->response[ $basename ] ) ) {
				unset( $transient->response[ $basename ] );
			}
			return $transient;
		}

		$item = new stdClass();
		$item->id          = 'github.com/' . self::GITHUB_REPO;
		$item->slug        = self::PLUGIN_SLUG;
		$item->plugin      = $basename;
		$item->new_version = $release['version'];
		$item->url         = $release['html_url'];
		$item->package     = $release['package_url'];
		$item->tested      = '';
		$item->icons       = array();
		$item->banners     = array();

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $basename ] = $item;

		return $transient;
	}

	/**
	 * Fornisce i dettagli mostrati nel popup "Visualizza dettagli versione X"
	 * della pagina Plugin, quando WordPress li richiede per questo plugin.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object              $args
	 * @return false|object|array
	 */
	public function inject_plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( is_wp_error( $release ) ) {
			return $result;
		}

		$info               = new stdClass();
		$info->name         = 'Modern Classic Editor';
		$info->slug         = self::PLUGIN_SLUG;
		$info->version      = $release['version'];
		$info->author       = '<a href="https://github.com/PeopleInside">PeopleInside</a>';
		$info->homepage     = $release['html_url'];
		$info->download_link = $release['package_url'];
		$info->sections     = array(
			// wp_kses_post: il testo della release (markdown grezzo) è
			// scritto dal maintainer su GitHub, ma trattandosi comunque di
			// contenuto remoto lo passiamo a wp_kses_post prima di mostrarlo
			// nell'interfaccia di amministrazione.
			'description' => wp_kses_post( wpautop( $release['body'] ) ),
		);

		return $info;
	}

	/**
	 * Difesa in profondità sul download del pacchetto: anche se l'URL
	 * proviene dalla nostra stessa risposta GitHub validata, verifichiamo
	 * di nuovo l'host subito prima che WordPress lo scarichi davvero,
	 * nel caso in cui altri filtri "upgrader_pre_download" o un transient
	 * non aggiornato avessero alterato il pacchetto in transito.
	 *
	 * @param false|WP_Error $reply
	 * @param string          $package URL del pacchetto da scaricare.
	 * @param object          $upgrader
	 * @param array           $hook_extra
	 * @return false|WP_Error
	 */
	public function verify_package_host( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$is_ours = $upgrader instanceof Plugin_Upgrader
			&& ! empty( $upgrader->skin->plugin )
			&& $this->plugin_basename() === $upgrader->skin->plugin;

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

	/**
	 * Quando il pacchetto installato è lo zipball del codice sorgente
	 * (fallback senza asset .zip dedicato), GitHub lo confeziona con una
	 * cartella radice nel formato "wp-moderneditor-<hash o tag>", diversa
	 * dal nome cartella richiesto dal plugin ("modern-classic-editor").
	 * WordPress, di norma, sa già adattare il nome cartella al momento
	 * dell'installazione di un aggiornamento (confronta con il plugin
	 * esistente tramite il suo "destination"), ma rinominiamo qui in modo
	 * esplicito per evitare ambiguità se il pacchetto venisse installato
	 * come nuovo plugin invece che come aggiornamento di uno esistente.
	 *
	 * @param string|WP_Error $source        Percorso della cartella estratta.
	 * @param string          $remote_source Percorso del file scaricato.
	 * @param WP_Upgrader     $upgrader
	 * @param array           $hook_extra
	 * @return string|WP_Error
	 */
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

		$source_dirname = basename( untrailingslashit( $source ) );
		if ( self::PLUGIN_SLUG === $source_dirname ) {
			return $source; // Già nel nome corretto (caso dell'asset .zip ufficiale).
		}

		$desired_source = trailingslashit( dirname( untrailingslashit( $source ) ) ) . self::PLUGIN_SLUG . '/';

		if ( $wp_filesystem->exists( $desired_source ) ) {
			$wp_filesystem->delete( $desired_source, true );
		}

		$moved = $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired_source ) );
		if ( ! $moved ) {
			return new WP_Error( 'mce_rename_failed', __( 'Impossibile rinominare la cartella del pacchetto scaricato.', 'modern-classic-editor' ) );
		}

		return $desired_source;
	}

	/**
	 * Svuota la cache delle release subito dopo un aggiornamento riuscito
	 * di QUESTO plugin, così la pagina Plugin non mostra più la versione
	 * appena installata come "disponibile" per il resto del TTL della cache.
	 *
	 * @param WP_Upgrader $upgrader
	 * @param array       $hook_extra
	 */
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
