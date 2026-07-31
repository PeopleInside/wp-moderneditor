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
		add_action( 'admin_notices', array( $this, 'maybe_show_update_notice' ) );
		add_action( 'wp_ajax_mce_dismiss_update_notice', array( $this, 'ajax_dismiss_update_notice' ) );
	}

	/**
	 * Valori di default delle opzioni.
	 */
	public static function get_defaults(): array {
		return array(
			'disable_gutenberg'          => true,  // Di default disattivato per articoli e pagine.
			'disabled_post_types'        => array( 'post', 'page' ), // Articoli e pagine abilitati all'editor classico di default.
			'dark_mode'                  => 'system', // 'system' | 'light' | 'dark'
			'toolbar_mode'               => 'extended', // 'standard' | 'extended' | 'full'
			'enable_menubar'             => true,
			'editor_source'              => 'local',  // 'cdn' | 'local' (offline, predefinito locale)
			'tinymce_major'              => '8',  // '7' | '8' (predefinito TinyMCE 8)
			'auto_check_tinymce_updates' => true,  // Controllo periodico via wp-cron attivo di default.
			'editor_height'              => 600,   // Altezza in pixel dell'area editor.
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

		$tinymce_major = isset( $input['tinymce_major'] ) ? sanitize_key( $input['tinymce_major'] ) : $defaults['tinymce_major'];
		$output['tinymce_major'] = in_array( $tinymce_major, MCE_Vendor::SUPPORTED_MAJORS, true ) ? $tinymce_major : $defaults['tinymce_major'];

		$output['auto_check_tinymce_updates'] = ! empty( $input['auto_check_tinymce_updates'] );

		$editor_height = isset( $input['editor_height'] ) ? absint( $input['editor_height'] ) : $defaults['editor_height'];
		$output['editor_height'] = ( $editor_height >= 100 && $editor_height <= 2000 ) ? $editor_height : $defaults['editor_height'];

		if ( 'local' === $output['editor_source'] ) {
			$vendor = MCE_Vendor::instance();
			$major  = $output['tinymce_major'];
			if ( null === $vendor->get_downloaded_version( $major ) ) {
				$latest = $vendor->fetch_latest_version( $major );
				if ( is_wp_error( $latest ) ) {
					add_settings_error(
						self::OPTION_KEY,
						'mce_download_failed',
						sprintf(
							/* translators: %s: messaggio di errore */
							__( 'Impossibile controllare l\'ultima versione per il download automatico: %s. Verrà usato il bundle locale predefinito.', 'modern-classic-editor' ),
							$latest->get_error_message()
						),
						'warning'
					);
				} elseif ( ! empty( $latest['version'] ) ) {
					$download = $vendor->download_version( $latest['version'], $major );
					if ( is_wp_error( $download ) ) {
						add_settings_error(
							self::OPTION_KEY,
							'mce_download_failed',
							sprintf(
								/* translators: %s: messaggio di errore */
								__( 'Download automatico dell\'editor fallito: %s. Verrà usato il bundle locale predefinito.', 'modern-classic-editor' ),
								$download->get_error_message()
							),
							'warning'
						);
					}
				}
			}
		}

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

		$vendor  = MCE_Vendor::instance();
		$settings = self::get();
		$active  = $vendor->get_active_local_version();
		$downloaded = $vendor->get_downloaded_version();

		wp_localize_script(
			'mce-admin-vendor',
			'mceVendorSettings',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'mce_vendor_action' ),
				'activeVersion'  => $active['version'],
				'activeSource'   => $active['source'],
				'editorSource'   => $settings['editor_source'],
				'hasDownloaded'  => ( 'none' !== $active['source'] ),
				// Bottone "Elimina versione locale": mostrato solo quando la
				// sorgente locale o inclusa nel plugin esiste, per permettere
				// la pulizia dello spazio.
				'showDeleteButton' => ( 'none' !== $active['source'] ),
				'lastKnownLatest' => get_option( 'mce_tinymce_latest_known_version_' . $settings['tinymce_major'], array() ),
				'i18n'           => array(
					'checking'       => __( 'Controllo in corso…', 'modern-classic-editor' ),
					'downloading'    => __( 'Download in corso, potrebbe richiedere qualche secondo…', 'modern-classic-editor' ),
					'deleting'       => __( 'Eliminazione in corso…', 'modern-classic-editor' ),
					'upToDate'       => __( 'Stai già usando l\'ultima versione disponibile.', 'modern-classic-editor' ),
					'updateAvailable' => __( 'È disponibile una nuova versione: ', 'modern-classic-editor' ),
					'genericError'   => __( 'Si è verificato un errore. Riprova.', 'modern-classic-editor' ),
					'confirmDelete'  => __( 'Eliminare i file dell\'editor offline? Se confermi, verrà usata la CDN fino al prossimo download manuale.', 'modern-classic-editor' ),
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
			<p><?php esc_html_e( 'Configura l\'editor classico moderno (TinyMCE) e la disattivazione di Gutenberg.', 'modern-classic-editor' ); ?></p>

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

				<h2 class="title"><?php esc_html_e( 'Versione di TinyMCE', 'modern-classic-editor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Major', 'modern-classic-editor' ); ?></th>
						<td>
							<fieldset>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tinymce_major]" value="7" <?php checked( $settings['tinymce_major'], '7' ); ?> />
									<?php esc_html_e( 'TinyMCE 7 (consigliata per compatibilità con configurazioni e plugin di terze parti esistenti)', 'modern-classic-editor' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tinymce_major]" value="8" <?php checked( $settings['tinymce_major'], '8' ); ?> />
									<?php esc_html_e( 'TinyMCE 8 (più recente; verifica gli embed e i contenuti incollati dopo il passaggio, la sanitizzazione interna è più stringente)', 'modern-classic-editor' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Le versioni locali scaricate per ciascuna major restano salvate separatamente: puoi passare da 7 a 8 e viceversa senza perdere i download già effettuati. La licenza GPL (uso gratuito) si applica a entrambe le major.', 'modern-classic-editor' ); ?>
								</p>
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
					<p id="mce-active-status-text">
						<?php
						if ( 'none' === $active_local['source'] ) {
							esc_html_e( 'Nessuna versione attualmente disponibile offline. L\'editor Classic userà automaticamente la CDN.', 'modern-classic-editor' );
						} else {
							printf(
								/* translators: 1: numero di versione, 2: origine (bundlata col plugin / scaricata) */
								esc_html__( 'Versione attualmente disponibile offline: %1$s (%2$s)', 'modern-classic-editor' ),
								'<strong id="mce-active-version">' . esc_html( $active_local['version'] ) . '</strong>',
								'<span id="mce-active-source" data-downloaded-label="' . esc_attr__( 'scaricata', 'modern-classic-editor' ) . '">' . esc_html( 'bundled' === $active_local['source'] ? __( 'incluse nel plugin', 'modern-classic-editor' ) : __( 'scaricata', 'modern-classic-editor' ) ) . '</span>'
							);
						}
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
						<button type="button" class="button button-link-delete" id="mce-delete-local-btn" style="display:none;">
							<?php esc_html_e( 'Elimina versione locale scaricata', 'modern-classic-editor' ); ?>
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
								<?php esc_html_e( 'Attivato di default: controlla regolarmente in background se è disponibile una nuova versione. Puoi comunque controllare e scaricare manualmente con i bottoni qui sopra.', 'modern-classic-editor' ); ?>
							</p>
							<?php
							$last_auto_check = get_option( 'mce_last_auto_check_time', '' );
							$next_scheduled  = wp_next_scheduled( MCE_Vendor::CRON_HOOK );
							if ( ! empty( $settings['auto_check_tinymce_updates'] ) ) {
								if ( ! $next_scheduled || $next_scheduled < time() ) {
									wp_clear_scheduled_hook( MCE_Vendor::CRON_HOOK );
									wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', MCE_Vendor::CRON_HOOK );
									$next_scheduled = wp_next_scheduled( MCE_Vendor::CRON_HOOK );
								}
							}
							$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
							?>
							<div class="mce-auto-check-log" style="margin-top: 12px; padding: 12px 14px; background: #f6f7f7; border-left: 4px solid #2271b1; border-radius: 2px; max-width: 600px;">
								<p style="margin: 0 0 6px 0;">
									<strong><?php esc_html_e( 'Registro dei controlli automatici:', 'modern-classic-editor' ); ?></strong>
								</p>
								<?php if ( ! empty( $settings['auto_check_tinymce_updates'] ) ) : ?>
									<p style="margin: 0 0 4px 0;">
										<?php esc_html_e( 'Ultimo controllo automatico:', 'modern-classic-editor' ); ?>
										<strong>
											<?php
											if ( ! empty( $last_auto_check ) ) {
												$last_ts = is_numeric( $last_auto_check ) ? (int) $last_auto_check : strtotime( $last_auto_check );
												echo esc_html( wp_date( $date_format, $last_ts ) );
											} else {
												esc_html_e( 'Nessun controllo automatico ancora eseguito.', 'modern-classic-editor' );
											}
											?>
										</strong>
									</p>
									<p style="margin: 0;">
										<?php esc_html_e( 'Prossimo controllo programmato:', 'modern-classic-editor' ); ?>
										<strong>
											<?php
											if ( $next_scheduled ) {
												echo esc_html( wp_date( $date_format, $next_scheduled ) );
											} else {
												esc_html_e( 'Non pianificato', 'modern-classic-editor' );
											}
											?>
										</strong>
									</p>
								<?php else : ?>
									<p style="margin: 0;">
										<em><?php esc_html_e( 'Il controllo automatico degli aggiornamenti è disattivato.', 'modern-classic-editor' ); ?></em>
										<?php if ( ! empty( $last_auto_check ) ) : ?>
											<br />
											<?php
											$last_ts = is_numeric( $last_auto_check ) ? (int) $last_auto_check : strtotime( $last_auto_check );
											printf(
												/* translators: %s: data dell'ultimo controllo */
												esc_html__( 'Ultimo controllo eseguito in precedenza: %s', 'modern-classic-editor' ),
												'<strong>' . esc_html( wp_date( $date_format, $last_ts ) ) . '</strong>'
											);
											?>
										<?php endif; ?>
									</p>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Editor TinyMCE moderno', 'modern-classic-editor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mce_editor_height"><?php esc_html_e( 'Altezza dell\'editor (px)', 'modern-classic-editor' ); ?></label></th>
						<td>
							<input
								type="number"
								id="mce_editor_height"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[editor_height]"
								value="<?php echo esc_attr( $settings['editor_height'] ); ?>"
								min="100"
								max="2000"
								step="10"
								class="small-text"
							/> px
							<p class="description">
								<?php esc_html_e( 'Altezza dell\'area di modifica dell\'editor in pixel (predefinito: 600).', 'modern-classic-editor' ); ?>
							</p>
						</td>
					</tr>
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

	/**
	 * Mostra un banner di avviso quando gli auto-aggiornamenti sono disattivati
	 * e viene rilevata la disponibilità di una nuova versione di TinyMCE.
	 * Include la possibilità per l'utente di nascondere il banner fino al
	 * rilascio di una versione successiva.
	 */
	public function maybe_show_update_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get();
		if ( ! empty( $settings['auto_check_tinymce_updates'] ) ) {
			return;
		}

		$major = $settings['tinymce_major'] ?? '8';
		$vendor = MCE_Vendor::instance();
		$active = $vendor->get_active_local_version( $major );
		if ( empty( $active['version'] ) ) {
			return;
		}

		$latest_known = get_option( 'mce_tinymce_latest_known_version_' . $major, array() );
		if ( empty( $latest_known['version'] ) ) {
			$fetched = $vendor->fetch_latest_version( $major );
			if ( ! is_wp_error( $fetched ) && ! empty( $fetched['version'] ) ) {
				$latest_known = $fetched;
			}
		}

		if ( empty( $latest_known['version'] ) || ! version_compare( $latest_known['version'], $active['version'], '>' ) ) {
			return;
		}

		$latest_version = (string) $latest_known['version'];
		$dismissed = get_option( 'mce_dismissed_update_notice_' . $major, '' );
		if ( $dismissed === $latest_version || version_compare( $dismissed, $latest_version, '>=' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible mce-update-notice" data-version="<?php echo esc_attr( $latest_version ); ?>" data-major="<?php echo esc_attr( $major ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mce_dismiss_notice' ) ); ?>">
			<p>
				<strong><?php esc_html_e( 'Modern Classic Editor:', 'modern-classic-editor' ); ?></strong>
				<?php
				printf(
					/* translators: 1: versione attuale, 2: nuova versione */
					esc_html__( 'È disponibile una nuova versione di TinyMCE (%2$s) rispetto a quella in uso (%1$s). Gli aggiornamenti automatici sono disattivati: aggiornare l\'editor permette di correggere bug e importanti problemi di sicurezza.', 'modern-classic-editor' ),
					'<strong>' . esc_html( $active['version'] ) . '</strong>',
					'<strong>' . esc_html( $latest_version ) . '</strong>'
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Vai alle Impostazioni e Aggiorna', 'modern-classic-editor' ); ?>
				</a>
				<button type="button" class="button-link mce-dismiss-notice-btn" style="margin-left: 15px; text-decoration: none;">
					<?php esc_html_e( 'Nascondi fino a nuova versione', 'modern-classic-editor' ); ?>
				</button>
			</p>
			<script>
			document.addEventListener('DOMContentLoaded', function() {
				var notice = document.querySelector('.mce-update-notice');
				if (!notice) return;
				var version = notice.getAttribute('data-version');
				var major = notice.getAttribute('data-major');
				var nonce = notice.getAttribute('data-nonce');
				var dismissNotice = function() {
					notice.style.display = 'none';
					var xhr = new XMLHttpRequest();
					xhr.open('POST', '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', true);
					xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded;');
					xhr.send('action=mce_dismiss_update_notice&version=' + encodeURIComponent(version) + '&major=' + encodeURIComponent(major) + '&_ajax_nonce=' + encodeURIComponent(nonce));
				};
				notice.addEventListener('click', function(e) {
					if (e.target && (e.target.classList.contains('mce-dismiss-notice-btn') || e.target.classList.contains('notice-dismiss') || e.target.closest('.notice-dismiss') || e.target.closest('.mce-dismiss-notice-btn'))) {
						dismissNotice();
					}
				});
			});
			</script>
		</div>
		<?php
	}

	/**
	 * Endpoint AJAX per salvare la disattivazione del banner di aggiornamento
	 * fino al rilascio di una versione ancora successiva.
	 */
	public function ajax_dismiss_update_notice(): void {
		check_ajax_referer( 'mce_dismiss_notice', '_ajax_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'modern-classic-editor' ) ) );
		}
		$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		$major   = isset( $_POST['major'] ) ? sanitize_key( wp_unslash( $_POST['major'] ) ) : '8';
		if ( ! empty( $version ) ) {
			update_option( 'mce_dismissed_update_notice_' . $major, $version, false );
		}
		wp_send_json_success();
	}
}
