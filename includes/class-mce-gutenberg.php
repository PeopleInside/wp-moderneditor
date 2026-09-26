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
 * Gestisce la disattivazione e la convivenza dell'editor a blocchi (Gutenberg)
 * e dell'editor classico (Modern Classic Editor), consentendo la scelta
 * predefinita e il cambio flessibile per articolo/pagina.
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
		// Filtri usati da WordPress per decidere se usare il block editor (per tipo o per singolo post)
		add_filter( 'use_block_editor_for_post_type', array( $this, 'maybe_disable_block_editor' ), 100, 2 );
		add_filter( 'use_block_editor_for_post', array( $this, 'maybe_disable_block_editor_for_post' ), 100, 2 );

		// Disattiva i widget a blocchi, se Gutenberg è disattivato globalmente
		add_filter( 'use_widgets_block_editor', array( $this, 'maybe_disable_widgets_block_editor' ), 100 );

		// Rimuove gli stili/script di Gutenberg dal frontend quando non servono
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_dequeue_block_assets' ), 20 );

		// Azioni di riga nella tabella articoli e pagine ("Modifica (Classico)", "Modifica (Blocchi)")
		add_filter( 'post_row_actions', array( $this, 'add_edit_row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_edit_row_actions' ), 10, 2 );

		// Voci sottomenu nel menu laterale di WordPress ("Aggiungi (Classico)", "Aggiungi (Blocchi)")
		add_action( 'admin_menu', array( $this, 'add_admin_submenus' ) );

		// Voci nella barra di amministrazione in alto ("+ Nuovo")
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_items' ), 80 );

		// Metabox nella barra laterale dell'editor per cambiare editor con 1 clic
		add_action( 'add_meta_boxes', array( $this, 'register_switch_editor_meta_box' ) );

		// Salva quale editor è stato usato per il post per riaprirlo coerentemente
		add_action( 'save_post', array( $this, 'save_post_editor_meta' ) );
	}

	private function is_post_type_handled( string $post_type ): bool {
		$settings = MCE_Settings::get();

		if ( empty( $settings['disable_gutenberg'] ) ) {
			return false;
		}

		return in_array( $post_type, (array) $settings['disabled_post_types'], true );
	}

	/**
	 * Determina se usare il block editor per il post type indicato.
	 *
	 * @param bool   $use_block_editor
	 * @param string $post_type
	 */
	public function maybe_disable_block_editor( bool $use_block_editor, string $post_type ): bool {
		if ( ! $this->is_post_type_handled( $post_type ) ) {
			return $use_block_editor;
		}

		$settings       = MCE_Settings::get();
		$allow_switch   = ! empty( $settings['allow_user_editor_switch'] );
		$default_editor = $settings['default_editor'] ?? 'classic';

		// Controllo parametro esplicito da URL
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['classic-editor'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['block-editor'] ) ) {
			return true;
		}

		// Se l'utente sta creando un nuovo post (post-new.php)
		global $pagenow;
		if ( 'post-new.php' === $pagenow ) {
			return 'block' === $default_editor;
		}

		// Se stiamo aprendo un post esistente tramite ID
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				return $this->should_use_block_editor_for_post_object( $post, $default_editor, $allow_switch );
			}
		}

		return 'block' === $default_editor;
	}

	/**
	 * Determina se usare il block editor per uno specifico post.
	 *
	 * @param bool     $use_block_editor
	 * @param \WP_Post $post
	 */
	public function maybe_disable_block_editor_for_post( bool $use_block_editor, $post ): bool {
		if ( ! ( $post instanceof WP_Post ) ) {
			return $use_block_editor;
		}

		if ( ! $this->is_post_type_handled( $post->post_type ) ) {
			return $use_block_editor;
		}

		$settings       = MCE_Settings::get();
		$allow_switch   = ! empty( $settings['allow_user_editor_switch'] );
		$default_editor = $settings['default_editor'] ?? 'classic';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['classic-editor'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['block-editor'] ) ) {
			return true;
		}

		return $this->should_use_block_editor_for_post_object( $post, $default_editor, $allow_switch );
	}

	/**
	 * Logica centrale di decisione per un singolo post.
	 */
	private function should_use_block_editor_for_post_object( WP_Post $post, string $default_editor, bool $allow_switch ): bool {
		if ( $allow_switch ) {
			$which = get_post_meta( $post->ID, '_mce_which_editor', true );
			if ( 'classic' === $which ) {
				return false;
			}
			if ( 'block' === $which ) {
				return true;
			}
		}

		// Se il post contiene già blocchi salvati in precedenza,
		// lasciamo Gutenberg attivo per non corrompere i commenti dei blocchi
		if ( function_exists( 'has_blocks' ) && has_blocks( $post ) ) {
			return true;
		}

		return 'block' === $default_editor;
	}

	public function maybe_disable_widgets_block_editor( bool $use_widgets_block_editor ): bool {
		$settings = MCE_Settings::get();
		if ( ! empty( $settings['disable_gutenberg'] ) && 'classic' === ( $settings['default_editor'] ?? 'classic' ) ) {
			return false;
		}
		return $use_widgets_block_editor;
	}

	/**
	 * Aggiunge le azioni di riga nella tabella articoli e pagine:
	 * "Modifica (Classico)" e "Modifica (Blocchi)".
	 *
	 * @param array    $actions
	 * @param \WP_Post $post
	 */
	public function add_edit_row_actions( array $actions, WP_Post $post ): array {
		$settings = MCE_Settings::get();
		if ( empty( $settings['allow_user_editor_switch'] ) || empty( $settings['disable_gutenberg'] ) ) {
			return $actions;
		}

		if ( ! $this->is_post_type_handled( $post->post_type ) ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$edit_link = get_edit_post_link( $post->ID, 'raw' );
		if ( ! $edit_link ) {
			return $actions;
		}

		$classic_link = add_query_arg( 'classic-editor', '', remove_query_arg( 'block-editor', $edit_link ) );
		$block_link   = add_query_arg( 'block-editor', '', remove_query_arg( 'classic-editor', $edit_link ) );

		$new_actions = array();
		foreach ( $actions as $action_key => $action_html ) {
			if ( 'edit' === $action_key ) {
				$new_actions['edit_classic'] = sprintf(
					'<a href="%s" aria-label="%s">%s</a>',
					esc_url( $classic_link ),
					esc_attr( sprintf( __( 'Modifica &#8220;%s&#8221; nell\'editor classico', 'modern-classic-editor' ), get_the_title( $post->ID ) ) ),
					esc_html__( 'Modifica (Classico)', 'modern-classic-editor' )
				);
				$new_actions['edit_block'] = sprintf(
					'<a href="%s" aria-label="%s">%s</a>',
					esc_url( $block_link ),
					esc_attr( sprintf( __( 'Modifica &#8220;%s&#8221; nell\'editor a blocchi', 'modern-classic-editor' ), get_the_title( $post->ID ) ) ),
					esc_html__( 'Modifica (Blocchi)', 'modern-classic-editor' )
				);
			} else {
				$new_actions[ $action_key ] = $action_html;
			}
		}

		return $new_actions;
	}

	/**
	 * Aggiunge sottomenu nel menu di amministrazione laterale:
	 * "Aggiungi (Classico)" e "Aggiungi (Blocchi)".
	 */
	public function add_admin_submenus(): void {
		$settings = MCE_Settings::get();
		if ( empty( $settings['allow_user_editor_switch'] ) || empty( $settings['disable_gutenberg'] ) ) {
			return;
		}

		foreach ( (array) $settings['disabled_post_types'] as $pt ) {
			$post_type_obj = get_post_type_object( $pt );
			if ( ! $post_type_obj || ! current_user_can( $post_type_obj->cap->create_posts ) ) {
				continue;
			}

			$parent_slug = ( 'post' === $pt ) ? 'edit.php' : "edit.php?post_type={$pt}";
			$classic_url = ( 'post' === $pt ) ? 'post-new.php?classic-editor' : "post-new.php?post_type={$pt}&classic-editor";
			$block_url   = ( 'post' === $pt ) ? 'post-new.php?block-editor' : "post-new.php?post_type={$pt}&block-editor";

			add_submenu_page(
				$parent_slug,
				sprintf( __( 'Aggiungi nuovo (%s - Classico)', 'modern-classic-editor' ), $post_type_obj->labels->singular_name ),
				__( 'Aggiungi (Classico)', 'modern-classic-editor' ),
				$post_type_obj->cap->create_posts,
				$classic_url
			);

			add_submenu_page(
				$parent_slug,
				sprintf( __( 'Aggiungi nuovo (%s - Blocchi)', 'modern-classic-editor' ), $post_type_obj->labels->singular_name ),
				__( 'Aggiungi (Blocchi)', 'modern-classic-editor' ),
				$post_type_obj->cap->create_posts,
				$block_url
			);
		}
	}

	/**
	 * Aggiunge voci dedicate nella barra admin in alto ("+ Nuovo").
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar
	 */
	public function add_admin_bar_items( WP_Admin_Bar $wp_admin_bar ): void {
		$settings = MCE_Settings::get();
		if ( empty( $settings['allow_user_editor_switch'] ) || empty( $settings['disable_gutenberg'] ) ) {
			return;
		}

		if ( ! is_admin_bar_showing() ) {
			return;
		}

		foreach ( (array) $settings['disabled_post_types'] as $pt ) {
			$post_type_obj = get_post_type_object( $pt );
			if ( ! $post_type_obj || ! current_user_can( $post_type_obj->cap->create_posts ) ) {
				continue;
			}

			$parent_id = 'new-' . $pt;
			$node      = $wp_admin_bar->get_node( $parent_id );
			if ( ! $node ) {
				continue;
			}

			$classic_url = ( 'post' === $pt ) ? admin_url( 'post-new.php?classic-editor' ) : admin_url( "post-new.php?post_type={$pt}&classic-editor" );
			$block_url   = ( 'post' === $pt ) ? admin_url( 'post-new.php?block-editor' ) : admin_url( "post-new.php?post_type={$pt}&block-editor" );

			$wp_admin_bar->add_node(
				array(
					'id'     => "new-{$pt}-classic",
					'title'  => sprintf( __( '%s (Classico)', 'modern-classic-editor' ), $post_type_obj->labels->singular_name ),
					'parent' => $parent_id,
					'href'   => $classic_url,
				)
			);

			$wp_admin_bar->add_node(
				array(
					'id'     => "new-{$pt}-block",
					'title'  => sprintf( __( '%s (Blocchi)', 'modern-classic-editor' ), $post_type_obj->labels->singular_name ),
					'parent' => $parent_id,
					'href'   => $block_url,
				)
			);
		}
	}

	/**
	 * Registra il metabox laterale nella schermata dell'editor classico
	 * per mostrare l'editor attivo e consentire il passaggio a Gutenberg.
	 */
	public function register_switch_editor_meta_box(): void {
		$settings = MCE_Settings::get();
		if ( empty( $settings['allow_user_editor_switch'] ) || empty( $settings['disable_gutenberg'] ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, (array) $settings['disabled_post_types'], true ) ) {
			return;
		}

		add_meta_box(
			'mce_switch_editor',
			__( 'Editor in uso', 'modern-classic-editor' ),
			array( $this, 'render_switch_editor_meta_box' ),
			$screen->post_type,
			'side',
			'low'
		);
	}

	/**
	 * Render del metabox con switch rapido.
	 *
	 * @param \WP_Post $post
	 */
	public function render_switch_editor_meta_box( $post ): void {
		$is_block = false;
		if ( function_exists( 'use_block_editor_for_post' ) ) {
			$is_block = use_block_editor_for_post( $post );
		}

		if ( $is_block ) {
			$switch_url   = add_query_arg( 'classic-editor', '', remove_query_arg( 'block-editor', get_edit_post_link( $post->ID, 'raw' ) ) );
			$switch_label = __( 'Passa all\'editor classico (Modern Classic Editor)', 'modern-classic-editor' );
			$current_name = __( 'Editor a blocchi (Gutenberg)', 'modern-classic-editor' );
			$info_text    = __( 'Puoi passare all\'editor classico Modern Classic Editor per questo articolo.', 'modern-classic-editor' );
			$confirm_msg  = __( 'Assicurati di aver salvato le modifiche prima di cambiare editor. Vuoi passare all\'editor classico?', 'modern-classic-editor' );
			$icon_class   = 'dashicons-block-default';
		} else {
			$switch_url   = add_query_arg( 'block-editor', '', remove_query_arg( 'classic-editor', get_edit_post_link( $post->ID, 'raw' ) ) );
			$switch_label = __( 'Passa all\'editor a blocchi (Gutenberg)', 'modern-classic-editor' );
			$current_name = __( 'Modern Classic Editor', 'modern-classic-editor' );
			$info_text    = __( 'Puoi passare all\'editor a blocchi Gutenberg per questo articolo.', 'modern-classic-editor' );
			$confirm_msg  = __( 'Assicurati di aver salvato le modifiche prima di cambiare editor. Vuoi passare all\'editor a blocchi?', 'modern-classic-editor' );
			$icon_class   = 'dashicons-edit';
		}
		?>
		<div class="mce-switch-editor-panel" style="font-size:13px;">
			<p style="margin: 0 0 8px 0;">
				<strong><?php esc_html_e( 'Stai usando:', 'modern-classic-editor' ); ?></strong>
				<span class="dashicons <?php echo esc_attr( $icon_class ); ?>" style="font-size:16px;vertical-align:middle;margin-right:2px;"></span>
				<?php echo esc_html( $current_name ); ?>
			</p>
			<p style="margin: 0 0 10px 0; color:#64748b; font-size:12px;">
				<?php echo esc_html( $info_text ); ?>
			</p>
			<p style="margin: 0;">
				<a href="<?php echo esc_url( $switch_url ); ?>" class="button button-secondary" onclick="return confirm('<?php echo esc_js( $confirm_msg ); ?>');">
					<?php echo esc_html( $switch_label ); ?>
				</a>
			</p>
			<?php if ( ! $is_block ) : ?>
				<input type="hidden" name="_mce_is_classic_editor" value="1" />
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Salva nei post meta quale editor è stato usato.
	 *
	 * @param int $post_id
	 */
	public function save_post_editor_meta( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! $this->is_post_type_handled( $post->post_type ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['_mce_is_classic_editor'] ) ) {
			update_post_meta( $post_id, '_mce_which_editor', 'classic' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['classic-editor'] ) ) {
			update_post_meta( $post_id, '_mce_which_editor', 'classic' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['block-editor'] ) ) {
			update_post_meta( $post_id, '_mce_which_editor', 'block' );
		} elseif ( function_exists( 'has_blocks' ) && has_blocks( $post ) ) {
			update_post_meta( $post_id, '_mce_which_editor', 'block' );
		}
	}

	public function maybe_dequeue_block_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_type = get_post_type();
		if ( ! $post_type || ! $this->is_post_type_handled( $post_type ) ) {
			return;
		}

		$post = get_queried_object();
		if ( $post instanceof WP_Post && function_exists( 'has_blocks' ) && has_blocks( $post ) ) {
			return;
		}

		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'wp-block-library-theme' );
		wp_dequeue_style( 'global-styles' );
	}
}
