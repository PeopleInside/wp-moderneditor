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
 * Gestione impostazioni e pagina di amministrazione.
 *
 * @package ModernClassicEditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCE_Settings {

	const OPTION_KEY  = 'mce_settings';
	const PAGE_SLUG   = 'modern-classic-editor';

	private static ?MCE_Settings $instance = null;

	public static function instance(): MCE_Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Valori di default delle opzioni.
	 */
	public static function get_defaults(): array {
		return array(
			'disable_gutenberg'          => false,  // Opt-in: l'utente deve attivarlo consapevolmente dalle impostazioni.
			'disabled_post_types'        => array(), // Nessun post type forzato all'editor classico finché non scelto esplicitamente.
			'dark_mode'                  => 'system', // 'system' | 'light' | 'dark'
			'toolbar_mode'               => 'extended', // 'standard' | 'extended' | 'full'
			'enable_menubar'             => true,
			'editor_source'              => 'cdn',  // 'cdn' (jsDelivr, sempre aggiornabile) | 'local' (offline, bundlata nel plugin o scaricata)
			'auto_check_tinymce_updates' => false,  // Controllo periodico via wp-cron: disattivato finché l'utente non lo attiva esplicitamente.
		);
	}

	/**
	 * Recupera le impostazioni correnti, fondendo con i default
	 * (utile se in futuro si aggiungono nuove opzioni).
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::get_defaults() );
	}

	public static function get_option( string $key ) {
		$settings = self::get();
		return $settings[ $key ] ?? null;
	}

	public function register_menu(): void {
		add_options_page(
			__( 'Modern Classic Editor', 'modern-classic-editor' ),
			__( 'Modern Classic Editor', 'modern-classic-editor' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'mce_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::get_defaults(),
			)
		);
	}

	/**
	 * Sanifica l'input del form impostazioni.
	 */
	public function sanitize_settings( $input ): array {
		$defaults = self::get_defaults();
		$output   = array();

		$output['disable_gutenberg'] = ! empty( $input['disable_gutenberg'] );

		$allowed_post_types = array_keys( $this->get_available_post_types() );
		$post_types          = isset( $input['disabled_post_types'] ) && is_array( $input['disabled_post_types'] )
			? array_map( 'sanitize_key', $input['disabled_post_types'] )
			: array();
		$output['disabled_post_types'] = array_values( array_intersect( $allowed_post_types, $post_types ) );

		$dark_mode = isset( $input['dark_mode'] ) ? sanitize_key( $input['dark_mode'] ) : $defaults['dark_mode'];
		$output['dark_mode'] = in_array( $dark_mode, array( 'system', 'light', 'dark' ), true ) ? $dark_mode : $defaults['dark_mode'];

		$toolbar_mode = isset( $input['toolbar_mode'] ) ? sanitize_key( $input['toolbar_mode'] ) : $defaults['toolbar_mode'];
		$output['toolbar_mode'] = in_array( $toolbar_mode, array( 'standard', 'extended', 'full' ), true ) ? $toolbar_mode : $defaults['toolbar_mode'];

		$output['enable_menubar'] = ! empty( $input['enable_menubar'] );

		$editor_source = isset( $input['editor_source'] ) ? sanitize_key( $input['editor_source'] ) : $defaults['editor_source'];
		$output['editor_source'] = in_array( $editor_source, array( 'cdn', 'local' ), true ) ? $editor_source : $defaults['editor_source'];

		$output['auto_check_tinymce_updates'] = ! empty( $input['auto_check_tinymce_updates'] );

		return $output;
	}

	/**
	 * Elenco dei post type pubblici che supportano l'editor,
	 * usato sia per la UI di settings che per la sanitizzazione.
	 */
	public function get_available_post_types(): array {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$result     = array();

		foreach ( $post_types as $post_type ) {
			if ( ! post_type_supports( $post_type->name, 'editor' ) ) {
				continue;
			}
			$result[ $post_type->name ] = $post_type->labels->name;
		}

		return $result;
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'mce-admin-settings',
			MCE_PLUGIN_URL . 'assets/css/admin-settings.css',
			array(),
			MCE_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'mce-admin-vendor',
			MCE_PLUGIN_URL . 'assets/js/admin-vendor.js',
			array(),
			MCE_PLUGIN_VERSION,
			true
		);

		$vendor = MCE_Vendor::instance();
		$active = $vendor->get_active_local_version();

		wp_localize_script(
			'mce-admin-vendor',
			'mceVendorSettings',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'mce_vendor_action' ),
				'activeVersion'  => $active['version'],
				'activeSource'   => $active['source'],
				'lastKnownLatest' => get_option( 'mce_tinymce_latest_known_version', array() ),
				'i18n'           => array(
					'checking'       => __( 'Controllo in corso…', 'modern-classic-editor' ),
					'downloading'    => __( 'Download in corso, potrebbe richiedere qualche secondo…', 'modern-classic-editor' ),
					'upToDate'       => __( 'Stai già usando l\'ultima versione disponibile.', 'modern-classic-editor' ),
					'updateAvailable' => __( 'È disponibile una nuova versione: ', 'modern-classic-editor' ),
					'genericError'   => __( 'Si è verificato un errore. Riprova.', 'modern-classic-editor' ),
				),
			)
		);
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = self::get();
		$post_types = $this->get_available_post_types();
		$active_local = MCE_Vendor::instance()->get_active_local_version();
		?>
		<div class="wrap mce-settings-wrap">
			<h1><?php esc_html_e( 'Modern Classic Editor', 'modern-classic-editor' ); ?></h1>
			<p><?php esc_html_e( 'Configura l\'editor classico moderno (TinyMCE 7) e la disattivazione di Gutenberg.', 'modern-classic-editor' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'mce_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Editor a blocchi (Gutenberg)', 'modern-classic-editor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Disattiva Gutenberg', 'modern-classic-editor' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disable_gutenberg]" value="1" <?php checked( $settings['disable_gutenberg'] ); ?> />
								<?php esc_html_e( 'Usa l\'editor classico invece dell\'editor a blocchi per i tipi di contenuto selezionati qui sotto', 'modern-classic-editor' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tipi di contenuto', 'modern-classic-editor' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $post_types as $slug => $label ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input
											type="checkbox"
											name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disabled_post_types][]"
											value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( in_array( $slug, $settings['disabled_post_types'], true ) ); ?>
										/>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Solo i tipi selezionati torneranno all\'editor classico.', 'modern-classic-editor' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Sorgente dell\'editor TinyMCE', 'modern-classic-editor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Da dove caricare TinyMCE', 'modern-classic-editor' ); ?></th>
						<td>
							<fieldset>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[editor_source]" value="cdn" <?php checked( $settings['editor_source'], 'cdn' ); ?> />
									<?php esc_html_e( 'CDN (jsDelivr) — sempre l\'ultima versione disponibile, richiede una connessione esterna funzionante', 'modern-classic-editor' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[editor_source]" value="local" <?php checked( $settings['editor_source'], 'local' ); ?> />
									<?php esc_html_e( 'Locale (offline) — file inclusi nel plugin o scaricati in precedenza, nessuna richiesta esterna durante l\'uso dell\'editor', 'modern-classic-editor' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Con la modalità locale l\'editor funziona anche se il sito non può raggiungere CDN esterni (firewall, ambienti air-gapped, policy di sicurezza restrittive).', 'modern-classic-editor' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
				</table>

				<div id="mce-vendor-status" class="mce-vendor-status">
					<h3><?php esc_html_e( 'Versione locale disponibile', 'modern-classic-editor' ); ?></h3>
					<p>
						<?php
						printf(
							/* translators: 1: numero di versione, 2: origine (bundlata col plugin / scaricata) */
							esc_html__( 'Versione attualmente disponibile offline: %1$s (%2$s)', 'modern-classic-editor' ),
							'<strong id="mce-active-version">' . esc_html( $active_local['version'] ) . '</strong>',
							'<span id="mce-active-source" data-downloaded-label="' . esc_attr__( 'scaricata', 'modern-classic-editor' ) . '">' . esc_html( 'bundled' === $active_local['source'] ? __( 'incluse nel plugin', 'modern-classic-editor' ) : __( 'scaricata', 'modern-classic-editor' ) ) . '</span>'
						);
						?>
					</p>
					<p id="mce-latest-version-info" class="description"></p>
					<p>
						<button type="button" class="button" id="mce-check-update-btn">
							<?php esc_html_e( 'Controlla aggiornamenti', 'modern-classic-editor' ); ?>
						</button>
						<button type="button" class="button button-primary" id="mce-download-update-btn" style="display:none;">
							<?php esc_html_e( 'Scarica e installa l\'ultima versione', 'modern-classic-editor' ); ?>
						</button>
						<span id="mce-vendor-spinner" class="spinner" style="float:none;"></span>
					</p>
					<p id="mce-vendor-message" class="description"></p>
				</div>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Controllo automatico', 'modern-classic-editor' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_check_tinymce_updates]" value="1" <?php checked( $settings['auto_check_tinymce_updates'] ); ?> />
								<?php esc_html_e( 'Controlla automaticamente una volta al giorno se è disponibile una nuova versione e scaricala in background', 'modern-classic-editor' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Disattivato di default: nessuna richiesta esterna viene effettuata senza il tuo consenso esplicito. Anche con questa opzione disattivata puoi sempre controllare e scaricare manualmente con i bottoni qui sopra.', 'modern-classic-editor' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Editor TinyMCE moderno', 'modern-classic-editor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Tema (dark mode)', 'modern-classic-editor' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[dark_mode]">
								<option value="system" <?php selected( $settings['dark_mode'], 'system' ); ?>><?php esc_html_e( 'Segui il sistema operativo', 'modern-classic-editor' ); ?></option>
								<option value="light" <?php selected( $settings['dark_mode'], 'light' ); ?>><?php esc_html_e( 'Chiaro', 'modern-classic-editor' ); ?></option>
								<option value="dark" <?php selected( $settings['dark_mode'], 'dark' ); ?>><?php esc_html_e( 'Scuro', 'modern-classic-editor' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Toolbar', 'modern-classic-editor' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[toolbar_mode]">
								<option value="standard" <?php selected( $settings['toolbar_mode'], 'standard' ); ?>><?php esc_html_e( 'Standard (come WordPress di default)', 'modern-classic-editor' ); ?></option>
								<option value="extended" <?php selected( $settings['toolbar_mode'], 'extended' ); ?>><?php esc_html_e( 'Estesa (font, colori, tabelle, allineamento)', 'modern-classic-editor' ); ?></option>
								<option value="full" <?php selected( $settings['toolbar_mode'], 'full' ); ?>><?php esc_html_e( 'Completa (tutte le funzioni disponibili)', 'modern-classic-editor' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Barra dei menu', 'modern-classic-editor' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_menubar]" value="1" <?php checked( $settings['enable_menubar'] ); ?> />
								<?php esc_html_e( 'Mostra la barra dei menu (File, Modifica, Inserisci, ecc.)', 'modern-classic-editor' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<p class="description">
				<?php esc_html_e( 'TinyMCE è distribuito sotto licenza GPLv2+ (GNU General Public License). In modalità CDN viene caricato da jsDelivr; in modalità locale i file (identici, non modificati) provengono dal pacchetto ufficiale "tinymce" su npm, incluso nel plugin o scaricato da questa pagina. Nessun account o API key è richiesto in entrambi i casi.', 'modern-classic-editor' ); ?>
			</p>
		</div>
		<?php
	}
}
