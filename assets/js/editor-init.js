/**
 * Inizializzazione di TinyMCE moderno (7 oppure 8, in base alla scelta
 * salvata nelle impostazioni del plugin; caricato da CDN o in locale)
 * in sostituzione dell'editor classico bundlato in WordPress.
 *
 * Si aggancia alle textarea che WordPress avrebbe normalmente trasformato
 * con la propria versione di TinyMCE (di solito #content, o l'id passato
 * a wp_editor()), e le inizializza con la configurazione moderna.
 *
 * Nota: questo file non contiene logica specifica per la major (le
 * opzioni di init usate qui sono stabili tra TinyMCE 7 e 8); la scelta
 * di quale major caricare avviene lato PHP (vedi class-mce-editor.php
 * e class-mce-vendor.php), che determina l'URL CDN o il percorso locale
 * passato a questo script.
 */
( function () {
	'use strict';

	if ( typeof window.tinymce === 'undefined' ) {
		// Il CDN o il bundle locale non si è caricato:
		// meglio lasciare la textarea semplice piuttosto che rompere la pagina.
		return;
	}

	// Alias di sicurezza per compatibilità con script WordPress che usano tinymce.$
	if ( ! window.tinymce.$ && window.jQuery ) {
		window.tinymce.$ = window.jQuery;
	}

	var settings = window.mceModernSettings || {};
	var toolbarPresets = settings.toolbarPresets || {};
	var toolbar = toolbarPresets[ settings.toolbarMode ] || toolbarPresets.extended;

	if ( settings.editorBaseUrl ) {
		tinymce.baseURL = settings.editorBaseUrl;
	}

	/**
	 * Riproduce la logica di wpautop() di WordPress in JavaScript.
	 * Trasforma doppie interruzioni di riga (\n\n) in paragrafi (<p>)
	 * e singole interruzioni di riga (\n) in tag <br />, proteggendo blocchi
	 * preformattati, script, stili, tabelle, iframe, ecc.
	 */
	function wpautop( text, br ) {
		if ( typeof text !== 'string' || ! text ) {
			return '';
		}
		if ( typeof br === 'undefined' ) {
			br = true;
		}

		var trimmed = text.trim();
		if ( trimmed === '' ) {
			return '';
		}

		var preserve = [];
		var preserveIndex = 0;

		// Normalizza i ritorni a capo
		var str = text.replace( /\r\n|\r/g, '\n' );

		// Proteggi i blocchi <script>, <style>, <pre>, <code>, <svg>, <!-- commenti -->
		str = str.replace( /<(script|style|pre|code|svg)[\s\S]*?<\/\1>/gi, function ( match ) {
			var placeholder = '<!--MCE_PRESERVE_' + ( preserveIndex++ ) + '-->';
			preserve.push( { placeholder: placeholder, content: match } );
			return placeholder;
		} );

		str = str.replace( /<!--[\s\S]*?-->/g, function ( match ) {
			var placeholder = '<!--MCE_PRESERVE_' + ( preserveIndex++ ) + '-->';
			preserve.push( { placeholder: placeholder, content: match } );
			return placeholder;
		} );

		var allblocks = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|math|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary|iframe)';

		var pees = str.split( /\n\s*\n/ );
		var result = '';

		for ( var i = 0; i < pees.length; i++ ) {
			var chunk = pees[ i ].trim();
			if ( chunk ) {
				var isBlock = new RegExp( '^<' + allblocks + '[\\s/>]', 'i' ).test( chunk );
				if ( ! isBlock ) {
					chunk = '<p>' + chunk + '</p>';
				}
				result += chunk + '\n';
			}
		}

		if ( br ) {
			result = result.replace( /(<p[^>]*>[\s\S]*?<\/p>)/gi, function ( pMatch ) {
				return pMatch.replace( /\n/g, '<br />' );
			} );
		}

		for ( var j = 0; j < preserve.length; j++ ) {
			result = result.replace( preserve[ j ].placeholder, preserve[ j ].content );
		}

		return result.trim();
	}

	function pre_wpautop( content ) {
		if ( typeof content !== 'string' || ! content ) {
			return '';
		}
		var output = content;
		output = output.replace( /<br\s*\/?>\n?/gi, '\n' );
		output = output.replace( /<\/p>\s*<p[^>]*>/gi, '\n\n' );
		output = output.replace( /<p[^>]*>/gi, '' );
		output = output.replace( /<\/p>/gi, '\n\n' );
		return output.trim();
	}

	function decodeEscapedHtml( content ) {
		if ( typeof content !== 'string' || ! content ) {
			return '';
		}

		return content
			.replace( /&lt;/gi, '<' )
			.replace( /&gt;/gi, '>' )
			.replace( /&quot;/gi, '"' )
			.replace( /&#0*39;|&#x0*27;/gi, '\'' )
			.replace( /&amp;/gi, '&' );
	}

	// Espone window.switchEditors per compatibilità con WordPress e plugin terzi
	if ( typeof window.switchEditors === 'undefined' ) {
		window.switchEditors = {
			wpautop: wpautop,
			pre_wpautop: pre_wpautop,
			_wpautop: wpautop,
			_wptexturize: function ( text ) { return text; },
			go: function ( id, mode ) {
				var ed = window.tinymce ? window.tinymce.get( id ) : null;
				var el = document.getElementById( id );
				if ( mode === 'html' || mode === 'text' ) {
					if ( ed ) {
						if ( el ) {
							el.value = ed.getContent();
						}
						ed.hide();
					}
				} else {
					if ( ed ) {
						if ( el ) {
							var val = el.value;
							if ( val && typeof val === 'string' && val.indexOf( '\n' ) !== -1 ) {
								var hasBlock = /<\/?(?:p|div|table|ul|ol|h[1-6]|blockquote|iframe|section|article)/i.test( val );
								if ( ! hasBlock ) {
									val = wpautop( val );
								}
							}
							el.value = val;
							ed.setContent( val );
						}
						ed.show();
					}
				}
			}
		};
	} else {
		if ( typeof window.switchEditors.wpautop !== 'function' ) {
			window.switchEditors.wpautop = wpautop;
		}
		if ( typeof window.switchEditors.pre_wpautop !== 'function' ) {
			window.switchEditors.pre_wpautop = pre_wpautop;
		}
	}

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

	var modernPlugins = [
		'advlist', 'autolink', 'lists', 'link', 'image', 'charmap',
		'preview', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
		'insertdatetime', 'media', 'table', 'wordcount', 'emoticons',
		'wpembedpreview', 'anchor'
	];

	var legacyPluginsToRemove = [
		'wpeditimage', 'wpgallery', 'wpemoji', 'wpdialogs', 'wplink',
		'wpview', 'colorpicker', 'textcolor', 'wordpress', 'wpautoresize',
		'wptextpattern', 'tabfocus', 'hr', 'paste', 'contextmenu', 'spellchecker'
	];

	/**
	 * Gestione e memorizzazione persistente dell'ultima preferenza per il vincolo
	 * delle proporzioni (icona lucchetto) nei dialog di inserimento/modifica Media e Immagini di TinyMCE.
	 */
	var MCE_LOCK_STORAGE_KEY = 'mce_media_constrain_proportions';

	function isLockActive( btn ) {
		if ( ! btn ) return false;
		var ariaPressed = btn.getAttribute( 'aria-pressed' );
		if ( ariaPressed === 'true' ) return true;
		if ( ariaPressed === 'false' ) return false;
		if ( btn.classList.contains( 'tox-button--active' ) ) return true;
		if ( btn.classList.contains( 'tox-lock--locked' ) ) return true;
		if ( btn.classList.contains( 'tox-lock--unlocked' ) ) return false;
		return true;
	}

	function checkAndApplyLockPref( btn ) {
		if ( ! btn || btn.dataset.mceLockPrefHandled === '1' ) {
			return;
		}
		btn.dataset.mceLockPrefHandled = '1';

		var savedPref = null;
		try {
			savedPref = localStorage.getItem( MCE_LOCK_STORAGE_KEY );
		} catch ( e ) {}

		// Se l'utente ha precedentemente scelto di disattivare il lucchetto ('false')
		if ( savedPref === 'false' ) {
			if ( isLockActive( btn ) ) {
				window.setTimeout( function () {
					if ( isLockActive( btn ) ) {
						btn.click();
					}
				}, 30 );
			}
		} else if ( savedPref === 'true' ) {
			if ( ! isLockActive( btn ) ) {
				window.setTimeout( function () {
					if ( ! isLockActive( btn ) ) {
						btn.click();
					}
				}, 30 );
			}
		}

		// Memorizza l'ultima scelta ogni volta che l'utente clicca il lucchetto
		btn.addEventListener( 'click', function () {
			window.setTimeout( function () {
				var currentLocked = isLockActive( btn );
				try {
					localStorage.setItem( MCE_LOCK_STORAGE_KEY, currentLocked ? 'true' : 'false' );
				} catch ( err ) {}
			}, 60 );
		} );
	}

	function scanForLockButtons( root ) {
		if ( ! root ) root = document;
		var selectors = [
			'button.tox-lock',
			'button[aria-label*="proportions" i]',
			'button[aria-label*="proporzioni" i]',
			'button[aria-label*="aspect" i]',
			'button[aria-label*="lock" i]',
			'button[aria-label*="lucchetto" i]',
			'.tox-form__controls-h-stacked button.tox-button--icon'
		];
		var buttons = root.querySelectorAll( selectors.join( ',' ) );
		Array.prototype.forEach.call( buttons, function ( btn ) {
			var parentGroup = btn.closest( '.tox-form__controls-h-stacked, .tox-form__group, .tox-dialog' );
			if ( parentGroup ) {
				checkAndApplyLockPref( btn );
			}
		} );
	}

	/**
	 * Protegge e sincronizza gli editor durante il salvataggio o l'aggiornamento
	 * dell'articolo (es. clic su 'Aggiorna', 'Pubblica', 'Salva bozza' o submit del form #post).
	 * Rimuove i falsi allarmi di 'modifiche non salvate' (beforeunload) di WordPress.
	 */
	function bindSaveAndSubmitGuards( editor ) {
		var targetEl = typeof editor.getElement === 'function' ? editor.getElement() : null;
		var form = targetEl && targetEl.form ? targetEl.form : document.getElementById( 'post' );

		function onSaveOrSubmit() {
			syncTargetElement( editor );
			if ( typeof editor.setDirty === 'function' ) {
				editor.setDirty( false );
			}
			if ( typeof editor.isNotDirty !== 'undefined' ) {
				editor.isNotDirty = true;
			}
			if ( typeof editor.save === 'function' ) {
				try {
					editor.save();
				} catch ( e ) {}
			}

			// Sincronizza e pulisce lo stato dirty anche per tutti gli altri editor
			if ( window.tinymce && window.tinymce.editors ) {
				window.tinymce.editors.forEach( function ( ed ) {
					if ( ed ) {
						syncTargetElement( ed );
						if ( typeof ed.setDirty === 'function' ) {
							ed.setDirty( false );
						}
						if ( typeof ed.isNotDirty !== 'undefined' ) {
							ed.isNotDirty = true;
						}
						if ( typeof ed.save === 'function' ) {
							try {
								ed.save();
							} catch ( errSave ) {}
						}
					}
				} );
			}

			// Disattiva il prompt di conferma di navigazione
			window.onbeforeunload = null;
			if ( window.jQuery ) {
				try {
					window.jQuery( window ).off( 'beforeunload.edit-post beforeunload' );
				} catch ( errJq ) {}
			}

			// Aggiorna lo stato di wp.autosave per evitare controlli disallineati
			if ( window.wp && window.wp.autosave ) {
				try {
					if ( typeof window.wp.autosave.getCompareString === 'function' ) {
						window.wp.autosave.initialCompareString = window.wp.autosave.getCompareString();
					}
					if ( window.wp.autosave.local && typeof window.wp.autosave.local.save === 'function' ) {
						window.wp.autosave.local.save();
					}
				} catch ( errAs ) {}
			}
		}

		if ( form && form.dataset.mceSaveGuarded !== '1' ) {
			form.dataset.mceSaveGuarded = '1';
			form.addEventListener( 'submit', onSaveOrSubmit, true );
		}

		var submitButtons = document.querySelectorAll( '#publish, #save-post, #post-preview, input[name="save"], input[name="publish"], button[type="submit"], input[type="submit"], .editor-post-publish-button, .editor-post-save-draft' );
		Array.prototype.forEach.call( submitButtons, function ( btn ) {
			if ( btn.dataset.mceSaveGuarded !== '1' ) {
				btn.dataset.mceSaveGuarded = '1';
				btn.addEventListener( 'click', onSaveOrSubmit, true );
			}
		} );
	}

	function sanitizePlugins( plugins ) {
		var list = [];
		if ( typeof plugins === 'string' ) {
			list = plugins.split( /[,\s]+/ );
		} else if ( Array.isArray( plugins ) ) {
			list = plugins;
		}

		var filtered = list.filter( function ( p ) {
			return p && legacyPluginsToRemove.indexOf( p ) === -1;
		} );

		modernPlugins.forEach( function ( p ) {
			if ( filtered.indexOf( p ) === -1 ) {
				filtered.push( p );
			}
		} );

		return filtered.join( ' ' );
	}

	function sanitizeConfig( userConfig ) {
		var theme = resolveTheme();
		var editorHeight = settings.editorHeight ? parseInt( settings.editorHeight, 10 ) : 600;
		if ( isNaN( editorHeight ) || editorHeight < 100 ) {
			editorHeight = 600;
		}

		var config = Object.assign( {}, userConfig );

		// Rimuove parametri e toolbar legacy di WordPress / TinyMCE 4 che corrompono il layout in TinyMCE 7/8
		delete config.toolbar1;
		delete config.toolbar2;
		delete config.toolbar3;
		delete config.toolbar4;
		delete config.wp_autoresize_on;
		delete config.wp_keep_scroll_position;
		delete config.wp_shortcut_labels;

		config.add_unload_trigger = false;
		config.license_key = 'gpl';
		config.theme = 'silver';
		config.skin = theme.skin;
		config.content_css = theme.content_css;
		config.language = settings.language || 'en';
		if ( settings.languageUrl ) {
			config.language_url = settings.languageUrl;
		}

		if ( settings.editorBaseUrl ) {
			config.base_url = settings.editorBaseUrl;
		}

		config.suffix = '.min';
		config.browser_spellcheck = true;
		config.contextmenu = false;
		config.entity_encoding = 'raw';
		config.forced_root_block = 'p';
		config.keep_styles = true;
		config.remove_trailing_brs = false;
		config.end_container_on_empty_block = true;
		config.pad_empty_with_br = true;
		config.media_live_embeds = true;
		config.extended_valid_elements = 'iframe[src|title|width|height|allowfullscreen|frameborder|style|class|id|loading|referrerpolicy],p[style|class|id|align],span[style|class|id],img[*],figure[*],figcaption[*]';
		config.custom_elements = '~iframe';

		var isIt = settings.isItalian !== undefined ? !! settings.isItalian : ( ( document.documentElement.lang || navigator.language || '' ).toLowerCase().indexOf( 'it' ) === 0 );

		config.image_advtab = true;
		config.image_caption = true;
		config.image_class_list = [
			{ title: isIt ? 'Nessun allineamento' : 'None', value: 'alignnone' },
			{ title: isIt ? 'Allinea a sinistra (testo a destra)' : 'Align left (wrap text)', value: 'alignleft' },
			{ title: isIt ? 'Allinea a destra (testo a sinistra)' : 'Align right (wrap text)', value: 'alignright' },
			{ title: isIt ? 'Al centro' : 'Align center', value: 'aligncenter' }
		];

		// Imposta formattazione pulita per i menu a tendina di Carattere e Dimensione carattere
		var defaultFontFamilies =
			'System Font=-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif; ' +
			'Manrope=Manrope,sans-serif; ' +
			'Inter=Inter,sans-serif; ' +
			'Roboto=Roboto,sans-serif; ' +
			'Open Sans="Open Sans",sans-serif; ' +
			'Lato=Lato,sans-serif; ' +
			'Montserrat=Montserrat,sans-serif; ' +
			'Poppins=Poppins,sans-serif; ' +
			'Playfair Display="Playfair Display",serif; ' +
			'Merriweather=Merriweather,serif; ' +
			'Arial=arial,helvetica,sans-serif; ' +
			'Arial Black=arial black,avant garde; ' +
			'Book Antiqua=book antiqua,palatino; ' +
			'Comic Sans MS=comic sans ms,sans-serif; ' +
			'Courier New=courier new,courier; ' +
			'Georgia=georgia,palatino; ' +
			'Helvetica=helvetica,sans-serif; ' +
			'Impact=impact,chicago; ' +
			'Tahoma=tahoma,arial,helvetica,sans-serif; ' +
			'Times New Roman=times new roman,times; ' +
			'Trebuchet MS=trebuchet ms,geneva; ' +
			'Verdana=verdana,geneva';

		var defaultFontSizes = '8pt 10pt 12pt 14pt 16pt 18pt 20pt 24pt 30pt 36pt 48pt';

		config.font_family_formats = userConfig.font_family_formats || userConfig.fontselect_formats || defaultFontFamilies;
		config.font_size_formats = userConfig.font_size_formats || userConfig.fontsize_formats || defaultFontSizes;
		config.fontsize_formats = config.font_size_formats;
		config.font_size_input_default_unit = 'pt';

		if ( settings.enableMenubar ) {
			config.menubar = ( typeof userConfig.menubar === 'string' && userConfig.menubar ) ? userConfig.menubar : 'file edit view insert format tools table';
			config.menu = {
				file: { title: 'File', items: 'newdocument restoredraft | preview | print' },
				edit: { title: 'Edit', items: 'undo redo | cut copy paste pastetext | selectall | searchreplace' },
				view: { title: 'View', items: 'code | visualaid visualchars visualblocks | preview fullscreen' },
				insert: { title: 'Insert', items: 'wp_add_media | image media link link_anchor anchor | table | charmap emoticons | hr insertdatetime' },
				format: { title: 'Format', items: 'bold italic underline strikethrough superscript subscript codeformat | blocks fontfamily fontsize align lineheight | forecolor backcolor | removeformat' },
				tools: { title: 'Tools', items: 'code wordcount' },
				table: { title: 'Table', items: 'inserttable | cell row column | tableprops deletetable' }
			};
		} else {
			config.menubar = false;
		}

		var rawToolbar = userConfig.toolbar || toolbar;
		if ( typeof rawToolbar === 'string' ) {
			if ( rawToolbar.indexOf( '\n' ) !== -1 ) {
				config.toolbar = rawToolbar.split( '\n' );
			} else {
				config.toolbar = rawToolbar;
			}
		} else {
			config.toolbar = rawToolbar;
		}
		config.toolbar_mode = 'wrap';
		config.branding = false;
		config.promotion = false;
		config.relative_urls = false;
		config.convert_urls = false;
		config.link_default_protocol = 'https';
		config.link_assume_external_targets = 'https';

		config.content_style =
			'.wp-embed-preview-block { margin: 1em 0; border: 1px dashed currentColor; opacity: 0.85; border-radius: 4px; padding: 4px; overflow: hidden; } ' +
			'.wp-embed-preview-block iframe { max-width: 100%; } ' +
			'img.alignleft, .alignleft { float: left !important; margin: 0.5em 1.5em 0.5em 0 !important; max-width: 100%; height: auto; } ' +
			'img.alignright, .alignright { float: right !important; margin: 0.5em 0 0.5em 1.5em !important; max-width: 100%; height: auto; } ' +
			'img.aligncenter, .aligncenter { display: block !important; margin-left: auto !important; margin-right: auto !important; clear: both !important; max-width: 100%; height: auto; float: none !important; } ' +
			'img.alignnone, .alignnone { float: none; margin: 0.5em 0; } ' +
			'figure.alignleft { float: left !important; margin: 0.5em 1.5em 0.5em 0 !important; } ' +
			'figure.alignright { float: right !important; margin: 0.5em 0 0.5em 1.5em !important; } ' +
			'figure.aligncenter { display: block !important; margin-left: auto !important; margin-right: auto !important; clear: both !important; float: none !important; } ' +
			'.mce-item-anchor, a.mce-item-anchor, a[id]:not([href]), a[name]:not([href]), span.mce-item-anchor { display: inline-block !important; background-color: rgba(255, 215, 0, 0.4) !important; border: 1px dashed #d97706 !important; border-radius: 3px !important; padding: 0 4px !important; margin: 0 2px !important; vertical-align: baseline !important; min-width: 14px; min-height: 14px; } ' +
			'.mce-item-anchor:empty::before, a.mce-item-anchor:empty::before, a[id]:not([href]):empty::before, a[name]:not([href]):empty::before, span.mce-item-anchor:empty::before { content: "⚓" !important; font-size: 13px !important; line-height: 1 !important; color: #92400e !important; display: inline-block !important; }';

		config.plugins = sanitizePlugins( userConfig.plugins );

		if ( settings.embedPreviewPluginUrl ) {
			config.external_plugins = Object.assign( {}, userConfig.external_plugins || {}, {
				wpembedpreview: settings.embedPreviewPluginUrl
			} );
		}

		if ( ! config.height && ! config.auto_focus ) {
			config.height = editorHeight;
		}

		var originalUrlConverter = userConfig.urlconverter_callback;
		config.urlconverter_callback = function ( url, node, on_save, name ) {
			if ( name === 'href' && url && typeof url === 'string' ) {
				var trimmed = url.trim();
				if ( trimmed && ! /^[a-z][a-z0-9+.-]*:/i.test( trimmed ) && ! /^[\/#!.?]/i.test( trimmed ) ) {
					return 'https://' + trimmed;
				}
			}
			if ( typeof originalUrlConverter === 'function' ) {
				return originalUrlConverter( url, node, on_save, name );
			}
			return url;
		};

		var originalPastePostprocess = userConfig.paste_postprocess;
		config.paste_postprocess = function ( plugin, args ) {
			if ( args && args.node ) {
				// Normalizza eventuali tag <div> non stilati in <p> per coerenza semantica
				var divs = args.node.querySelectorAll( 'div' );
				Array.prototype.forEach.call( divs, function ( div ) {
					if ( ! div.className && ! div.id && ( ! div.style.cssText || div.style.cssText.trim() === '' ) ) {
						var p = document.createElement( 'p' );
						while ( div.firstChild ) {
							p.appendChild( div.firstChild );
						}
						if ( div.parentNode ) {
							div.parentNode.replaceChild( p, div );
						}
					}
				} );

				// Normalizza i link senza schema in HTTPS
				var links = args.node.querySelectorAll( 'a[href]' );
				Array.prototype.forEach.call( links, function ( link ) {
					var href = ( link.getAttribute( 'href' ) || '' ).trim();
					if ( href && ! /^[a-z][a-z0-9+.-]*:/i.test( href ) && ! /^[\/#!.?]/i.test( href ) ) {
						link.setAttribute( 'href', 'https://' + href );
					}
				} );
			}

			if ( typeof originalPastePostprocess === 'function' ) {
				originalPastePostprocess( plugin, args );
			}
		};

	function openWpMediaFrame( editor ) {
		var isIt = settings.isItalian !== undefined ? !! settings.isItalian : ( ( document.documentElement.lang || navigator.language || '' ).toLowerCase().indexOf( 'it' ) === 0 );
		if ( typeof window.wp !== 'undefined' && window.wp.media ) {
			var frame = window.wp.media( {
				title: isIt ? 'Seleziona o carica file multimediale' : 'Select or upload media',
				button: { text: isIt ? 'Inserisci nell\'articolo' : 'Insert into post' },
				multiple: true
			} );

			frame.on( 'select', function () {
				var selection = frame.state().get( 'selection' );
				selection.each( function ( attachmentModel ) {
					var attachment = attachmentModel.toJSON();
					if ( attachment && attachment.url ) {
						if ( attachment.type === 'image' ) {
							var alt = attachment.alt || attachment.title || '';
							var display = {};
							try {
								if ( frame.state().display ) {
									display = frame.state().display( attachmentModel ).toJSON();
								}
							} catch ( errDisplay ) {}

							var align = ( display.align || attachment.align || 'none' ).toLowerCase();
							var size = display.size || 'full';
							var imgUrl = attachment.url;
							var width = attachment.width;
							var height = attachment.height;

							if ( attachment.sizes && attachment.sizes[ size ] ) {
								imgUrl = attachment.sizes[ size ].url;
								width = attachment.sizes[ size ].width;
								height = attachment.sizes[ size ].height;
							}

							var classNames = [];
							var inlineStyles = [];

							if ( align === 'left' ) {
								classNames.push( 'alignleft' );
								inlineStyles.push( 'float: left', 'margin: 0.5em 1.5em 0.5em 0', 'max-width: 100%', 'height: auto' );
							} else if ( align === 'right' ) {
								classNames.push( 'alignright' );
								inlineStyles.push( 'float: right', 'margin: 0.5em 0 0.5em 1.5em', 'max-width: 100%', 'height: auto' );
							} else if ( align === 'center' ) {
								classNames.push( 'aligncenter' );
								inlineStyles.push( 'display: block', 'margin-left: auto', 'margin-right: auto', 'clear: both', 'max-width: 100%', 'height: auto' );
							} else {
								classNames.push( 'alignnone' );
							}

							var classAttr = classNames.length ? ' class="' + classNames.join( ' ' ) + '"' : '';
							var styleAttr = inlineStyles.length ? ' style="' + inlineStyles.join( '; ' ) + ';"' : '';
							var imgHtml = '<img src="' + imgUrl + '" alt="' + alt + '"' + classAttr + styleAttr;
							if ( width ) {
								imgHtml += ' width="' + width + '"';
							}
							if ( height ) {
								imgHtml += ' height="' + height + '"';
							}
							imgHtml += ' />';

							// Per allineamento sinistro o destro, inserisce l'immagine seguita da uno spazio
							// in modo che l'utente possa subito iniziare a digitare il testo a lato dell'immagine
							if ( align === 'left' || align === 'right' ) {
								editor.insertContent( imgHtml + '&nbsp;' );
							} else {
								editor.insertContent( imgHtml );
							}
						} else if ( attachment.type === 'video' ) {
							editor.insertContent( '<video controls src="' + attachment.url + '"></video>' );
						} else if ( attachment.type === 'audio' ) {
							editor.insertContent( '<audio controls src="' + attachment.url + '"></audio>' );
						} else {
							var label = attachment.title || attachment.filename || 'File';
							editor.insertContent( '<a href="' + attachment.url + '">' + label + '</a>' );
						}
					}
				} );
			} );

			frame.open();
		} else {
			editor.execCommand( 'mceImage' );
		}
	}

	function isValidAnchorId( id, el ) {
		if ( ! id || typeof id !== 'string' ) {
			return false;
		}
		var trimmed = id.trim();
		if ( ! trimmed ) {
			return false;
		}
		// Escludi ID generati automaticamente da TinyMCE / WP / browser
		if ( trimmed === 'tinymce' || trimmed === 'mce-content-body' ) {
			return false;
		}
		if ( /^mce[-_]/i.test( trimmed ) || /^wp[-_]/i.test( trimmed ) ) {
			return false;
		}
		if ( el ) {
			var tagName = el.tagName ? el.tagName.toLowerCase() : '';
			if ( tagName === 'body' || tagName === 'html' || tagName === 'script' || tagName === 'style' || tagName === 'link' || tagName === 'iframe' ) {
				return false;
			}
		}
		return true;
	}

	function openLinkAnchorDialog( editor ) {
		var isIt = settings.isItalian !== undefined ? !! settings.isItalian : ( ( document.documentElement.lang || navigator.language || '' ).toLowerCase().indexOf( 'it' ) === 0 );
		var doc = editor.getDoc();
		var anchors = [];
		if ( doc ) {
			var elements = doc.querySelectorAll( '[id], a[name], .mce-item-anchor' );
			Array.prototype.forEach.call( elements, function ( el ) {
				var id = el.getAttribute( 'id' ) || el.getAttribute( 'name' );
				if ( isValidAnchorId( id, el ) && anchors.indexOf( id.trim() ) === -1 ) {
					anchors.push( id.trim() );
				}
			} );
		}

		var currentNode = editor.selection.getNode();
		var existingLinkNode = editor.dom.getParent( currentNode, 'a, .mce-item-anchor' );

		var existingHref = '';
		var selectedText = '';

		if ( existingLinkNode ) {
			existingHref = existingLinkNode.getAttribute( 'href' ) || '';
			if ( ! existingHref && ( existingLinkNode.getAttribute( 'id' ) || existingLinkNode.getAttribute( 'name' ) ) ) {
				existingHref = '#' + ( existingLinkNode.getAttribute( 'id' ) || existingLinkNode.getAttribute( 'name' ) );
			}
			selectedText = existingLinkNode.innerText || existingLinkNode.textContent || '';
		} else {
			selectedText = editor.selection.getContent( { format: 'text' } ) || '';
		}

		var items = [
			{ text: isIt ? '-- Seleziona un\'ancora presente nel documento --' : '-- Select an anchor present in document --', value: '' }
		];
		var foundInSelect = false;
		anchors.forEach( function ( a ) {
			var val = '#' + a;
			items.push( { text: val, value: val } );
			if ( existingHref && existingHref === val ) {
				foundInSelect = true;
			}
		} );

		var initSelectedAnchor = foundInSelect ? existingHref : '';
		var initCustomAnchor = foundInSelect ? '' : existingHref;

		var dialogSpec = {
			title: existingLinkNode ?
				( isIt ? 'Modifica Link ad Ancora' : 'Edit Anchor Link' ) :
				( isIt ? 'Inserisci Link ad Ancora' : 'Insert Anchor Link' ),
			body: {
				type: 'panel',
				items: [
					{
						type: 'selectbox',
						name: 'selectedAnchor',
						label: isIt ? 'Ancore trovate nel documento:' : 'Anchors found in document:',
						items: items
					},
					{
						type: 'input',
						name: 'customAnchor',
						label: isIt ? 'Oppure digita nome o ID dell\'ancora (es. sezione1 o #sezione1):' : 'Or type anchor name or ID (e.g. section1 or #section1):'
					},
					{
						type: 'input',
						name: 'displayText',
						label: isIt ? 'Testo del link (opzionale, lascia vuoto per ancoraggio semplice):' : 'Link text (optional, leave empty for plain anchor):'
					}
				]
			},
			initialData: {
				selectedAnchor: initSelectedAnchor,
				customAnchor: initCustomAnchor,
				displayText: selectedText
			},
			buttons: [
				{
					type: 'cancel',
					text: isIt ? 'Annulla' : 'Cancel'
				},
				{
					type: 'submit',
					text: existingLinkNode ?
						( isIt ? 'Aggiorna Link' : 'Update Link' ) :
						( isIt ? 'Inserisci Link' : 'Insert Link' ),
					primary: true
				}
			],
			onSubmit: function ( api ) {
				var data = api.getData();
				var anchorVal = ( data.customAnchor || data.selectedAnchor || '' ).trim();
				if ( ! anchorVal ) {
					if ( existingLinkNode ) {
						editor.dom.remove( existingLinkNode, true );
					}
					api.close();
					return;
				}
				if ( anchorVal.indexOf( '#' ) !== 0 && anchorVal.indexOf( 'http' ) !== 0 && anchorVal.indexOf( '/' ) !== 0 ) {
					anchorVal = '#' + anchorVal;
				}
				var text = ( data.displayText || '' ).trim();

				if ( existingLinkNode ) {
					if ( anchorVal.indexOf( '#' ) === 0 ) {
						editor.dom.setAttrib( existingLinkNode, 'id', anchorVal.substring( 1 ) );
					}
					editor.dom.setAttrib( existingLinkNode, 'href', anchorVal );
					editor.dom.addClass( existingLinkNode, 'mce-item-anchor' );
					existingLinkNode.textContent = text;
					editor.selection.select( existingLinkNode );
				} else {
					var idAttr = ( anchorVal.indexOf( '#' ) === 0 ) ? ' id="' + anchorVal.substring( 1 ) + '"' : '';
					var html = '<a href="' + anchorVal + '"' + idAttr + ' class="mce-item-anchor">' + text + '</a>';
					editor.insertContent( html );
				}
				api.close();
			}
		};

		editor.windowManager.open( dialogSpec );
	}

	function syncTargetElement( editor ) {
		if ( ! editor ) {
			return;
		}
		try {
			editor.save();
		} catch ( e ) {}

		var el = typeof editor.getElement === 'function' ? editor.getElement() : null;
		if ( el ) {
			try {
				el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} catch ( err ) {
				var evt = document.createEvent( 'HTMLEvents' );
				evt.initEvent( 'change', true, true );
				el.dispatchEvent( evt );
			}
		}

		// Sincronizza lo stato del blocco Gutenberg tramite lo store React wp.data
		if ( window.wp && window.wp.data && typeof window.wp.data.dispatch === 'function' && typeof window.wp.data.select === 'function' ) {
			try {
				var blockEditor = window.wp.data.select( 'core/block-editor' );
				if ( blockEditor ) {
					var selectedBlock = blockEditor.getSelectedBlock();
					if ( selectedBlock && selectedBlock.clientId ) {
						var content = editor.getContent();
						window.wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes(
							selectedBlock.clientId,
							{ content: content }
						);
					}
				}
			} catch ( err2 ) {}
		}
	}

	var originalSetup = userConfig.setup;
		config.setup = function ( editor ) {
			// Gestione sicura del throbber/overlay di caricamento per evitare che rimanga bloccato
			var throbberTimer = null;
			var clearThrobber = function () {
				try {
					var container = typeof editor.getContainer === 'function' ? editor.getContainer() : null;
					if ( container ) {
						container.classList.remove( 'tox-tinymce--busy' );
						container.removeAttribute( 'aria-busy' );
						var ths = container.querySelectorAll( '.tox-throbber' );
						Array.prototype.forEach.call( ths, function ( th ) {
							th.style.display = 'none';
							th.style.pointerEvents = 'none';
							th.removeAttribute( 'aria-busy' );
						} );
					}
				} catch ( errTh ) {}
			};

			var origSetProgressState = editor.setProgressState;
			editor.setProgressState = function ( state, time ) {
				if ( typeof origSetProgressState === 'function' ) {
					try {
						origSetProgressState.call( editor, state, time );
					} catch ( eState ) {}
				}
				window.clearTimeout( throbberTimer );
				if ( state ) {
					throbberTimer = window.setTimeout( function () {
						clearThrobber();
					}, 800 );
				} else {
					clearThrobber();
				}
			};

			// Tracciamento avanzato dello stato delle modifiche (isContentDirty)
			var initialContent = '';
			var isContentDirty = false;

			function checkHasUnsavedChanges() {
				if ( ! isContentDirty ) {
					if ( typeof editor.isDirty === 'function' && ! editor.isDirty() ) {
						return false;
					}
				}
				try {
					var current = editor.getContent();
					if ( current === initialContent ) {
						isContentDirty = false;
						if ( typeof editor.setDirty === 'function' ) {
							editor.setDirty( false );
						}
						return false;
					}
				} catch ( e ) {}
				return true;
			}

			// Sincronizzazione e tracciamento modifiche utente
			editor.on( 'keyup change paste input undo redo', function () {
				isContentDirty = true;
				syncTargetElement( editor );
			} );

			editor.on( 'ExecCommand blur', function () {
				syncTargetElement( editor );
			} );

			editor.on( 'remove detach BeforeUnload', function () {
				if ( isContentDirty ) {
					syncTargetElement( editor );
				}
			} );

			var isIt = settings.isItalian !== undefined ? !! settings.isItalian : ( ( document.documentElement.lang || navigator.language || '' ).toLowerCase().indexOf( 'it' ) === 0 );

			// Registra il pulsante e la voce di menu "Salva" / "Save"
			editor.ui.registry.addButton( 'wp_save', {
				text: isIt ? 'Salva' : 'Save',
				icon: 'save',
				tooltip: isIt ? 'Salva le modifiche e sincronizza il contenuto' : 'Save changes and sync content',
				onAction: function () {
					syncTargetElement( editor );
					isContentDirty = false;
					if ( typeof editor.setDirty === 'function' ) {
						editor.setDirty( false );
					}
					try {
						initialContent = editor.getContent();
					} catch ( e ) {}
					if ( editor.notificationManager ) {
						editor.notificationManager.open( {
							text: isIt ? 'Contenuto salvato con successo!' : 'Content saved successfully!',
							type: 'success',
							timeout: 2000
						} );
					}
				}
			} );

			editor.ui.registry.addMenuItem( 'wp_save', {
				text: isIt ? 'Salva contenuto' : 'Save content',
				icon: 'save',
				onAction: function () {
					syncTargetElement( editor );
					isContentDirty = false;
					if ( typeof editor.setDirty === 'function' ) {
						editor.setDirty( false );
					}
					try {
						initialContent = editor.getContent();
					} catch ( e ) {}
				}
			} );

			// Registra il pulsante e la voce di menu "Aggiungi media" / "Add media"
			editor.ui.registry.addButton( 'wp_add_media', {
				text: isIt ? 'Aggiungi media' : 'Add media',
				icon: 'image',
				tooltip: isIt ? 'Aggiungi media (Libreria Media WordPress)' : 'Add media (WordPress Media Library)',
				onAction: function () {
					openWpMediaFrame( editor );
				}
			} );

			editor.ui.registry.addMenuItem( 'wp_add_media', {
				text: isIt ? 'Aggiungi media (Libreria WP)...' : 'Add media (WP Library)...',
				icon: 'image',
				onAction: function () {
					openWpMediaFrame( editor );
				}
			} );

			function isAnchorElement( el ) {
				if ( ! el || ! editor.dom ) {
					return false;
				}
				var node = editor.dom.getParent( el, 'a, .mce-item-anchor' );
				if ( ! node ) {
					return false;
				}

				// Elemento con classe mce-item-anchor (generato per le ancore)
				if ( editor.dom.hasClass( node, 'mce-item-anchor' ) ) {
					return true;
				}

				var href = node.getAttribute( 'href' );

				// Se non ha href (o è vuoto), ma ha un id o name, è un'ancora di destinazione (bookmark)
				if ( href === null || href === '' || typeof href === 'undefined' ) {
					if ( node.getAttribute( 'id' ) || node.getAttribute( 'name' ) ) {
						return true;
					}
					return false;
				}

				// Se ha href e inizia con '#' (es. #sezione1), è un link ad un'ancora
				if ( typeof href === 'string' && href.trim().charAt( 0 ) === '#' ) {
					return true;
				}

				return false;
			}

			// Registra il pulsante e la voce di menu "Link ad ancora" / "Anchor link"
			editor.ui.registry.addToggleButton( 'link_anchor', {
				text: isIt ? 'Link ad ancora' : 'Anchor link',
				icon: 'bookmark',
				tooltip: isIt ? 'Inserisci link ad un\'ancora presente nel documento (#ancora)' : 'Insert link to an anchor in the document (#anchor)',
				onAction: function () {
					openLinkAnchorDialog( editor );
				},
				onSetup: function ( buttonApi ) {
					var nodeChangeHandler = function ( e ) {
						try {
							var isAnchor = isAnchorElement( e.element );
							if ( buttonApi && typeof buttonApi.setActive === 'function' ) {
								buttonApi.setActive( !! isAnchor );
							}
						} catch ( err ) {}
					};
					editor.on( 'NodeChange', nodeChangeHandler );
					return function () {
						editor.off( 'NodeChange', nodeChangeHandler );
					};
				}
			} );

			editor.ui.registry.addMenuItem( 'link_anchor', {
				text: isIt ? 'Link ad ancora...' : 'Anchor link...',
				icon: 'bookmark',
				onAction: function () {
					openLinkAnchorDialog( editor );
				}
			} );

			function getSelectedImageNode() {
				var selected = editor.selection.getNode();
				if ( ! selected ) return null;
				if ( selected.nodeName === 'IMG' ) return selected;
				if ( editor.dom.is( selected, 'figure.image' ) ) return selected;
				return editor.dom.getParent( selected, 'img, figure.image' );
			}

			function applyImageAlignment( imgNode, align ) {
				if ( ! imgNode ) return;
				editor.undoManager.transact( function () {
					var target = imgNode;
					var figure = editor.dom.getParent( target, 'figure' );
					var elToStyle = figure || target;

					editor.dom.removeClass( elToStyle, 'alignleft alignright aligncenter alignnone' );
					if ( target !== elToStyle ) {
						editor.dom.removeClass( target, 'alignleft alignright aligncenter alignnone' );
					}

					if ( align === 'left' ) {
						editor.dom.addClass( elToStyle, 'alignleft' );
						editor.dom.setStyle( elToStyle, 'float', 'left' );
						editor.dom.setStyle( elToStyle, 'margin', '0.5em 1.5em 0.5em 0' );
						editor.dom.setStyle( elToStyle, 'display', '' );
						editor.dom.setStyle( elToStyle, 'clear', '' );
					} else if ( align === 'right' ) {
						editor.dom.addClass( elToStyle, 'alignright' );
						editor.dom.setStyle( elToStyle, 'float', 'right' );
						editor.dom.setStyle( elToStyle, 'margin', '0.5em 0 0.5em 1.5em' );
						editor.dom.setStyle( elToStyle, 'display', '' );
						editor.dom.setStyle( elToStyle, 'clear', '' );
					} else if ( align === 'center' ) {
						editor.dom.addClass( elToStyle, 'aligncenter' );
						editor.dom.setStyle( elToStyle, 'float', 'none' );
						editor.dom.setStyle( elToStyle, 'display', 'block' );
						editor.dom.setStyle( elToStyle, 'marginLeft', 'auto' );
						editor.dom.setStyle( elToStyle, 'marginRight', 'auto' );
						editor.dom.setStyle( elToStyle, 'clear', 'both' );
					} else {
						editor.dom.addClass( elToStyle, 'alignnone' );
						editor.dom.setStyle( elToStyle, 'float', 'none' );
						editor.dom.setStyle( elToStyle, 'margin', '' );
						editor.dom.setStyle( elToStyle, 'display', '' );
						editor.dom.setStyle( elToStyle, 'clear', '' );
					}

					editor.nodeChanged();
					syncTargetElement( editor );
				} );
			}

			// Intercetta i comandi di allineamento standard della toolbar (JustifyLeft, etc.) quando un'immagine è selezionata
			editor.on( 'BeforeExecCommand', function ( e ) {
				var cmd = ( e.command || '' ).toLowerCase();
				if ( cmd === 'justifyleft' || cmd === 'justifyright' || cmd === 'justifycenter' || cmd === 'justifynone' ) {
					var img = getSelectedImageNode();
					if ( img ) {
						e.preventDefault();
						if ( cmd === 'justifyleft' ) {
							applyImageAlignment( img, 'left' );
						} else if ( cmd === 'justifyright' ) {
							applyImageAlignment( img, 'right' );
						} else if ( cmd === 'justifycenter' ) {
							applyImageAlignment( img, 'center' );
						} else {
							applyImageAlignment( img, 'none' );
						}
					}
				}
			} );

			// Registra i pulsanti per l'allineamento immagini nella toolbar contestuale
			editor.ui.registry.addToggleButton( 'mce_img_align_left', {
				icon: 'align-left',
				tooltip: isIt ? 'Allinea a sinistra (testo a destra)' : 'Align left (wrap text)',
				onAction: function () {
					var node = getSelectedImageNode();
					if ( node ) {
						applyImageAlignment( node, 'left' );
					}
				},
				onSetup: function ( buttonApi ) {
					var handler = function () {
						var node = getSelectedImageNode();
						if ( buttonApi && typeof buttonApi.setActive === 'function' ) {
							buttonApi.setActive( !! ( node && ( editor.dom.hasClass( node, 'alignleft' ) || editor.dom.getStyle( node, 'float' ) === 'left' ) ) );
						}
					};
					editor.on( 'NodeChange', handler );
					return function () {
						editor.off( 'NodeChange', handler );
					};
				}
			} );

			editor.ui.registry.addToggleButton( 'mce_img_align_center', {
				icon: 'align-center',
				tooltip: isIt ? 'Al centro' : 'Align center',
				onAction: function () {
					var node = getSelectedImageNode();
					if ( node ) {
						applyImageAlignment( node, 'center' );
					}
				},
				onSetup: function ( buttonApi ) {
					var handler = function () {
						var node = getSelectedImageNode();
						if ( buttonApi && typeof buttonApi.setActive === 'function' ) {
							buttonApi.setActive( !! ( node && ( editor.dom.hasClass( node, 'aligncenter' ) || ( editor.dom.getStyle( node, 'display' ) === 'block' && editor.dom.getStyle( node, 'margin-left' ) === 'auto' ) ) ) );
						}
					};
					editor.on( 'NodeChange', handler );
					return function () {
						editor.off( 'NodeChange', handler );
					};
				}
			} );

			editor.ui.registry.addToggleButton( 'mce_img_align_right', {
				icon: 'align-right',
				tooltip: isIt ? 'Allinea a destra (testo a sinistra)' : 'Align right (wrap text)',
				onAction: function () {
					var node = getSelectedImageNode();
					if ( node ) {
						applyImageAlignment( node, 'right' );
					}
				},
				onSetup: function ( buttonApi ) {
					var handler = function () {
						var node = getSelectedImageNode();
						if ( buttonApi && typeof buttonApi.setActive === 'function' ) {
							buttonApi.setActive( !! ( node && ( editor.dom.hasClass( node, 'alignright' ) || editor.dom.getStyle( node, 'float' ) === 'right' ) ) );
						}
					};
					editor.on( 'NodeChange', handler );
					return function () {
						editor.off( 'NodeChange', handler );
					};
				}
			} );

			editor.ui.registry.addToggleButton( 'mce_img_align_none', {
				icon: 'align-none',
				tooltip: isIt ? 'Nessun allineamento (in linea)' : 'No alignment (inline)',
				onAction: function () {
					var node = getSelectedImageNode();
					if ( node ) {
						applyImageAlignment( node, 'none' );
					}
				},
				onSetup: function ( buttonApi ) {
					var handler = function () {
						var node = getSelectedImageNode();
						if ( buttonApi && typeof buttonApi.setActive === 'function' ) {
							var isAligned = node && (
								editor.dom.hasClass( node, 'alignleft' ) ||
								editor.dom.hasClass( node, 'alignright' ) ||
								editor.dom.hasClass( node, 'aligncenter' ) ||
								editor.dom.getStyle( node, 'float' ) === 'left' ||
								editor.dom.getStyle( node, 'float' ) === 'right' ||
								editor.dom.getStyle( node, 'display' ) === 'block'
							);
							buttonApi.setActive( !! ( node && ! isAligned ) );
						}
					};
					editor.on( 'NodeChange', handler );
					return function () {
						editor.off( 'NodeChange', handler );
					};
				}
			} );

			editor.ui.registry.addButton( 'mce_img_edit', {
				icon: 'image',
				tooltip: isIt ? 'Modifica immagine' : 'Edit image',
				onAction: function () {
					editor.execCommand( 'mceImage' );
				}
			} );

			if ( settings.enableImageAlignment !== false ) {
				editor.ui.registry.addContextToolbar( 'imagealignment', {
					predicate: function ( node ) {
						return !! ( node && ( node.nodeName === 'IMG' || editor.dom.is( node, 'figure.image' ) || editor.dom.getParent( node, 'img, figure.image' ) ) );
					},
					items: 'mce_img_align_left mce_img_align_center mce_img_align_right mce_img_align_none | mce_img_edit',
					position: 'node',
					scope: 'node'
				} );
			}

			// Seleziona il nodo dell'ancora o del link al clic
			editor.on( 'click', function ( e ) {
				var anchorNode = editor.dom.getParent( e.target, 'a[href],a[name],a[id],.mce-item-anchor' );
				if ( anchorNode ) {
					editor.selection.select( anchorNode );
				}
			} );

			// Apri finestra di modifica quando si fa doppio clic su un link o un'ancora
			editor.on( 'dblclick', function ( e ) {
				if ( isAnchorElement( e.target ) ) {
					e.preventDefault();
					openLinkAnchorDialog( editor );
				} else {
					var normalLink = editor.dom.getParent( e.target, 'a[href]' );
					if ( normalLink ) {
						e.preventDefault();
						editor.execCommand( 'mceLink' );
					}
				}
			} );

			// Pulizia automatica del throbber ad ogni evento chiave di caricamento
			editor.on( 'init PostRender LoadContent', function () {
				clearThrobber();
				window.setTimeout( clearThrobber, 100 );
				window.setTimeout( clearThrobber, 500 );
				window.setTimeout( clearThrobber, 1000 );
			} );

			// Integrazione avanzata con i popup / modal di Gutenberg
			editor.on( 'init', function () {
				clearThrobber();
				try {
					initialContent = editor.getContent();
				} catch ( e ) {
					initialContent = '';
				}
				isContentDirty = false;
				if ( typeof editor.setDirty === 'function' ) {
					editor.setDirty( false );
				}
				syncTargetElement( editor );

				var checkModal = function () {
					var container = typeof editor.getContainer === 'function' ? editor.getContainer() : null;
					if ( ! container ) return;

					var modalFrame = container.closest( '.components-modal__frame, .block-library-classic__modal, .block-editor-freeform-modal' );
					if ( ! modalFrame ) {
						var modalContent = container.closest( '.components-modal__content' );
						if ( modalContent ) {
							modalFrame = modalContent.closest( '.components-modal__frame' ) || modalContent.parentElement;
						}
					}

					if ( modalFrame ) {
						// Aggiunge il pulsante primario "Salva" / "Save" nella header del popup Gutenberg
						var header = modalFrame.querySelector( '.components-modal__header' );
						if ( header && ! header.querySelector( '.mce-modal-header-save-btn' ) ) {
							var saveBtn = document.createElement( 'button' );
							saveBtn.type = 'button';
							saveBtn.className = 'components-button is-primary mce-modal-header-save-btn';
							saveBtn.textContent = isIt ? 'Salva' : 'Save';

							saveBtn.addEventListener( 'click', function ( e ) {
								e.preventDefault();
								e.stopPropagation();
								syncTargetElement( editor );
								isContentDirty = false;
								if ( typeof editor.setDirty === 'function' ) {
									editor.setDirty( false );
								}
								try {
									initialContent = editor.getContent();
								} catch ( err ) {}

								var origText = saveBtn.textContent;
								saveBtn.textContent = isIt ? 'Salvato!' : 'Saved!';
								saveBtn.disabled = true;
								window.setTimeout( function () {
									saveBtn.textContent = origText;
									saveBtn.disabled = false;
								}, 1500 );

								if ( editor.notificationManager ) {
									editor.notificationManager.open( {
										text: isIt ? 'Contenuto salvato con successo!' : 'Content saved successfully!',
										type: 'success',
										timeout: 2000
									} );
								}
							} );

							var closeBtn = header.querySelector( 'button.components-modal__close-button, button[aria-label*="Close"], button[aria-label*="Chiudi"], .components-button.has-icon' );
							if ( closeBtn ) {
								header.insertBefore( saveBtn, closeBtn );
							} else {
								header.appendChild( saveBtn );
							}
						}

						// Intercetta il click sul pulsante di chiusura 'X' o sfondo overlay
						var closeTargets = modalFrame.querySelectorAll( '.components-modal__header button, button.components-modal__close-button, button[aria-label*="Close"], button[aria-label*="Chiudi"]' );
						var overlay = modalFrame.parentElement ? modalFrame.parentElement.querySelector( '.components-modal__screen-overlay' ) : null;

						var handleModalCloseAttempt = function ( e ) {
							// Se NON ci sono modifiche non salvate, NON viene mostrato alcun avviso!
							if ( checkHasUnsavedChanges() ) {
								var msg = isIt ?
									'Ci sono modifiche non salvate nell\'editor.\n\nDesideri SALVARE le modifiche prima di chiudere?\n\n• Premi OK per salvare ed uscire.\n• Premi Annulla per uscire senza salvare.' :
									'There are unsaved changes in the editor.\n\nDo you want to SAVE your changes before closing?\n\n• Click OK to save and exit.\n• Click Cancel to exit without saving.';

								var userSaveChoice = window.confirm( msg );
								if ( userSaveChoice ) {
									syncTargetElement( editor );
									isContentDirty = false;
									if ( typeof editor.setDirty === 'function' ) {
										editor.setDirty( false );
									}
									try {
										initialContent = editor.getContent();
									} catch ( err ) {}
								} else {
									// L'UTENTE HA PREMUTO ANNULLA: NON salvare e RIPRISTINA il contenuto iniziale!
									isContentDirty = false;
									if ( typeof editor.setDirty === 'function' ) {
										editor.setDirty( false );
									}

									// 1. Ripristina la textarea target
									var el = typeof editor.getElement === 'function' ? editor.getElement() : null;
									if ( el ) {
										el.value = initialContent;
									}

									// 2. Ripristina lo store del blocco Gutenberg React
									if ( window.wp && window.wp.data && typeof window.wp.data.dispatch === 'function' && typeof window.wp.data.select === 'function' ) {
										try {
											var blockEditor = window.wp.data.select( 'core/block-editor' );
											if ( blockEditor ) {
												var selectedBlock = blockEditor.getSelectedBlock();
												if ( selectedBlock && selectedBlock.clientId ) {
													window.wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes(
														selectedBlock.clientId,
														{ content: initialContent }
													);
												}
											}
										} catch ( errRevert ) {}
									}

									// 3. Ripristina il contenuto in TinyMCE
									try {
										editor.setContent( initialContent );
									} catch ( errSet ) {}
								}
							}
						};

						Array.prototype.forEach.call( closeTargets, function ( btn ) {
							if ( btn.classList.contains( 'mce-modal-header-save-btn' ) ) {
								return;
							}
							if ( btn.getAttribute( 'aria-label' ) && btn.getAttribute( 'aria-label' ).toLowerCase().indexOf( 'screen' ) !== -1 ) {
								return;
							}
							if ( btn.dataset.mceCloseWarnHandler === '1' ) {
								return;
							}
							btn.dataset.mceCloseWarnHandler = '1';
							btn.addEventListener( 'click', handleModalCloseAttempt, true );
						} );

						if ( overlay && overlay.dataset.mceCloseWarnHandler !== '1' ) {
							overlay.dataset.mceCloseWarnHandler = '1';
							overlay.addEventListener( 'click', handleModalCloseAttempt, true );
						}
					}
				};

				checkModal();
				window.setTimeout( checkModal, 200 );
				window.setTimeout( checkModal, 500 );
				window.setTimeout( checkModal, 1000 );

				bindSaveAndSubmitGuards( editor );
				scanForLockButtons( document.body );
			} );

			editor.on( 'OpenWindow', function () {
				window.setTimeout( function () {
					scanForLockButtons( document.body );
				}, 50 );
				window.setTimeout( function () {
					scanForLockButtons( document.body );
				}, 200 );
			} );

			editor.on( 'BeforeSetContent', function ( e ) {
				if ( e.content && typeof e.content === 'string' ) {
					// Se il contenuto è stato escapato in entità HTML (es. inizia con &lt;p o &lt;iframe o &lt;div)
					if ( /^\s*&lt;(?:p|div|table|ul|ol|h[1-6]|blockquote|iframe|section|article|a|em|strong|img)/i.test( e.content ) ) {
						e.content = decodeEscapedHtml( e.content );
					}

					// Se il contenuto contiene nuove righe (\n) ma non è strutturato in tag a blocchi (<p>),
					// applichiamo wpautop per preservare paragrafi, spazi e a capo (solo se non contiene già tag di blocco)
					if ( e.content.indexOf( '\n' ) !== -1 ) {
						var hasBlock = /<\/?(?:p|div|table|ul|ol|h[1-6]|blockquote|iframe|section|article)/i.test( e.content );
						if ( ! hasBlock ) {
							e.content = wpautop( e.content );
						}
					}

					// Corregge link senza schema
					if ( e.content.indexOf( '<a ' ) !== -1 || e.content.indexOf( '<a\t' ) !== -1 ) {
						e.content = e.content.replace( /(<a\s+[^>]*?href\s*=\s*["'])([^"']+)(["'][^>]*?>)/gi, function ( match, prefix, href, suffix ) {
							var trimmed = ( href || '' ).trim();
							if ( ! trimmed || /^[a-z][a-z0-9+.-]*:/i.test( trimmed ) || /^[\/#!.?]/i.test( trimmed ) ) {
								return match;
							}
							return prefix + 'https://' + trimmed + suffix;
						} );
					}
				}
			} );

			editor.on( 'SaveContent', function ( e ) {
				if ( ! e.content || ( e.content.indexOf( '<a ' ) === -1 && e.content.indexOf( '<a\t' ) === -1 ) ) {
					return;
				}
				e.content = e.content.replace( /(<a\s+[^>]*?href\s*=\s*["'])([^"']+)(["'][^>]*?>)/gi, function ( match, prefix, href, suffix ) {
					var trimmed = ( href || '' ).trim();
					if ( ! trimmed || /^[a-z][a-z0-9+.-]*:/i.test( trimmed ) || /^[\/#!.?]/i.test( trimmed ) ) {
						return match;
					}
					return prefix + 'https://' + trimmed + suffix;
				} );
			} );

			editor.on( 'focus', function () {
				window.wpActiveEditor = editor.id;
			} );

			if ( typeof originalSetup === 'function' ) {
				originalSetup( editor );
			}
		};

		return config;
	}

	function wrapWpEditor( editorObj ) {
		if ( ! editorObj || editorObj._modernMceWrapped ) {
			return;
		}
		var originalInit = editorObj.initialize;
		if ( typeof originalInit === 'function' ) {
			editorObj.initialize = function ( id, userSettings ) {
				userSettings = userSettings || {};
				if ( userSettings.tinymce ) {
					delete userSettings.tinymce.toolbar1;
					delete userSettings.tinymce.toolbar2;
					delete userSettings.tinymce.toolbar3;
					delete userSettings.tinymce.toolbar4;
					userSettings.tinymce.toolbar = toolbar;
				}
				return originalInit.call( this, id, userSettings );
			};
		}
		editorObj._modernMceWrapped = true;
	}

	// Shim e wrapper per window.wp.oldEditor e window.wp.editor usati da Gutenberg e dai blocchi "Editor classico"
	if ( typeof window.wp === 'undefined' ) {
		window.wp = {};
	}
	if ( typeof window.wp.oldEditor === 'undefined' ) {
		window.wp.oldEditor = {
			initialize: function ( id, userSettings ) {
				userSettings = userSettings || {};
				var config = Object.assign( {}, userSettings.tinymce || userSettings );
				config.id = id;
				if ( ! config.target && ! config.selector && id ) {
					var el = document.getElementById( id );
					if ( el ) {
						if ( el.value && typeof el.value === 'string' ) {
							if ( /^\s*&lt;(?:p|div|table|ul|ol|h[1-6]|blockquote|iframe|section|article|a|em|strong|img)/i.test( el.value ) ) {
								el.value = decodeEscapedHtml( el.value );
							}
						}
						config.target = el;
					} else {
						config.selector = '#' + id;
					}
				}
				return tinymce.init( config );
			},
			remove: function ( id ) {
				if ( window.tinymce ) {
					var ed = window.tinymce.get( id );
					if ( ed ) {
						ed.remove();
					}
				}
			},
			get: function ( id ) {
				return window.tinymce ? window.tinymce.get( id ) : null;
			},
			getContent: function ( id ) {
				if ( window.tinymce ) {
					var ed = window.tinymce.get( id );
					if ( ed ) {
						return ed.getContent();
					}
				}
				var el = document.getElementById( id );
				return el ? el.value : '';
			},
			setContent: function ( id, content ) {
				if ( typeof content === 'string' ) {
					if ( /^\s*&lt;(?:p|div|table|ul|ol|h[1-6]|blockquote|iframe|section|article|a|em|strong|img)/i.test( content ) ) {
						content = decodeEscapedHtml( content );
					}
				}
				if ( window.tinymce ) {
					var ed = window.tinymce.get( id );
					if ( ed ) {
						return ed.setContent( content );
					}
				}
				var el = document.getElementById( id );
				if ( el ) {
					el.value = content;
				}
			}
		};
	} else {
		wrapWpEditor( window.wp.oldEditor );
	}

	if ( typeof window.wp.editor === 'undefined' ) {
		window.wp.editor = window.wp.oldEditor;
	} else {
		wrapWpEditor( window.wp.editor );
	}

	// Intercetta tinymce.init per garantire che qualsiasi inizializzazione
	// (anche dai blocchi Gutenberg) sia sanificata per TinyMCE moderno
	var rawInit = tinymce.init;
	tinymce.init = function ( config ) {
		var sanitized = sanitizeConfig( config || {} );
		return rawInit.call( tinymce, sanitized );
	};

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
		if ( textarea && textarea.value && typeof textarea.value === 'string' ) {
			if ( /^\s*&lt;(?:p|div|table|ul|ol|h[1-6]|blockquote|iframe|section|article|a|em|strong|img)/i.test( textarea.value ) ) {
				textarea.value = decodeEscapedHtml( textarea.value );
			}
		}
		if ( textarea.id ) {
			initialConfigs[ textarea.id ] = { target: textarea };
		}
		tinymce.init( { target: textarea } );
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
				scanForLockButtons( document.body );
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	// Aggiorna live il tema se l'utente cambia preferenza di sistema
	// e l'impostazione del plugin è "system".
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
