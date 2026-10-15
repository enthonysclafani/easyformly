# Modello dati

Ogni dato che il plugin salva è elencato qui. Se non è in questa pagina, il plugin non deve crearlo.
`uninstall.php` rimuove tutto ciò che è elencato, in multisite su ogni sito della rete, e `tests/UninstallTest.php` lo
verifica. Cosa si rimuove sempre e cosa solo con `delete_on_uninstall` dipende dalla decisione D5
(`docs/decisioni.md`); la colonna "Disinstallazione" descrive il comportamento attuale.

Regola: zero tabelle custom. Solo Options API, post meta, post type non pubblici, tassonomie nascoste e transient.
Tutti i nomi usano il prefisso `easyformly` (D10): la 1.0.0 con i nomi `easycontact` non è mai andata in produzione.

## Post type

| Nome | Contenuto | Visibilità | Disinstallazione |
|---|---|---|---|
| `easyformly_form` | Un modulo. La definizione sta nel meta `_easyformly_definition`. | Non pubblico, `show_ui` per `manage_options`, nessun REST del core. | Solo con opt-in |
| `easyformly_entry` | Un invio archiviato, stato `private`. | Non pubblico, sottomenu dei moduli, nessun REST del core. | Solo con opt-in |

## Tassonomia

| Nome | Oggetto | Contenuto | Disinstallazione |
|---|---|---|---|
| `easyformly_safety` | `easyformly_entry` | Categoria anti-spam, una per invio: termini `accepted`, `suspect`, `spam`. Nascosta (niente UI, REST, query var). Creati da `Review::create_terms()` al setup; `Review::write()` li ricrea se spariscono. | Solo con opt-in |

## Option

| Nome | Autoload | Contenuto | Disinstallazione |
|---|---|---|---|
| `easyformly_settings` | no | Impostazioni del sito, creata con i default all'attivazione e al setup (`Settings::defaults()`): `store_entries`, `retention_days` (30), `spam_retention_days` (14), `antispam_level` (2), `antispam_observe`, `quarantine_entries`, `quarantine_limit` (500), `email_dns_check`, `phone_country`, `spam_phrases`, `allow_erasure`, `delete_on_uninstall`, `mailerlite_token`, `turnstile_site_key`, `turnstile_secret`. Registrata con `register_setting()` e descritta da `Settings::schema()`, fuori dal REST perché contiene i segreti. | Solo con opt-in |
| `easyformly_version` | sì | Versione per cui è girato il setup. Se diversa da `EASYFORMLY_VERSION`, `Plugin::init()` rifà il setup (impostazioni mancanti, categorie, ripresa dei tentativi). La disattivazione la cancella. | Sempre |
| `easyformly_maintenance` | no | Cursori della manutenzione senza cron (`Maintenance`): `due` (timestamp della prossima esecuzione completa, una al giorno), `state` (ultimo `option_id` visto dalla pulizia di lock e stati, `null` a giro finito), `resume` (ultimo invio visto dalla ripresa dei tentativi, `null` se non c'è niente da riprendere). Letta solo in admin e dopo un invio. | Sempre |
| `easyformly_review_terms_version` | no | `1` quando le tre categorie esistono. Letta solo durante il setup. | Sempre |
| `easyformly_disposable_domains` | no | Ultima sincronizzazione completa della lista domini usa e getta: `version`, `domains` (testo, un dominio per riga, < 8 MB), `count` (≤ 250.000), `sources`, `updated_at`. Assente = lista inclusa nel plugin (`includes/data/`). Scritta solo dalla sincronizzazione manuale in admin. | Sempre |
| `easyformly_lock_{chiave}` | no | Lease atomico (`State::claim()`, `INSERT IGNORE`): `scadenza.uuid`. Chiavi: `submit_{ticket}`, `retry_{entry}` e `rate_{hash}` (lease di 5 secondi attorno a un contatore). Si libera alla fine dell'operazione; quelli scaduti li toglie `State::cleanup()`, chiamata da `Maintenance`. | Sempre |
| `easyformly_state_{ticket}` | no | Stato di un invio per renderlo idempotente (JSON): `fingerprint`, `input_fingerprint`, `hash` dei dati, `entry`, `attempts` (max 3), `outcomes`, `assessment`. Chiave = primi 40 caratteri dello SHA-256 del ticket. Scade e lo toglie `State::cleanup()`. | Sempre |

## Post meta

Sui moduli (`easyformly_form`):

| Chiave | Contenuto |
|---|---|
| `_easyformly_definition` | `template` (testo con i tag), `schema` (campi analizzati da `Schema::parse()`), `conditions` (regole dei campi condizionali), `config` (impostazioni del modulo, `Forms::config_defaults()`: destinatari, archivio, anti-spam, Turnstile, MailerLite, Slack con `slack_webhook`, messaggio di conferma). |
| `_easyformly_last_delivery` | Esito dell'ultima consegna, mostrato nell'elenco dei moduli. |

Sugli invii (`easyformly_entry`):

| Chiave | Contenuto |
|---|---|
| `_easyformly_form_id` | ID del modulo. |
| `_easyformly_values` | Valori dei campi visibili, già validati. |
| `_easyformly_labels` | Etichette dei campi al momento dell'invio. |
| `_easyformly_email` | Indirizzi email dell'invio (uno per meta), per esportazione e cancellazione dei dati personali. |
| `_easyformly_initializing` | Presente mentre l'invio si sta archiviando: blocca consegne e revisione. |
| `_easyformly_antispam` | Valutazione automatica (`AntiSpam::assess()`): `status`, `detected`, `score`, `level`, `observe`, `reasons`. Scritta una volta. |
| `_easyformly_review` | Ultima classificazione: `status`, `source` (`automatic` o `manual`), `time`, `reviewer`. |
| `_easyformly_review_history` | Ultime 10 classificazioni manuali. |
| `_easyformly_held` | `1` se le consegne sono sospese (sospetto, spam o revisione a metà), `0` altrimenti. |
| `_easyformly_delivery` | Esito per destinazione (`outcomes`: email, MailerLite, Slack). |
| `_easyformly_retry` | Nuovo tentativo in corso: `hash`, `attempts` (max 3), `expires`. |

Tutti i meta si cancellano con il loro post (quindi solo con opt-in). I due meta dei moduli sono registrati con
`register_post_meta()` (`Forms::register_meta()`): `single`, fuori dal REST, `auth_callback` che nega la modifica
dalle API generiche dei meta; senza `default`, quindi un meta assente si legge ancora come stringa vuota.

## Transient

| Nome | Durata | Contenuto |
|---|---|---|
| `easyformly_rate_{hash}` | finestra del limite (5 minuti di default) | Contatore di invii, token e tentativi per indirizzo e modulo. L'indirizzo non si salva: la chiave è un HMAC. Con una cache a oggetti persistente il contatore sta invece nel gruppo di cache `easyformly_rate` (chiave `rate_{hash}`, stessa scadenza) e non scrive nel database. |
| `easyformly_dns_{hash}` | 15 minuti (1 se l'esito è incerto) | Esito del controllo DNS sul dominio di un'email, se `email_dns_check` è attivo. |
| `easyformly_review_notice_{utente}` | 90 secondi | Messaggio da mostrare dopo una revisione. |
| `easyformly_disposable_notice_{utente}` | 1 minuto | Esito della sincronizzazione dei domini. |
| `easyformly_admin_error_{utente}_{modulo}` | 1 minuto | Errore di salvataggio di un modulo. |

Tutti rimossi alla disinstallazione (ricerca per prefisso in `uninstall.php`, poi `delete_transient()`).

## Eventi cron

Uno solo, per D1:

| Hook | Tipo | Cosa fa |
|---|---|---|
| `easyformly_retry_delivery` | singolo | Nuovo tentativo di consegna di un invio (argomento: ID). |

Retention, pulizia di lock e stati e ripresa dei tentativi dopo un aggiornamento non usano WP-Cron: le esegue
`Maintenance::run()` a lotti su `admin_init` e alla fine di una richiesta che ha elaborato un invio (vedi
`easyformly_maintenance`).

## Nonce, azioni e chiavi che non sono dati

Non salvano niente e non vanno rimossi: azioni dei nonce (`easyformly_editor_{id}`, `easyformly_preview_{id}`,
`easyformly_retry_{id}`, `easyformly_review_{id}`, `easyformly_submit_{sito}_{id}`,
`easyformly_sync_disposable_domains`), azioni di `admin-post.php` (`easyformly_submit`, `easyformly_preview`,
`easyformly_retry`, `easyformly_classify`, `easyformly_approve`, `easyformly_sync_disposable_domains`), lo
shortcode `[easyformly]` e le route REST
`easyformly/v1/forms/{id}/token` e `easyformly/v1/forms/{id}/submit`.
