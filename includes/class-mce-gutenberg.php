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
		if ( ! $this->is_post_type_disabled( $post_type ) ) {
			return $use_block_editor;
		}

		// Se stiamo aprendo un post/pagina esistente che contiene già
		// blocchi salvati, lasciamo Gutenberg attivo per quel singolo
		// contenuto: forzare l'editor classico mostrerebbe i commenti
		// HTML dei blocchi (<!-- wp:paragraph -->...) come testo grezzo
		// nell'editor, generando contenuti rotti al primo salvataggio.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lettura per contesto, nessuna azione eseguita.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post && function_exists( 'has_blocks' ) && has_blocks( $post ) ) {
				return $use_block_editor;
			}
		}

		return false;
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
		if ( ! $post_type || ! $this->is_post_type_disabled( $post_type ) ) {
			return;
		}

		// Sicurezza: se il contenuto contiene blocchi Gutenberg già salvati
		// (anche se l'editor a blocchi è ora disattivato per questo post type),
		// il CSS dei blocchi deve restare caricato, altrimenti il markup a
		// blocchi perde stili, allineamenti e colonne e la pagina si rompe
		// visivamente. Disattivare l'editor non riconverte i contenuti
		// esistenti: il markup a blocchi resta nel post finché non viene
		// modificato manualmente.
		$post = get_queried_object();
		if ( $post instanceof WP_Post && function_exists( 'has_blocks' ) && has_blocks( $post ) ) {
			return;
		}

		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'wp-block-library-theme' );
		wp_dequeue_style( 'global-styles' );
	}
}
