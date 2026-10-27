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
	function parseTimeParam( val ) {
		if ( ! val ) return 0;
		val = String( val ).trim().toLowerCase();
		if ( /^\d+s?$/i.test( val ) ) {
			return parseInt( val, 10 );
		}
		var seconds = 0;
		var h = val.match( /(\d+)h/i );
		var m = val.match( /(\d+)m/i );
		var s = val.match( /(\d+)s/i );
		if ( h ) seconds += parseInt( h[1], 10 ) * 3600;
		if ( m ) seconds += parseInt( m[1], 10 ) * 60;
		if ( s ) seconds += parseInt( s[1], 10 );
		return seconds;
	}

	function getYoutubeInfo( url ) {
		if ( ! url || typeof url !== 'string' ) return null;
		var u = url.trim();
		var videoId = '';
		var shortMatch = u.match( /(?:https?:)?\/\/(?:www\.)?youtu\.be\/([a-zA-Z0-9_-]{11})/i );
		if ( shortMatch ) {
			videoId = shortMatch[1];
		} else {
			var vMatch = u.match( /(?:https?:)?\/\/(?:www\.|m\.)?youtube(?:-nocookie)?\.com\/.*[?&]v=([a-zA-Z0-9_-]{11})/i );
			if ( vMatch ) {
				videoId = vMatch[1];
			} else {
				var embedMatch = u.match( /(?:https?:)?\/\/(?:www\.|m\.)?youtube(?:-nocookie)?\.com\/(?:embed|v|shorts|live)\/([a-zA-Z0-9_-]{11})/i );
				if ( embedMatch ) {
					videoId = embedMatch[1];
				}
			}
		}
		if ( ! videoId ) return null;

		var timeSeconds = 0;
		var tMatch = u.match( /[?&#](?:t|start)=([a-zA-Z0-9]+)/i );
		if ( tMatch ) {
			timeSeconds = parseTimeParam( tMatch[1] );
		}

		var embedUrl = 'https://www.youtube.com/embed/' + videoId;
		var params = [];
		if ( timeSeconds > 0 ) {
			params.push( 'start=' + timeSeconds );
		}
		var listMatch = u.match( /[?&#]list=([a-zA-Z0-9_-]+)/i );
		if ( listMatch ) {
			params.push( 'list=' + listMatch[1] );
		}
		if ( params.length > 0 ) {
			embedUrl += '?' + params.join( '&' );
		}

		return {
			videoId: videoId,
			startSeconds: timeSeconds,
			embedUrl: embedUrl
		};
	}

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
	function sanitizeEmbedHtml( html, originalUrl ) {
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

		var ytInfo = originalUrl ? getYoutubeInfo( originalUrl ) : null;

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

			if ( 'iframe' === el.tagName.toLowerCase() && ! el.hasAttribute( 'sandbox' ) ) {
				el.setAttribute( 'sandbox', 'allow-scripts allow-same-origin allow-popups allow-presentation' );

				// Se l'URL originale ha un timestamp YouTube, assicurati che il src usi start=SECONDI
				if ( ytInfo && ytInfo.startSeconds > 0 ) {
					var currentSrc = el.getAttribute( 'src' ) || '';
					if ( currentSrc.indexOf( 'youtube' ) !== -1 || currentSrc.indexOf( 'youtu.be' ) !== -1 ) {
						if ( currentSrc.indexOf( 'start=' ) === -1 ) {
							var cleaned = currentSrc.replace( /([?&])t=[a-zA-Z0-9]+(&?)/i, '$1' );
							cleaned = cleaned.replace( /[?&]$/, '' );
							cleaned += ( cleaned.indexOf( '?' ) === -1 ? '?' : '&' ) + 'start=' + ytInfo.startSeconds;
							el.setAttribute( 'src', cleaned );
						}
					}
				}
			}
		} );

		return doc.body ? doc.body.innerHTML : '';
	}

	function parseVimeoUrl( url ) {
		if ( ! url || typeof url !== 'string' ) return null;
		var u = url.trim();
		var m = u.match( /(?:https?:)?\/\/(?:www\.)?vimeo\.com\/(?:video\/)?([0-9]+)/i );
		if ( ! m ) return null;
		var videoId = m[1];
		var embedUrl = 'https://player.vimeo.com/video/' + videoId;
		var tMatch = u.match( /[?&#]t=([a-zA-Z0-9]+)/i );
		if ( tMatch ) {
			embedUrl += '#t=' + tMatch[1];
		}
		return {
			videoId: videoId,
			embedUrl: embedUrl
		};
	}

	/**
	 * Richiede l'anteprima HTML al proxy oEmbed di WordPress con fallback locale.
	 * Ritorna una Promise che risolve con l'HTML, o null se non embeddable.
	 */
	function fetchEmbedHtml( url ) {
		var localFallback = function () {
			var ytInfo = getYoutubeInfo( url );
			if ( ytInfo ) {
				return '<iframe src="' + ytInfo.embedUrl + '" width="560" height="314" allowfullscreen="allowfullscreen"></iframe>';
			}
			var vimeoInfo = parseVimeoUrl( url );
			if ( vimeoInfo ) {
				return '<iframe src="' + vimeoInfo.embedUrl + '" width="560" height="314" allowfullscreen="allowfullscreen"></iframe>';
			}
			return null;
		};

		if ( ! settings.oembedProxyUrl ) {
			return Promise.resolve( localFallback() );
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
					return localFallback();
				}
				return response.json();
			} )
			.then( function ( data ) {
				return ( data && data.html ) ? data.html : localFallback();
			} )
			.catch( function () {
				return localFallback();
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
				if ( editor.dom && editor.dom.getParent( match.node, '.wp-embed-preview-block' ) ) {
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
					var safeHtml = html ? sanitizeEmbedHtml( html, url ) : '';
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
