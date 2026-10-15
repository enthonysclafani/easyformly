# Piano: gli standard di EasyRankly in EasyFormly

> Documento storico: i nomi `easycontact` qui sotto sono quelli della 1.0.0. Dal 15 ottobre 2026 il plugin si
> chiama EasyFormly e tutti i nomi usano `easyformly` (D10).

Obiettivo: portare in EasyFormly gli standard di qualità di EasyRankly (branch `Refactory` di
`enthonysclafani/easyrankly`): stessi controlli automatici, stessa struttura, stessa disciplina sui dati,
**zero tabelle custom e zero accessi diretti al database non motivati**.

Questo documento è il punto di partenza delle sessioni di lavoro (anche nel cloud). Va letto per intero prima di
toccare il codice. Le decisioni aperte (sezione 3) si chiedono a Enthony: non si scelgono da soli.

Riferimento per ogni dubbio su "come lo fa EasyRankly":

```bash
git clone -b Refactory https://github.com/enthonysclafani/easyrankly.git /tmp/easyrankly
# CLAUDE.md, docs/, tools/check-architecture.php, tools/cloud-setup.sh, .github/workflows/ci.yml
```

Si copia il **metodo** e gli **strumenti**, non il codice delle funzioni SEO.

## 1. Stato di partenza (EasyContact 1.0.0, branch `main`)

- ~4.600 righe PHP in `includes/class-*.php` (namespace `EasyContact`, `require_once` a mano), più
  `includes/vendor/` (libphonenumber e polyfill mbstring) e `includes/data/` (paesi, domini usa e getta).
- Dati: post type `easycontact_form` ed `easycontact_entry`, tassonomia `easycontact_safety`, option
  `easycontact_settings`, `easycontact_version`, `easycontact_disposable_domains`,
  `easycontact_review_terms_version`, option dinamiche `easycontact_lock_*` ed `easycontact_state_*`, transient
  `easycontact_*`, meta `_easycontact_*`. **Nessuna tabella custom.**
- Accessi diretti a `$wpdb`:
  - `includes/class-state.php`: `INSERT IGNORE` in `wp_options` per lock atomici e contatori di rate limit;
  - `includes/class-submission.php:309`: query diretta su `postmeta` per gli invii da ritentare;
  - `uninstall.php`: `LIKE` su `wp_options` per trovare lock, stati e transient.
- Cron: `easycontact_daily_cleanup` (giornaliero), `easycontact_retention_cleanup`, `easycontact_state_cleanup`,
  `easycontact_retry_delivery`, `easycontact_resume_retries` (singoli).
- Frontend: `assets/frontend.css`, `assets/frontend.js`, script di Turnstile; invio via REST
  (`easycontact/v1/forms/{id}/submit`, `.../token`) e senza JS via `admin-post.php`.
- Chiamate remote all'invio: Turnstile, MailerLite, Slack, `wp_mail()`, `dns_get_record()` (controlli DNS).
- Admin: meta box classiche sul post type dei moduli, Settings API per le impostazioni, `assets/admin.css` e
  `assets/admin.js`, azioni via `admin_post_*`.
- Disinstallazione: rimuove i dati solo con l'opzione `delete_on_uninstall` attiva (default: conserva).
- Qualità: nessun `composer.json`, nessuna CI, nessun PHPCS/PHPStan; test in due script da lanciare con
  `studio wp eval-file`.

## 2. Standard di arrivo

Ripresi da `CLAUDE.md` di EasyRankly; quelli segnati con (D) dipendono da una decisione della sezione 3.

1. **Zero tabelle custom**: mai `CREATE TABLE`, `dbDelta()`, `ALTER TABLE`. Dati solo in Options API, meta,
   post type non pubblici, tassonomie nascoste.
2. **`$wpdb` solo in allowlist**: ogni file ammesso è elencato in `tools/check-architecture.php` con un commento
   che spiega perché le API non bastano; sempre `$wpdb->prepare()` con `%i` per gli identificatori.
3. **Cron** (D1).
4. **Asset nel frontend** (D2).
5. **Chiamate HTTP** (D3).
6. **Dipendenze PHP a runtime** (D4).
7. **Nessun sistema a moduli**: un plugin unico; le impostazioni spengono comportamenti, non caricano "addon".
8. **Struttura**: `src/` con autoload PSR-4 senza Composer, una cartella per funzionalità con una classe che
   espone `register(): void`, `Plugin::register()` con una riga per funzionalità, hook agganciati solo dentro
   `register()` (mai a livello di file né nel costruttore), codice admin in sottocartelle `Admin/`.
9. **API native**: `register_setting()` con schema, `register_post_meta()` con schema e `auth_callback`,
   `register_rest_route()` con `permission_callback` e `args` tipizzati, `WP_Query` invece di SQL,
   `wp_cache_*()` con invalidazione esplicita.
10. **Stile admin**: un solo foglio di stile, piccolo, con selettori sotto il wrapper del plugin; markup e classi
    del core; niente CSS inline.
11. **Sicurezza**: guardia `ABSPATH` su ogni file, capability sull'oggetto specifico, nonce nei form classici,
    sanitizzazione all'ingresso ed escape all'uscita, segreti mai in JS/REST/log, `phpcs:ignore` solo con
    motivazione nella stessa riga.
12. **Dati e ciclo di vita**: ogni dato registrato in un punto e documentato in `docs/data-model.md`;
    `uninstall.php` coperto da test, anche in multisite (D5).
13. **Prestazioni**: nessuna query in più nelle pagine senza modulo; autoload solo per dati piccoli e letti a ogni
    richiesta; niente lavoro su `init` oltre le registrazioni.
14. **Controlli**: `composer check` = architettura + PHPCS (WPCS `WordPress-Extra` e `WordPress-Docs`,
    PHPCompatibilityWP) + PHPStan livello 8 con `szepeviktor/phpstan-wordpress` + PHPUnit sulla suite di
    WordPress (singolo sito e multisite) + Plugin Check in CI.
15. **Definizione di "fatto"** come in EasyRankly: `composer check` verde, invarianti rispettate, comportamento
    visibile coperto da test di integrazione, `docs/data-model.md` e `uninstall.php` aggiornati, casella spuntata
    in questo piano, resoconto di cosa è stato verificato e cosa no.

## 3. Decisioni aperte (da chiedere prima della Fase 2)

Spostate in `docs/decisioni.md` (punto 1.3), con una nuova decisione D9 sulla lingua delle stringhe sorgente. Tutte
prese il 13 ottobre 2026 (proposte approvate; D1 con l'eccezione di `easycontact_retry_delivery`).

## 4. Fasi

Ogni punto = una pull request verso `Refactory`, con CI verde. Nessun cambio di comportamento visibile nelle
Fasi 0 e 1.

### Fase 0 — Strumenti (nessun cambio al codice del plugin)

- [x] 0.1 `composer.json` con soli strumenti di sviluppo (`require-dev`), `composer.lock` committato,
      script `architecture`, `lint`, `lint:fix`, `analyse`, `test`, `check`
- [x] 0.2 `phpcs.xml.dist` (WPCS Extra + Docs, PHPCompatibilityWP, text domain `easycontact`, esclusi
      `includes/vendor/`, `includes/data/`, `tools/phone-build/`)
- [x] 0.3 PHPStan livello 8 in `tools/phpstan/` (dipendenze separate, come in EasyRankly), `tools/analyse.sh`
- [x] 0.4 PHPUnit sulla suite di WordPress: `phpunit.xml.dist`, `tests/bootstrap.php`,
      `tools/install-wp-tests.sh`; i casi di `tests/integration.php` e `tests/disposable-domains.php`
      convertiti in test PHPUnit
- [x] 0.5 `tools/check-architecture.php` adattato (con test sui casi vietati e consentiti in
      `tests/fixtures/architecture/`), inizialmente con l'allowlist dello stato attuale e un `TODO` per ogni
      eccezione da eliminare nelle fasi successive
- [x] 0.6 CI GitHub (`.github/workflows/ci.yml`): statici su PHP minimo e 8.4, PHPUnit su WordPress minimo e
      ultimo, anche multisite, Plugin Check; azioni fissate per SHA; `.github/dependabot.yml`
- [x] 0.7 Sessioni cloud: `tools/cloud-setup.sh` e hook `SessionStart` in `.claude/settings.json`
      (Composer, MariaDB, suite di test di WordPress, sorgenti del core in `/tmp/wordpress-develop`)
- [x] 0.8 `.editorconfig`, `.gitattributes` (strumenti di sviluppo esclusi dal pacchetto), `tools/build-dist.sh`
- [x] 0.9 Primo run della CI: si registra quanti errori PHPCS/PHPStan ci sono (baseline), senza correggerli qui
      _PHPCS: 264 errori e 210 avvisi all'inizio, portati a zero con sole modifiche di stile e docblock (13 ottobre
      2026). PHPStan livello 8: 159 errori al primo run della CI, quasi tutti tipi mancanti negli array
      (`missingType.iterableValue`) più qualche `string|false` non controllato. Con `phpVersion` fissato a 8.1 la
      baseline generata dalla CI conta 165 errori in `phpstan-baseline.neon`; da qui PHPStan blocca ogni errore nuovo._

### Fase 1 — Regole e documentazione

- [x] 1.1 `CLAUDE.md` (e `AGENTS.md` che lo richiama) sul modello di EasyRankly, con le invarianti decise in D1–D8
      _Fatto: invarianti aggiornate con le decisioni D1–D9 del 13 ottobre 2026._
- [x] 1.2 `docs/data-model.md`: ogni option, meta, post type, tassonomia, transient, cron e chiave dinamica,
      con chi li scrive, autoload e rimozione
- [x] 1.3 `docs/decisioni.md`: decisioni D1–D8 con data e motivo (spostate da questo documento)
- [x] 1.4 Subagenti in `.claude/agents/` (ricercatore, esecutore, verificatore) adattati

### Fase 2 — Database: zero SQL non motivato

- [x] 2.1 `class-submission.php:309`: query su `postmeta` sostituita da `WP_Query` con `meta_query`
      (`fields => ids`, `no_found_rows`), test di regressione sul riavvio dei nuovi tentativi
      _Fatto con paginazione per chiave (`ID >` cursore) e `tests/RetryResumeTest.php`, verde con il codice vecchio e
      con il nuovo._
- [x] 2.2 Lock e rate limit secondo D6; allowlist `$wpdb` ridotta al minimo deciso
      _Con una cache a oggetti persistente i contatori usano `wp_cache_add()`/`wp_cache_incr()`, atomici e senza
      lock né scritture nel database; senza, resta il lock breve, perché lì un transient da solo non è atomico
      (`tests/RateLimitTest.php`)._
- [x] 2.3 `uninstall.php` secondo D5; ricerca delle chiavi dinamiche ridotta (se possibile, chiavi tracciate in
      un'option indice invece del `LIKE`); `tests/UninstallTest.php` singolo sito e multisite
      _Il test esiste già (Fase 0) e il comportamento coincide con D5. Il `LIKE` resta: gira una volta sola, mentre
      un'option indice costerebbe una scrittura a ogni invio. Motivo scritto nell'allowlist._
- [x] 2.4 `composer architecture` blocca `CREATE TABLE`, `dbDelta`, `ALTER TABLE` e `$wpdb` fuori allowlist

### Fase 3 — Struttura

- [x] 3.1 `src/autoload.php` PSR-4, `src/Plugin.php` con `register()`; bootstrap in `easyformly.php` ridotto
- [x] 3.2 Una cartella per funzionalità (ipotesi: `Forms/`, `Submissions/`, `Delivery/`, `AntiSpam/`, `Phone/`,
      `Review/`, `Privacy/`, `Settings/`), classi admin in `*/Admin/`; un PR per funzionalità, comportamento
      invariato e coperto dai test della Fase 0
      _Fatto: `Phone/`, `Privacy/`, `AntiSpam/`, `Delivery/`, `Review/` (con `Review/Admin/Screen.php`),
      `Settings/` (con `Settings/Admin/Page.php`), `Forms/` (definizione, `Schema`, `Renderer`,
      `Admin/Editor.php`), `Submissions/` (`Submission`, `Endpoint`, `Entries`, `State`,
      `Admin/EntryScreen.php`) e `Admin/Admin.php` per menu, asset e pagina di supporto condivisi. In `Plugin`
      restano post type e attivazione; la retention `cleanup()` è passata a `Submissions/Entries.php` con la
      Fase 4.1._
- [x] 3.3 Hook solo dentro `register()`; niente metodi statici usati come registro globale dove non serve
      _Fatto: `Plugin::register()` chiama il `boot()` di ogni funzionalità; la regola `hooks-in-register` di
      `composer architecture` vieta `add_action()`, `add_filter()` e `add_shortcode()` a livello di file in
      `src/`, come in EasyRankly. Restano un filtro `posts_where` aggiunto e tolto intorno a una sola query in
      `Submission::resume()` (paginazione per ID) e la cache per richiesta `DisposableDomains::$lists`, che evita
      di rileggere fino a 8 MB per ogni controllo: nessun registro globale._
- [x] 3.4 `register_setting()` con schema completo per `easycontact_settings`; `register_post_meta()` con schema
      e `auth_callback` per i meta dei moduli
      _Fatto: `Settings::schema()` descrive ogni chiave (tipo, limiti, default) e `Page::sanitize_settings()` lo
      verifica chiave per chiave prima di salvare; l'option resta fuori dal REST perché contiene il token di
      MailerLite e la chiave segreta di Turnstile (diversamente da EasyRankly). `Forms::register_meta()` registra
      `_easycontact_definition` e `_easycontact_last_delivery` su `init`. Test: `tests/SettingsTest.php`._
- [x] 3.5 REST: `args` con `type`, `sanitize_callback`, `validate_callback` per le route pubbliche; motivazione
      scritta del `__return_true` (moduli pubblici) e test con utente anonimo
      _Fatto: `Endpoint::routes()` dichiara `id` (intero ≥ 1) e i parametri del corpo (stringhe con lunghezza
      massima, oggetti per campi, paesi e honeypot) con `rest_validate_request_arg` e `rest_sanitize_request_arg`;
      un tipo sbagliato riceve 400 prima del callback. Nonce, ticket, honeypot e orologio restano verificati da
      `submit()`, comune all'invio classico. Test con utente anonimo in `tests/RestTest.php`._

### Fase 4 — Cron, asset, HTTP (secondo D1–D3)

- [x] 4.1 Pulizie senza cron (o con l'eccezione decisa), idempotenti e a lotti; test
      _Fatto: `src/Submissions/Maintenance.php` esegue retention (`Entries::cleanup()`), pulizia di lock e stati e
      ripresa dei tentativi su `admin_init` e alla fine di una richiesta con un invio, sotto un lock, al massimo una
      volta al giorno a giro finito; i cursori stanno in `easycontact_maintenance` (non autoload). Resta solo
      `easycontact_retry_delivery`; il setup toglie gli eventi della 1.0.0 quando cambia `EASYFORMLY_VERSION`, quindi
      il rilascio deve aumentare la versione. `State.php` esce dall'allowlist del cron. Test:
      `tests/MaintenanceTest.php`._
- [x] 4.2 Asset del frontend caricati solo con un modulo nella pagina; test che una pagina senza modulo non
      carichi né CSS né JS del plugin e non faccia query in più (`tests/QueriesTest.php`)
      _Fatto: `tests/QueriesTest.php` verifica che una pagina senza modulo non carichi asset e che `init` non faccia
      query; nel frontend il plugin aggancia solo lo shortcode, le route REST e `admin-post.php`. Le tre letture in
      più a ogni richiesta sono state tolte nella Fase 0. Il controllo con Query Monitor sul sito Studio resta da
      fare in locale._
- [x] 4.3 Chiamate remote solo nella richiesta di invio e in admin, timeout ≤ 15 s, "External services" in
      `readme.txt`; test con risposte HTTP simulate (`pre_http_request`)
      _Fatto: `tests/HttpTest.php` verifica che ogni chiamata (Turnstile, MailerLite, Slack, liste dei domini) abbia
      timeout tra 1 e 15 s, nessun redirect, risposta limitata e solo host pubblici; che stampare un modulo, anche
      con CAPTCHA, non faccia richieste; che ogni risposta (successo, errore HTTP, corpo non valido, rete
      irraggiungibile) dia un risultato definito. Le uniche chiamate fuori da invio e admin sono i nuovi tentativi
      di `easycontact_retry_delivery` (D1). "External services" in `readme.txt` è verificato sulle fonti
      ufficiali._

### Fase 5 — Admin

- [x] 5.1 Un solo foglio di stile admin (regola `one-stylesheet` come in EasyRankly), niente CSS inline
      _Fatto: la regola `one-stylesheet` di `tools/check-architecture.php` ammette solo `assets/admin.css` e
      `assets/frontend.css` e vuole ogni selettore sotto una classe o un ID `easycontact`; `no-inline-css` c'era già.
      I due fogli rispettavano già la regola: nessun cambiamento visibile._
- [x] 5.2 Azioni `admin_post_*` con nonce e capability verificate da test; valutare il passaggio a REST dove
      serve interazione
      _Fatto: `tests/AdminActionsTest.php` elenca ogni azione `admin_post_*` del plugin e, per classificazione,
      approvazione, nuovo tentativo, anteprima e sincronizzazione delle liste, verifica che un editor, una richiesta
      senza nonce e una con il nonce di un altro oggetto vengano fermati da `wp_die()` senza effetti, e che
      l'amministratore con il nonce giusto ottenga l'effetto e il ritorno alla schermata. REST non serve: sono form
      classici con ricarica della pagina, come prevede la tabella delle API native in CLAUDE.md._
- [x] 5.3 Testi delle schermate brevi e coerenti
      _Fatto con D9: le stringhe sorgente sono in inglese, brevi e con le parole del core; il testo italiano di oggi è
      in `languages/easyformly-it_IT.po`/`.mo`, caricato da `Plugin::init()` solo quando il file per la lingua esiste,
      così un sito in italiano non vede cambiamenti. Anche le etichette del modulo predefinito e gli esempi della
      pagina Supporto seguono la lingua. `tests/I18nTest.php` verifica che ogni stringa sia tradotta, che un sito in
      italiano mostri i testi di prima e che il modulo predefinito resti valido in entrambe le lingue._

### Fase 6 — Controllo finale

- [x] 6.1 `composer check` verde senza baseline né `phpcs:ignore` non motivati
      _Fatto in quattro PR (AntiSpam, Phone, Admin e strumenti; Delivery, Privacy e Review; moduli; invii): i 159
      errori della baseline sono corretti nel codice e `phpstan-baseline.neon` non c'è più. I 34 `phpcs:ignore`
      nominano la regola e hanno la motivazione nella stessa riga._
- [x] 6.2 Controllo di sicurezza (lista sul modello di `docs/sicurezza.md` di EasyRankly): input dell'invio,
      upload (se presenti), segreti delle integrazioni, SSRF sui webhook, rate limit
      _Fatto in `docs/sicurezza.md`, area per area con il test che verifica ogni difesa. Il documento di EasyRankly
      non era raggiungibile, quindi la struttura segue le aree di questo punto. Aggiunti `tests/SecretsTest.php`
      (nessun segreto esce dal server) e il limite di 2048 caratteri sul token CAPTCHA nella route di invio._
- [ ] 6.3 Verifica manuale sul sito Studio locale (`~/Studio/form-a-caso`) con Query Monitor
- [ ] 6.4 Merge di `Refactory` in `main` e nuova versione

## 5. Regole di lavoro

- Branch base: `Refactory`. Ogni sessione lavora su un branch proprio e apre una pull request verso `Refactory`,
  mai verso `main`.
- Cambia il minimo: niente refactor non richiesti dal punto in corso.
- Prima di aggiungere una dipendenza, un hook pubblico, un'option o un meta: chiedi.
- Se un download o un comando viene bloccato dal proxy o dai permessi, non aggirarlo: riferisci e proponi opzioni.
- I problemi di sicurezza non corretti non vanno in issue, PR o commit (il repository è pubblico).
- Messaggi di commit in italiano, all'indicativo presente, che spiegano il perché.
