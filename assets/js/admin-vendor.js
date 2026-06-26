/**
 * Gestione AJAX dei bottoni "Controlla aggiornamenti" e
 * "Scarica e installa" nella pagina impostazioni del plugin,
 * per la sorgente locale (offline) di TinyMCE.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var settings = window.mceVendorSettings || {};
		var checkBtn    = document.getElementById( 'mce-check-update-btn' );
		var downloadBtn = document.getElementById( 'mce-download-update-btn' );
		var spinner     = document.getElementById( 'mce-vendor-spinner' );
		var messageEl   = document.getElementById( 'mce-vendor-message' );
		var latestInfoEl = document.getElementById( 'mce-latest-version-info' );
		var activeVersionEl = document.getElementById( 'mce-active-version' );
		var activeSourceEl  = document.getElementById( 'mce-active-source' );

		if ( ! checkBtn || ! downloadBtn ) {
			return;
		}

		var latestVersionFound = null;

		function setBusy( isBusy ) {
			checkBtn.disabled = isBusy;
			downloadBtn.disabled = isBusy;
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
				if ( activeVersionEl ) {
					activeVersionEl.textContent = data.data.version;
				}
				if ( activeSourceEl ) {
					activeSourceEl.textContent = activeSourceEl.dataset.downloadedLabel || activeSourceEl.textContent;
				}
			} ).catch( function () {
				setBusy( false );
				setMessage( settings.i18n.genericError, true );
			} );
		} );
	} );
} )();
