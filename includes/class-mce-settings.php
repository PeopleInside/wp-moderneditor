<?php
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
			'disable_gutenberg'   => false,        // Opt-in: l'utente deve attivarlo consapevolmente dalle impostazioni.
			'disabled_post_types' => array(),       // Nessun post type forzato all'editor classico finché non scelto esplicitamente.
			'dark_mode'           => 'system',      // 'system' | 'light' | 'dark'
			'toolbar_mode'        => 'extended',    // 'standard' | 'extended' | 'full'
			'enable_menubar'      => true,
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
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = self::get();
		$post_types = $this->get_available_post_types();
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
				<?php esc_html_e( 'TinyMCE viene caricato da CDN (jsDelivr) sotto licenza GPL, senza necessità di account o API key.', 'modern-classic-editor' ); ?>
			</p>
		</div>
		<?php
	}
}
