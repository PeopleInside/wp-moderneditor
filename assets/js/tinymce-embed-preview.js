/**
 * Plugin TinyMCE custom: anteprima live degli embed (YouTube, Vimeo, ecc.)
 * quando l'utente incolla un URL su una riga propria.
 *
 * Sostituisce la funzionalità che in WordPress con TinyMCE 4 era fornita
 * dal plugin nativo "wpview", non disponibile per TinyMCE 7. Il contenuto
 * salvato nel post resta l'URL puro (dentro un blocco riconoscibile),
 * così il rendering finale sul frontend continua a passare da WP_Embed
 * in PHP, esattamente come l'editor classico nativo.
 */
( function () {
	'use strict';

	if ( typeof window.tinymce === 'undefined' ) {
		return;
	}

	var settings = window.mceModernSettings || {};

	// Riconosce URL "nudi" su una riga propria, dentro o fuori da un paragrafo.
	var URL_LINE_REGEX = /^https?:\/\/[^\s<]+$/i;

	// Tag che non devono mai comparire in un embed oEmbed legittimo
	// (player video/social restituiscono al massimo iframe/blockquote/script
	// di supporto): li rimuoviamo per intero, contenuto incluso.
	var DISALLOWED_TAGS = [ 'script', 'style', 'link', 'object', 'embed', 'meta', 'base', 'form' ];

	// Protocolli ammessi per attributi URL-like (href, src, ecc.):
	// blocca javascript:, data:text/html, vbscript: e simili.
	var SAFE_URL_PATTERN = /^(https?:|\/\/|\/|#)/i;
	var URL_LIKE_ATTRS = [ 'href', 'src', 'xlink:href', 'action', 'formaction' ];

	/**
	 * Sanifica un blocco HTML di anteprima oEmbed prima di inserirlo nel DOM
	 * dell'editor: l'HTML arriva da un servizio terzo (tramite il proxy
	 * oEmbed di WordPress) e non può essere considerato attendibile al 100%
	 * (provider oEmbed di terze parti, redirect, risposte malformate).
	 * Costruisce il markup in un documento inerte (non eseguibile, non
	 * agganciato al DOM visibile) tramite DOMParser, poi rimuove script/tag
	 * pericolosi, attributi "on*" e URL con protocollo non sicuro, e infine
	 * mette in sandbox eventuali iframe.
	 */
	function sanitizeEmbedHtml( html ) {
		if ( ! html || typeof DOMParser === 'undefined' ) {
			return '';
		}

		var doc;
		try {
			doc = new DOMParser().parseFromString( html, 'text/html' );
		} catch ( e ) {
			return '';
		}

		DISALLOWED_TAGS.forEach( function ( tagName ) {
			var nodes = doc.querySelectorAll( tagName );
			nodes.forEach( function ( node ) {
				node.parentNode && node.parentNode.removeChild( node );
			} );
		} );

		var allNodes = doc.body ? doc.body.querySelectorAll( '*' ) : [];
		allNodes.forEach( function ( el ) {
			// Rimuove tutti gli attributi "on*" (onload, onerror, onclick, ...).
			Array.prototype.slice.call( el.attributes ).forEach( function ( attr ) {
				var name = attr.name.toLowerCase();

				if ( name.indexOf( 'on' ) === 0 ) {
					el.removeAttribute( attr.name );
					return;
				}

				if ( URL_LIKE_ATTRS.indexOf( name ) !== -1 && attr.value && ! SAFE_URL_PATTERN.test( attr.value.trim() ) ) {
					el.removeAttribute( attr.name );
				}
			} );

			// Gli embed video (YouTube, Vimeo, ecc.) usano <iframe>: lo
			// lasciamo, ma in sandbox, così anche un iframe verso un host
			// non fidato non può fare popup, navigare il top-level o
			// accedere allo stesso origin della pagina di amministrazione.
			if ( 'iframe' === el.tagName.toLowerCase() && ! el.hasAttribute( 'sandbox' ) ) {
				el.setAttribute( 'sandbox', 'allow-scripts allow-same-origin allow-popups allow-presentation' );
			}
		} );

		return doc.body ? doc.body.innerHTML : '';
	}

	/**
	 * Richiede l'anteprima HTML al proxy oEmbed di WordPress.
	 * Ritorna una Promise che risolve con l'HTML, o null se non embeddable.
	 */
	function fetchEmbedHtml( url ) {
		if ( ! settings.oembedProxyUrl ) {
			return Promise.resolve( null );
		}

		var endpoint = settings.oembedProxyUrl +
			'?url=' + encodeURIComponent( url ) +
			( settings.postId ? '&post_id=' + encodeURIComponent( settings.postId ) : '' );

		return fetch( endpoint, {
			headers: { 'X-WP-Nonce': settings.restNonce || '' },
			credentials: 'same-origin',
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					return null;
				}
				return response.json();
			} )
			.then( function ( data ) {
				return data && data.html ? data.html : null;
			} )
			.catch( function () {
				return null;
			} );
	}

	tinymce.PluginManager.add( 'wpembedpreview', function ( editor ) {
		// Evita di richiedere più volte la stessa URL nella stessa sessione di editing.
		var cache = {};

		/**
		 * Cerca, dentro il nodo dato, paragrafi/testi che contengono
		 * solo un URL e li sostituisce con un blocco di anteprima.
		 */
		function scanAndPreview( node ) {
			var walker = document.createTreeWalker( node, NodeFilter.SHOW_TEXT );
			var textNode;
			var matches = [];

			while ( ( textNode = walker.nextNode() ) ) {
				var text = textNode.nodeValue.trim();
				if ( URL_LINE_REGEX.test( text ) ) {
					matches.push( { node: textNode, url: text } );
				}
			}

			matches.forEach( function ( match ) {
				var parent = match.node.parentElement;
				if ( ! parent || parent.dataset.wpEmbedPreview === '1' ) {
					return;
				}
				// Solo se il paragrafo contiene ESCLUSIVAMENTE l'URL (niente altro testo).
				if ( parent.textContent.trim() !== match.url ) {
					return;
				}

				var url = match.url;

				if ( cache[ url ] === false ) {
					return; // Già verificato come non-embeddable.
				}

				if ( cache[ url ] ) {
					renderPreview( parent, url, cache[ url ] );
					return;
				}

				fetchEmbedHtml( url ).then( function ( html ) {
					var safeHtml = html ? sanitizeEmbedHtml( html ) : '';
					cache[ url ] = safeHtml || false;
					if ( safeHtml ) {
						renderPreview( parent, url, safeHtml );
					}
				} );
			} );
		}

		/**
		 * Sostituisce il paragrafo contenente l'URL con un blocco di anteprima
		 * non editabile, mantenendo l'URL originale in un attributo dati:
		 * editor.save() (in editor-init.js) ripristina l'URL puro nella
		 * textarea, così il contenuto salvato resta lo stesso che produrrebbe
		 * l'editor classico nativo.
		 */
		function renderPreview( paragraph, url, html ) {
			if ( ! paragraph || paragraph.dataset.wpEmbedPreview === '1' ) {
				return;
			}

			var wrapper = editor.dom.create(
				'div',
				{
					class: 'wp-embed-preview-block',
					'data-wp-embed-preview': '1',
					'data-wp-embed-url': url,
					contenteditable: 'false',
				},
				html
			);

			paragraph.dataset.wpEmbedPreview = '1';
			paragraph.replaceWith( wrapper );
		}

		// Esegue la scansione dopo modifiche al contenuto (paste, typing con pausa, undo/redo).
		editor.on( 'Change SetContent paste', function () {
			window.clearTimeout( editor._wpEmbedPreviewTimer );
			editor._wpEmbedPreviewTimer = window.setTimeout( function () {
				scanAndPreview( editor.getBody() );
			}, 400 );
		} );

		// Prima del salvataggio (editor.save / getContent), riconverte ogni
		// blocco di anteprima nel semplice URL testuale, per non salvare
		// l'HTML dell'embed (che verrebbe rigenerato da WP_Embed lato PHP).
		editor.on( 'GetContent', function ( e ) {
			var temp = document.createElement( 'div' );
			temp.innerHTML = e.content;

			temp.querySelectorAll( '[data-wp-embed-preview="1"]' ).forEach( function ( block ) {
				var url = block.getAttribute( 'data-wp-embed-url' ) || '';
				var p = document.createElement( 'p' );
				p.textContent = url;
				block.replaceWith( p );
			} );

			e.content = temp.innerHTML;
		} );
	} );
} )();
