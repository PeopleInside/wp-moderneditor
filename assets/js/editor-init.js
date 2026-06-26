/**
 * Inizializzazione di TinyMCE 7 (caricato da CDN) in sostituzione
 * dell'editor classico bundlato in WordPress.
 *
 * Si aggancia alle textarea che WordPress avrebbe normalmente trasformato
 * con la propria versione di TinyMCE (di solito #content, o l'id passato
 * a wp_editor()), e le inizializza con la configurazione moderna.
 */
( function () {
	'use strict';

	if ( typeof window.tinymce === 'undefined' ) {
		// Il CDN non si è caricato (rete bloccata, CDN down, ecc.):
		// meglio lasciare la textarea semplice piuttosto che rompere la pagina.
		return;
	}

	var settings = window.mceModernSettings || {};
	var toolbarPresets = settings.toolbarPresets || {};
	var toolbar = toolbarPresets[ settings.toolbarMode ] || toolbarPresets.extended;

	/**
	 * Determina skin e content_css in base alla preferenza salvata
	 * ('system' | 'light' | 'dark'), con supporto al cambio live
	 * se l'utente cambia tema del sistema operativo mentre la pagina è aperta.
	 */
	function resolveTheme() {
		var prefersDark = window.matchMedia &&
			window.matchMedia( '(prefers-color-scheme: dark)' ).matches;

		var isDark;
		if ( settings.darkMode === 'dark' ) {
			isDark = true;
		} else if ( settings.darkMode === 'light' ) {
			isDark = false;
		} else {
			isDark = !! prefersDark;
		}

		return {
			skin: isDark ? 'oxide-dark' : 'oxide',
			content_css: isDark ? 'dark' : 'default',
		};
	}

	/**
	 * Individua tutte le textarea che WordPress normalmente inizializzerebbe
	 * come editor TinyMCE. Sono identificate dalla classe "wp-editor-area"
	 * che WordPress applica sempre, indipendentemente dal contenuto/tema.
	 */
	function getEditorTargets() {
		var nodes = document.querySelectorAll( 'textarea.wp-editor-area' );
		return Array.prototype.slice.call( nodes );
	}

	/**
	 * Conserva la configurazione di inizializzazione di ciascun editor,
	 * per poterlo ricreare correttamente se il tema di sistema cambia
	 * (TinyMCE 6/7 non espone più editor.settings).
	 */
	var initialConfigs = {};

	function initEditor( textarea ) {
		var theme = resolveTheme();

		var config = {
			license_key: 'gpl',
			target: textarea,
			height: 400,
			menubar: settings.enableMenubar ? 'edit view insert format tools table' : false,
			toolbar: toolbar,
			toolbar_mode: 'wrap',
			skin: theme.skin,
			content_css: theme.content_css,
			content_style:
				'.wp-embed-preview-block { margin: 1em 0; border: 1px dashed currentColor; ' +
				'opacity: 0.85; border-radius: 4px; padding: 4px; overflow: hidden; } ' +
				'.wp-embed-preview-block iframe { max-width: 100%; }',
			plugins: [
				'advlist', 'autolink', 'lists', 'link', 'image', 'charmap',
				'preview', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
				'insertdatetime', 'media', 'table', 'wordcount', 'emoticons',
				'wpembedpreview',
			],
			external_plugins: settings.embedPreviewPluginUrl ? {
				wpembedpreview: settings.embedPreviewPluginUrl,
			} : {},
			branding: false,
			promotion: false,
			relative_urls: false,
			convert_urls: false,
			// Mantiene il contenuto sincronizzato con la textarea originale,
			// così il salvataggio del form di WordPress continua a funzionare normalmente.
			setup: function ( editor ) {
				editor.on( 'change keyup undo redo', function () {
					editor.save();
				} );

				// Mantiene window.wpActiveEditor sincronizzato con l'editor a fuoco,
				// così il pulsante nativo "Aggiungi media" di WordPress (media-upload.js)
				// continua a inserire il contenuto nell'editor corretto.
				editor.on( 'focus', function () {
					window.wpActiveEditor = editor.id;
				} );
			},
		};

		if ( textarea.id ) {
			initialConfigs[ textarea.id ] = config;
		}

		tinymce.init( config );
	}

	function initAll() {
		getEditorTargets().forEach( function ( textarea ) {
			// Evita doppie inizializzazioni se lo script viene eseguito più volte.
			if ( textarea.dataset.mceModernInit === '1' ) {
				return;
			}
			textarea.dataset.mceModernInit = '1';
			initEditor( textarea );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}

	/**
	 * Alcuni plugin (es. SiteOrigin Page Builder) creano le proprie
	 * textarea.wp-editor-area dinamicamente, quando l'utente apre un
	 * dialog di modifica widget — quindi dopo che la scansione iniziale
	 * è già passata. Un MutationObserver, con un piccolo debounce per
	 * evitare scansioni ripetute durante manipolazioni massive del DOM
	 * (es. drag&drop di righe/widget), copre anche questi casi.
	 */
	var rescanTimer = null;
	function scheduleRescan() {
		window.clearTimeout( rescanTimer );
		rescanTimer = window.setTimeout( initAll, 250 );
	}

	if ( 'MutationObserver' in window ) {
		var observer = new MutationObserver( function ( mutations ) {
			var hasNewNodes = mutations.some( function ( m ) {
				return m.addedNodes && m.addedNodes.length > 0;
			} );
			if ( hasNewNodes ) {
				scheduleRescan();
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	// Aggiorna live il tema se l'utente cambia preferenza di sistema
	// e l'impostazione del plugin è "system". Per farlo ricreiamo l'editor
	// con la configurazione originale salvata in fase di inizializzazione,
	// dato che in TinyMCE 6/7 l'oggetto "settings" non è più esposto.
	if ( settings.darkMode === 'system' && window.matchMedia ) {
		window.matchMedia( '(prefers-color-scheme: dark)' ).addEventListener( 'change', function () {
			getEditorTargets().forEach( function ( textarea ) {
				var editor = tinymce.get( textarea.id );
				if ( ! editor || ! initialConfigs[ textarea.id ] ) {
					return;
				}
				var content = editor.getContent();
				var theme = resolveTheme();
				var config = Object.assign( {}, initialConfigs[ textarea.id ], {
					target: textarea,
					skin: theme.skin,
					content_css: theme.content_css,
				} );
				editor.remove();
				tinymce.init( config );
				textarea.value = content;
			} );
		} );
	}
} )();
