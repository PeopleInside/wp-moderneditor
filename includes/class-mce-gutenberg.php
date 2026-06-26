<?php
/**
 * Gestisce la disattivazione dell'editor a blocchi (Gutenberg)
 * in base alle impostazioni del plugin, ripristinando l'editor classico.
 *
 * @package ModernClassicEditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCE_Gutenberg {

	private static ?MCE_Gutenberg $instance = null;

	public static function instance(): MCE_Gutenberg {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Filtro "moderno" usato da WP per decidere se usare il block editor.
		add_filter( 'use_block_editor_for_post_type', array( $this, 'maybe_disable_block_editor' ), 100, 2 );

		// Disattiva anche i widget a blocchi, se Gutenberg è disattivato globalmente,
		// per coerenza con plugin come "Classic Editor".
		add_filter( 'use_widgets_block_editor', array( $this, 'maybe_disable_widgets_block_editor' ), 100 );

		// Rimuove gli stili/script di Gutenberg dal frontend quando non servono,
		// solo per i post type in cui l'editor classico è forzato (micro-ottimizzazione).
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_dequeue_block_assets' ), 20 );
	}

	private function is_post_type_disabled( string $post_type ): bool {
		$settings = MCE_Settings::get();

		if ( empty( $settings['disable_gutenberg'] ) ) {
			return false;
		}

		return in_array( $post_type, (array) $settings['disabled_post_types'], true );
	}

	/**
	 * @param bool   $use_block_editor
	 * @param string $post_type
	 */
	public function maybe_disable_block_editor( bool $use_block_editor, string $post_type ): bool {
		if ( $this->is_post_type_disabled( $post_type ) ) {
			return false;
		}
		return $use_block_editor;
	}

	public function maybe_disable_widgets_block_editor( bool $use_widgets_block_editor ): bool {
		$settings = MCE_Settings::get();
		if ( ! empty( $settings['disable_gutenberg'] ) ) {
			return false;
		}
		return $use_widgets_block_editor;
	}

	public function maybe_dequeue_block_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_type = get_post_type();
		if ( $post_type && $this->is_post_type_disabled( $post_type ) ) {
			wp_dequeue_style( 'wp-block-library' );
			wp_dequeue_style( 'wp-block-library-theme' );
			wp_dequeue_style( 'global-styles' );
		}
	}
}
