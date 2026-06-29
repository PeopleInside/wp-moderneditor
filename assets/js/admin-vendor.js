/**
 * Gestione AJAX dei bottoni "Controlla aggiornamenti", "Scarica e
 * installa" ed "Elimina versione locale scaricata" nella pagina
 * impostazioni del plugin, per la sorgente locale (offline) di TinyMCE.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var settings = window.mceVendorSettings || {};
		var checkBtn    = document.getElementById( 'mce-check-update-btn' );
		var downloadBtn = document.getElementById( 'mce-download-update-btn' );
		var deleteBtn   = document.getElementById( 'mce-delete-local-btn' );
		var spinner     = document.getElementById( 'mce-vendor-spinner' );
		var messageEl   = document.getElementById( 'mce-vendor-message' );
		var latestInfoEl = document.getElementById( 'mce-latest-version-info' );
		var activeStatusTextEl = document.getElementById( 'mce-active-status-text' );
		var activeVersionEl = document.getElementById( 'mce-active-version' );
		var activeSourceEl  = document.getElementById( 'mce-active-source' );

		if ( ! checkBtn || ! downloadBtn ) {
			return;
		}

		var latestVersionFound = null;
		var sourceRadios = document.querySelectorAll( 'input[name="mce_settings[editor_source]"]' );

		function updateDeleteBtn() {
			if ( ! deleteBtn ) {
				return;
			}
			if ( settings.hasDownloaded ) {
				deleteBtn.style.display = '';
			} else {
				deleteBtn.style.display = 'none';
			}
		}

		// Visibilità iniziale e gestione dinamica del bottone
		updateDeleteBtn();

		function setBusy( isBusy ) {
			checkBtn.disabled = isBusy;
			downloadBtn.disabled = isBusy;
			if ( deleteBtn ) {
				deleteBtn.disabled = isBusy;
			}
			if ( spinner ) {
				spinner.classList.toggle( 'is-active', isBusy );
			}
		}

		function setMessage( text, isError ) {
			if ( ! messageEl ) {
				return;
			}
			messageEl.textContent = text || '';
			messageEl.style.color = isError ? '#b32d2e' : '';
		}

		function updateActiveStatus( version, source ) {
			if ( ! activeStatusTextEl ) {
				return;
			}
			if ( 'none' === source || ! version ) {
				activeStatusTextEl.textContent = 'Nessuna versione attualmente disponibile offline. L\'editor Classic userà automaticamente la CDN.';
				activeVersionEl = null;
				activeSourceEl = null;
			} else {
				var sourceLabel = 'bundled' === source ? 'incluse nel plugin' : ( activeSourceEl && activeSourceEl.dataset.downloadedLabel || 'scaricata' );
				activeStatusTextEl.innerHTML = 'Versione attualmente disponibile offline: <strong id="mce-active-version">' + escapeHtml( version ) + '</strong> (<span id="mce-active-source" data-downloaded-label="scaricata">' + escapeHtml( sourceLabel ) + '</span>)';
				// Re-bind elements since we recreated them
				activeVersionEl = document.getElementById( 'mce-active-version' );
				activeSourceEl = document.getElementById( 'mce-active-source' );
			}
		}

		function escapeHtml( string ) {
			return String( string ).replace( /[&<>"']/g, function ( s ) {
				return {
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;',
					'"': '&quot;',
					"'": '&#39;'
				}[ s ];
			} );
		}

		function ajaxPost( action, extraParams ) {
			var body = new URLSearchParams( Object.assign(
				{ action: action, nonce: settings.nonce },
				extraParams || {}
			) );

			return fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).then( function ( res ) {
				return res.json();
			} );
		}

		checkBtn.addEventListener( 'click', function () {
			setBusy( true );
			setMessage( settings.i18n.checking, false );
			downloadBtn.style.display = 'none';

			ajaxPost( 'mce_check_tinymce_version' ).then( function ( data ) {
				setBusy( false );

				if ( ! data.success ) {
					setMessage( ( data.data && data.data.message ) || settings.i18n.genericError, true );
					return;
				}

				latestVersionFound = data.data.latest_version;

				if ( data.data.has_update ) {
					if ( latestInfoEl ) {
						latestInfoEl.textContent = settings.i18n.updateAvailable + latestVersionFound;
					}
					downloadBtn.style.display = '';
					setMessage( '', false );
				} else {
					if ( latestInfoEl ) {
						latestInfoEl.textContent = settings.i18n.upToDate;
					}
					setMessage( '', false );
				}
			} ).catch( function () {
				setBusy( false );
				setMessage( settings.i18n.genericError, true );
			} );
		} );

		downloadBtn.addEventListener( 'click', function () {
			if ( ! latestVersionFound ) {
				return;
			}

			setBusy( true );
			setMessage( settings.i18n.downloading, false );

			ajaxPost( 'mce_download_tinymce', { version: latestVersionFound } ).then( function ( data ) {
				setBusy( false );

				if ( ! data.success ) {
					setMessage( ( data.data && data.data.message ) || settings.i18n.genericError, true );
					return;
				}

				setMessage( data.data.message, false );
				downloadBtn.style.display = 'none';
				if ( latestInfoEl ) {
					latestInfoEl.textContent = '';
				}
				updateActiveStatus( data.data.version, 'downloaded' );
				// Dopo un download riuscito esiste ora una versione locale scaricata
				settings.hasDownloaded = true;
				updateDeleteBtn();
			} ).catch( function () {
				setBusy( false );
				setMessage( settings.i18n.genericError, true );
			} );
		} );

		if ( deleteBtn ) {
			deleteBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( settings.i18n.confirmDelete ) ) {
					return;
				}

				setBusy( true );
				setMessage( settings.i18n.deleting, false );

				ajaxPost( 'mce_delete_tinymce' ).then( function ( data ) {
					setBusy( false );

					if ( ! data.success ) {
						setMessage( ( data.data && data.data.message ) || settings.i18n.genericError, true );
						return;
					}

					setMessage( data.data.message, false );
					updateActiveStatus( data.data.active_version, data.data.active_source );
					settings.hasDownloaded = ( 'none' !== data.data.active_source );
					updateDeleteBtn();
				} ).catch( function () {
					setBusy( false );
					setMessage( settings.i18n.genericError, true );
				} );
			} );
		}
	} );
} )();
