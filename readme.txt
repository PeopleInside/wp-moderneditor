=== Modern Classic Editor ===
Contributors: yourname
Tags: tinymce, classic editor, gutenberg, dark mode, wysiwyg
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Disattiva Gutenberg e usa una versione moderna di TinyMCE (v7), caricata via CDN, con supporto dark mode e toolbar configurabile.

== Description ==

Modern Classic Editor risolve due problemi comuni:

1. **TinyMCE datato**: WordPress include internamente una versione di TinyMCE non aggiornata. Questo plugin la sostituisce con TinyMCE 7, caricato da CDN (jsDelivr) sotto licenza GPL, senza bisogno di account o API key.
2. **Editor a blocchi non desiderato**: permette di disattivare Gutenberg per i tipi di contenuto che scegli (articoli, pagine, custom post type), ripristinando l'editor classico.

Funzionalità principali:

* Toggle per disattivare Gutenberg, per singolo tipo di contenuto
* TinyMCE 7 moderno, caricato da CDN pubblico (nessuna dipendenza da account Tiny Cloud)
* Dark mode: automatica (segue il sistema), sempre chiara, o sempre scura
* Tre preset di toolbar: standard, estesa, completa
* Compatibile con il pulsante nativo "Aggiungi media" di WordPress

== Installation ==

1. Carica la cartella del plugin in `/wp-content/plugins/`
2. Attiva il plugin dal menu Plugin di WordPress
3. Vai su Impostazioni > Modern Classic Editor per configurare Gutenberg, dark mode e toolbar

== Frequently Asked Questions ==

= TinyMCE viene scaricato ogni volta dal CDN? =

Lo script viene caricato dal browser dell'utente che sta editando (con cache standard del browser/CDN), non dal server. Non ci sono limiti di utilizzo perché jsDelivr è un CDN pubblico gratuito, diverso dal servizio cloud a pagamento di Tiny.

= Funziona offline o su reti con restrizioni? =

No: se il CDN non è raggiungibile, l'editor TinyMCE non si carica e la textarea resta semplice (nessun crash della pagina). Per ambienti completamente offline, valutare il self-hosting dei file TinyMCE.

== Changelog ==

= 1.0.0 =
* Prima versione pubblica.
