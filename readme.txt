=== Modern Classic Editor ===
Contributors: PeopleInside
Tags: tinymce, classic editor, gutenberg, dark mode, wysiwyg
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Disattiva Gutenberg e usa una versione moderna di TinyMCE (v7), caricata via CDN oppure offline, con supporto dark mode e toolbar configurabile.

== Description ==

Modern Classic Editor risolve due problemi comuni:

1. **TinyMCE datato**: WordPress include internamente una versione di TinyMCE non aggiornata. Questo plugin la sostituisce con TinyMCE 7, caricato da CDN (jsDelivr) oppure interamente offline, sotto licenza GPL, senza bisogno di account o API key.
2. **Editor a blocchi non desiderato**: permette di disattivare Gutenberg per i tipi di contenuto che scegli (articoli, pagine, custom post type), ripristinando l'editor classico, senza interferire con il blocco nativo "Editor classico" quando lavori dentro Gutenberg.

Funzionalità principali:

* Toggle per disattivare Gutenberg, per singolo tipo di contenuto, senza rompere contenuti già pubblicati a blocchi
* TinyMCE 7 moderno, caricato da CDN pubblico oppure interamente offline (file inclusi nel plugin o scaricabili dalle impostazioni)
* Controllo manuale o automatico di nuove versioni di TinyMCE, con download e installazione in un clic
* Dark mode: automatica (segue il sistema), sempre chiara, o sempre scura
* Tre preset di toolbar: standard, estesa, completa
* Compatibile con il pulsante nativo "Aggiungi media" di WordPress
* Aggiornamenti del plugin stesso integrati nel meccanismo nativo di WordPress, tramite le release GitHub ufficiali

== Installation ==

1. Carica la cartella del plugin in `/wp-content/plugins/`
2. Attiva il plugin dal menu Plugin di WordPress
3. Vai su Impostazioni > Modern Classic Editor per configurare Gutenberg, dark mode e toolbar

== Frequently Asked Questions ==

= TinyMCE viene scaricato ogni volta dal CDN? =

Lo script viene caricato dal browser dell'utente che sta editando (con cache standard del browser/CDN), non dal server. Non ci sono limiti di utilizzo perché jsDelivr è un CDN pubblico gratuito, diverso dal servizio cloud a pagamento di Tiny.

= Funziona offline o su reti con restrizioni? =

Sì. Nelle impostazioni puoi scegliere "Locale (offline)" come sorgente dell'editor: in questo caso TinyMCE viene caricato dai file inclusi nel plugin (o da una versione più recente eventualmente scaricata), senza alcuna richiesta verso CDN esterni durante l'uso dell'editor. È la scelta consigliata per ambienti air-gapped, dietro firewall restrittivi, o con policy di sicurezza che bloccano script di terze parti.

= I file di TinyMCE offline sono legali da distribuire? =

Sì. TinyMCE è distribuito da Tiny Technologies sotto licenza GNU GPLv2 o successiva. I file inclusi nel plugin (e quelli scaricabili dalle impostazioni) provengono dal pacchetto ufficiale "tinymce" pubblicato su npm, senza alcuna modifica al codice. La licenza GPL viene dichiarata esplicitamente nell'inizializzazione dell'editor (`license_key: 'gpl'`).

= Il controllo aggiornamenti di TinyMCE contatta server esterni senza che io lo sappia? =

No. Il controllo automatico giornaliero è disattivato di default: va attivato esplicitamente dalle impostazioni. Puoi sempre controllare e scaricare manualmente una nuova versione con i bottoni dedicati, indipendentemente da questa opzione.

= Come vengono aggiornate le nuove versioni del plugin stesso (non di TinyMCE)? =

Il plugin si integra con il meccanismo nativo di aggiornamento dei plugin di WordPress: se è pubblicata una nuova release sul repository GitHub ufficiale (github.com/PeopleInside/wp-moderneditor), comparirà nella pagina Plugin con lo stesso avviso "Aggiornamento disponibile" e lo stesso bottone "Aggiorna ora" usati per i plugin della directory ufficiale di WordPress.org, ed è compatibile con gli aggiornamenti automatici dei plugin se li attivi dalla stessa pagina. Il controllo avviene in background, in HTTPS, al massimo ogni 12 ore (la stessa cadenza che WordPress usa già per tutti i plugin installati).

== Changelog ==

= 1.2.3 =
* Nuovo: il plugin controlla ora le nuove versioni pubblicate sul repository GitHub ufficiale e si integra con il meccanismo nativo di aggiornamento dei plugin di WordPress (stessa interfaccia "Aggiornamento disponibile" e bottone "Aggiorna ora" dei plugin della directory ufficiale; compatibile con gli auto-update automatici dei plugin).
* Fix: corretta la costante di versione interna del plugin, che non era allineata alla versione dichiarata nell'header e in questo changelog.

= 1.2.0 =
* Fix: lo spinner accanto a "Controlla aggiornamenti" non è più visibile in modo permanente; appare solo durante un controllo o un download in corso.
* Nuovo: supporto completo alla lingua inglese (file di traduzione en_US e en_GB inclusi in `languages/`).
* Modifica: il caricamento delle traduzioni è stato spostato dall'hook `plugins_loaded` a `init`, in linea con le raccomandazioni WordPress più recenti.

= 1.1.0 =
* Nuovo: sorgente dell'editor selezionabile tra CDN (jsDelivr) e Locale (offline), per ambienti senza accesso a CDN esterni.
* Nuovo: bundle TinyMCE 7.9.3 incluso direttamente nel plugin per l'uso offline immediato.
* Nuovo: controllo manuale e automatico (opzionale, disattivato di default) di nuove versioni di TinyMCE, con download e installazione in un clic dalle impostazioni.
* Fix: il CSS dell'editor a blocchi non viene più rimosso dal frontend per contenuti che contengono già blocchi Gutenberg salvati, anche quando l'editor classico è forzato per quel tipo di contenuto.
* Fix: gli script legacy di TinyMCE non vengono più svuotati quando si lavora in Gutenberg, così il blocco nativo "Editor classico" continua a funzionare correttamente.
* Modifica: "Disattiva Gutenberg" e i tipi di contenuto associati sono ora disattivati di default (opt-in), per evitare modifiche impreviste all'attivazione del plugin.

= 1.0.0 =
* Prima versione pubblica.
