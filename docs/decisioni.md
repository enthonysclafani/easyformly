# Decisioni

Tutte le decisioni sugli standard di EasyFormly, aperte e prese, con data e perché. Prima di proporre qualcosa
controlla che non sia già stato deciso o scartato; davanti a una decisione aperta fermati e chiedi a Enthony: non si
sceglie da soli.

Le decisioni D1–D8 vengono da `docs/piano-standard.md` (sezione 3), dove sono nate; da qui in avanti si aggiornano
solo in questo file. Quando una decisione viene presa: si scrive la data e il motivo, si aggiorna `CLAUDE.md`
(invarianti) e, se cambia un'eccezione, l'allowlist in `tools/check-architecture.php`.

## Riepilogo

| | Tema | Stato | Cosa resta |
|---|---|---|---|
| D1 | Cron | presa | Fase 4.1, `CRON_ALLOWLIST` |
| D2 | Asset nel frontend | presa (già così) | `ASSET_ALLOWLIST` |
| D3 | Chiamate HTTP | presa | Fase 4.3, `HTTP_ALLOWLIST` |
| D4 | Dipendenze PHP a runtime | presa | Fase 3, `includes/vendor/` |
| D5 | Disinstallazione | presa (già così) | Fase 2.3 |
| D6 | Lock atomici in `wp_options` | presa | Fase 2.2, `WPDB_ALLOWLIST` |
| D7 | Requisiti minimi | presa (già così) | |
| D8 | Nomi dei dati | presa | |
| D9 | Lingua delle stringhe sorgente | presa, fatta | Fase 5.3, `languages/easyformly-it_IT.po` |
| D10 | Nuovo nome: EasyFormly | presa (default, da confermare) | |

## Prese

Il 13 ottobre 2026 Enthony ha approvato tutte le proposte qui sotto così come sono scritte. Per D1 vale la
variante con l'eccezione dichiarata: le pulizie diventano pigre e a lotti senza cron, e resta un solo evento singolo,
`easycontact_retry_delivery`, per i nuovi tentativi di consegna. Il motivo comune: quasi tutte descrivono il
comportamento attuale, e le altre tolgono codice o rischi senza cambiare ciò che vede l'utente.

Sotto ogni decisione la riga "Decisione" dice cosa è stato scelto e cosa resta da fare.

**D1 — Cron.** EasyRankly vieta ogni cron. EasyFormly lo usa per pulizie e per ritentare le consegne fallite.
- Stato attuale: `easycontact_daily_cleanup` (giornaliero), `easycontact_retention_cleanup`,
  `easycontact_state_cleanup`, `easycontact_retry_delivery`, `easycontact_resume_retries` (singoli). I file che li
  pianificano sono in `CRON_ALLOWLIST` con `TODO D1`.
- Proposta: le pulizie (retention, stati scaduti) diventano pigre e a lotti su `admin_init` e dopo un invio, con
  il cursore in un'option. Per i nuovi tentativi verso MailerLite/Slack/email: tenere **un solo** evento singolo
  `easycontact_retry_delivery` come eccezione dichiarata, oppure toglierlo e lasciare solo il pulsante "Riprova"
  in admin (più semplice, ma un invio fallito resta fermo finché qualcuno non entra).
- Nota dall'analisi: per un modulo di contatto un invio fallito che resta fermo è una perdita di contatti. La
  variante con l'eccezione dichiarata sembra la più sicura per la produzione.
- Decisione (13 ottobre 2026): proposta approvata, variante con l'eccezione. Retention e pulizia di lock e stati
  senza cron, a lotti su `admin_init` e dopo un invio; `easycontact_retry_delivery` resta come unico evento, eccezione
  dichiarata in `CRON_ALLOWLIST`. Fatto nella Fase 4.1: `Maintenance` con il cursore in `easycontact_maintenance`.

**D2 — Asset nel frontend.** Un plugin di moduli non può avere zero CSS/JS.
- Stato attuale: già conforme alla proposta. `assets/frontend.css` e `assets/frontend.js` si caricano solo quando
  si stampa un modulo; `tests/QueriesTest.php` verifica che una pagina senza modulo non carichi nulla. Il modulo
  funziona senza JS (invio via `admin-post.php`).
- Proposta: regola riformulata come "asset solo sulle pagine che stampano un modulo (enqueue al rendering),
  nessun JS inline, il modulo funziona anche senza JS". Turnstile solo sui moduli che lo attivano.
  `composer architecture` verifica che l'enqueue stia solo in `src/Forms/Renderer.php` e `src/Admin/Admin.php`.
- Decisione (13 ottobre 2026): proposta approvata. Il comportamento attuale la rispetta già.

**D3 — Chiamate HTTP.** EasyRankly: mai nel frontend, solo admin/REST/WP-CLI.
- Stato attuale: chiamate solo nella richiesta di invio (Turnstile, MailerLite, Slack, DNS) e in admin
  (sincronizzazione manuale dei domini usa e getta), con `wp_safe_remote_*`/`wp_remote_*`. `HTTP_ALLOWLIST`:
  `src/Delivery/Delivery.php`, `src/AntiSpam/DisposableDomains.php`.
- Proposta: "mai durante il rendering di una pagina; solo nella richiesta di invio (REST o `admin-post.php`) e in
  admin", con timeout espliciti (≤ 15 s), gestione di `WP_Error` e dei codici HTTP, servizi elencati in
  "External services" di `readme.txt`. I controlli DNS restano opzionali e con cache in transient.
- Decisione (13 ottobre 2026): proposta approvata. Da fare: Fase 4.3 (timeout verificati, "External services" in
  `readme.txt`).

**D4 — Dipendenze PHP a runtime.** EasyFormly distribuisce libphonenumber (centinaia di file di metadati) e il
polyfill di mbstring.
- Proposta: polyfill mbstring rimosso se i requisiti lo permettono (verificare l'uso effettivo); libphonenumber
  tenuto come **unica eccezione dichiarata**, isolato in `includes/vendor/phone/`, escluso da PHPCS e PHPStan,
  aggiornato solo con `tools/bundle-phone.php`, licenza in `THIRD-PARTY.md`. Alternativa: validazione telefonica
  semplificata senza libreria (meno precisa).
- Nota dall'analisi: `Phone::boot()` carica il polyfill solo se `mb_strtoupper`/`mb_strtolower` mancano; dichiarare
  `ext-mbstring` come requisito permetterebbe di toglierlo.
- Decisione (13 ottobre 2026): proposta approvata. libphonenumber resta come unica eccezione dichiarata; il polyfill
  mbstring si toglie se `ext-mbstring` diventa requisito dopo la verifica dell'uso effettivo (Fase 3).

**D5 — Disinstallazione.** EasyRankly rimuove sempre tutto; EasyFormly conserva moduli e invii salvo opt-in.
- Stato attuale: già come la proposta. `uninstall.php` rimuove sempre i dati tecnici e, solo con
  `delete_on_uninstall`, moduli, invii, categorie e impostazioni, su ogni sito della rete.
  `tests/UninstallTest.php` copre entrambi i casi e il multisite.
- Proposta: tenere l'opt-in per moduli e invii (sono dati dell'utente, perderli per errore è grave), ma rimuovere
  **sempre** i dati tecnici.
- Decisione (13 ottobre 2026): proposta approvata. Il comportamento attuale la rispetta già ed è coperto da
  `tests/UninstallTest.php`.

**D6 — Lock atomici in `wp_options`.** `State::claim()` usa `INSERT IGNORE` per evitare consegne doppie in
richieste concorrenti.
- Proposta: tenerlo come unico file in allowlist `$wpdb` (con il commento già presente), ma solo per i lock; i
  contatori di rate limit passano a transient/`wp_cache_add()` dove l'atomicità non è critica. Valutare se
  `wp_cache_add()` basta quando c'è una cache persistente.
- Decisione (13 ottobre 2026): proposta approvata. `src/Submissions/State.php` resta in `WPDB_ALLOWLIST` solo per i lock; i
  contatori di rate limit passano a transient/`wp_cache_add()` (Fase 2.2).

**D7 — Requisiti minimi.** Oggi PHP 8.1 / WordPress 6.4; EasyRankly PHP 8.0 / WordPress 7.0.
- Dati: la suite PHPUnit passa su WordPress 6.4.9 e 7.1.0 (13 ottobre 2026); la CI la esegue su entrambe.
- Proposta: tenere PHP 8.1 e WordPress 6.4, ora che il minimo è davvero testato in CI.
- Decisione (13 ottobre 2026): PHP 8.1 e WordPress 6.4, testati in CI.

**D8 — Nomi dei dati.** Post type, option e meta sono già prefissati `easycontact_`. Rinominarli richiede una
migrazione.
- Proposta: **nessuna rinomina**; si documentano così come sono (`docs/data-model.md`).
- Decisione (13 ottobre 2026): nessuna rinomina.

**D9 — Lingua delle stringhe sorgente.** EasyRankly vuole codice, commenti e stringhe sorgente in inglese, tradotte
con il text domain. In EasyFormly codice e commenti sono in inglese, ma le stringhe dell'interfaccia sono scritte in
italiano dentro `__()`.
- Effetto: su un sito in inglese o in un'altra lingua l'interfaccia resta in italiano finché non esiste una
  traduzione da italiano; WordPress.org e i traduttori si aspettano sorgenti in inglese.
- Proposta: portare le stringhe sorgente in inglese e aggiungere `languages/easyformly-it_IT.po`/`.mo` con il testo
  italiano di oggi, così i siti italiani non vedono cambiamenti. Va fatto in un PR a parte (Fase 5.3), con test che
  confrontano le stringhe principali.
- Decisione (13 ottobre 2026): proposta approvata. Stringhe sorgente in inglese più traduzione `it_IT` con il testo di
  oggi, in un PR a parte (Fase 5.3). Fino ad allora le nuove stringhe seguono quelle vicine.

**D10 — Nuovo nome: EasyFormly.** Il 15 ottobre 2026 Enthony ha chiesto di rinominare il plugin "EasyFormly".
- Problema: i siti in produzione hanno la 1.0.0 come `easycontact/easycontact.php`, con dati, shortcode nelle pagine e
  HTML del modulo nelle cache delle pagine che usano i nomi `easycontact`.
- Scelta: cambia tutto ciò che è nome del plugin o del codice; resta tutto ciò che WordPress salva o che esce dal
  PHP verso pagine già pubblicate.
  - Cambiano: nome mostrato (intestazione, menu, titoli delle schermate, guida privacy), cartella e file principale
    (`easyformly/easyformly.php`, zip `easyformly.zip`), text domain `easyformly` e file in `languages/`, namespace
    PHP `EasyFormly` (anche per le librerie incluse), costanti `EASYFORMLY_*`, pacchetti Composer, documenti.
  - Restano `easycontact` (D8 e compatibilità): post type, tassonomia, option, meta, transient, gruppo di cache,
    eventi cron, route REST `easycontact/v1`, azioni di `admin-post.php` e dei nonce, nomi dei campi, classi CSS,
    attributi `data-`, oggetti JS, handle degli asset, slug delle schermate admin, ID di export ed erase privacy.
    Le pagine in cache mandano ancora questi nomi, e i temi possono avere CSS sulle classi.
  - Shortcode: `[easyformly]` è quello mostrato nell'editor; `[easycontact]` resta valido, senza scadenza.
- Passaggio dalla 1.0.0: EasyFormly è un plugin con un'altra cartella, quindi si installa accanto e si attiva.
  - L'attivazione disattiva EasyContact sullo stesso sito (o sulla rete, se entrambi sono attivi sulla rete); se i
    due restano attivi insieme, EasyFormly non registra niente e lascia servire EasyContact finché un amministratore
    apre la bacheca, che lo disattiva. La disattivazione di EasyContact toglie la versione: la richiesta dopo rifà
    il setup e riprende i tentativi di consegna.
  - Eliminare EasyContact da Plugin esegue il suo `uninstall.php`, che legge le stesse impostazioni: con
    EasyFormly attivo, in quella richiesta "elimina i dati alla disinstallazione" risulta spento, quindi moduli,
    invii e impostazioni restano. I dati tecnici che toglie (versione, lock, ricevute, cache dei domini) li ricrea
    il setup. Test: `tests/RenameTest.php`, `tests/UninstallTest.php`.
- Repository: dal 15 ottobre 2026 il lavoro continua in `enthonysclafani/easyformly`, creato da Enthony, con la storia
  completa di `main` e `Refactory` copiata da `enthonysclafani/easycontact` senza riscriverla (PR #1–#25 sono là).
- Decisione (15 ottobre 2026): default scelto da Claude sulla richiesta di Enthony, da confermare nella pull
  request della rinomina.

## Aperte

Nessuna per ora.
