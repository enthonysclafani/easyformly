# Sicurezza

Controllo della Fase 6.2 (13 ottobre 2026): per ogni area, cosa fa il plugin e quale test lo verifica. Le regole
sono quelle della sezione "Sicurezza" di `CLAUDE.md`. Il documento di EasyRankly indicato dal piano come modello
(`docs/sicurezza.md`) non era raggiungibile: la struttura qui segue le aree elencate nel punto 6.2.

Il repository è pubblico: qui stanno solo le difese in funzione. Un problema non ancora corretto non va in questo
file, in una issue o in una PR finché la correzione non è pubblicata; la PR che lo corregge descrive il rinforzo.

## Superficie pubblica

Sono tre le porte che un visitatore anonimo può chiamare. Tutto il resto richiede `manage_options`.

| Porta | Cosa accetta | Verifica |
|---|---|---|
| `GET /easyformly/v1/forms/{id}/token` | Solo moduli pubblicati con una definizione valida; risposta con `Cache-Control: no-store` | `RestTest::test_token_is_issued_only_for_published_forms_and_never_cached` |
| `POST /easyformly/v1/forms/{id}/submit` | Argomenti tipizzati e con limiti; corpo fino a 50 KB | `RestTest::test_routes_declare_typed_args_and_refuse_malformed_parameters`, `RestTest::test_submission_refuses_missing_tokens_and_oversized_bodies` |
| `admin-post.php?action=easyformly_submit` (anche senza login) | Lo stesso percorso di validazione del REST, `submit()` | `SubmissionTest`, `RestTest` |

I post type del plugin non hanno route nel REST del core (`RestTest::test_forms_and_entries_are_not_exposed_by_core_routes`).

## Input dell'invio

- [x] Ticket firmato con HMAC e `wp_salt( 'auth' )`, legato a sito e modulo, valido un'ora.
- [x] Nonce per sito e modulo.
- [x] Honeypot con un nome diverso per ogni ticket.
- [x] Orologio firmato: un invio troppo rapido aumenta il punteggio anti-spam.
- [x] Limiti: fino a 80 campi, 40 000 byte di campi in JSON, 40 paesi, testo fino a 10 000 caratteri (512 per gli altri
      campi), token CAPTCHA fino a 2048 caratteri.
- [x] Idempotenza per ticket: lo stesso invio ripetuto restituisce la stessa ricevuta, un invio diverso con lo stesso
      ticket viene rifiutato.
- [x] I valori dei campi nascosti dalle condizioni non arrivano mai a email, archivio o integrazioni.
- [x] Sanitizzazione all'ingresso (`sanitize_text_field`, `sanitize_textarea_field`), escape all'uscita.

Verifica: `SubmissionTest` (normalizzazione, condizioni, consegna unica, ricevute senza dati personali),
`RestTest` (token, nonce, dimensioni).

## Upload

Il plugin non accetta file: nessun tipo di campo carica allegati.

## Segreti delle integrazioni

Token MailerLite, chiave segreta di Turnstile e webhook Slack.

- [x] L'option `easyformly_settings` è registrata senza REST (`SettingsTest::test_the_option_with_its_secrets_is_registered_without_rest`).
- [x] Il meta `_easyformly_definition`, che contiene il webhook, è privato e senza REST (`SettingsTest::test_form_meta_is_registered_private`).
- [x] Nelle schermate admin i segreti sono campi password vuoti con l'indicazione "già configurato".
- [x] Nessun segreto nella pagina del modulo, nella risposta del token o dell'invio, nell'email, nell'export privacy
      o nelle schermate admin: `SecretsTest::test_secrets_never_leave_the_server`.
- [x] Il token MailerLite viaggia solo nell'header `Authorization` (`HttpTest::test_mailerlite_answers_have_a_defined_result`).
- [x] Gli esiti delle consegne salvano solo codici di stato, mai le risposte dei servizi.

## SSRF e richieste HTTP

- [x] Il webhook Slack è accettato solo se è HTTPS su `hooks.slack.com` con il percorso `/services/…`, senza porta,
      credenziali, query o frammento (`Delivery::valid_slack_url()`).
- [x] Ogni richiesta usa `wp_safe_remote_*()`: timeout fino a 15 secondi, nessun redirect, risposta limitata
      (`HttpTest::test_every_outbound_request_is_bounded`).
- [x] Nessuna richiesta durante il rendering di una pagina (`HttpTest::test_rendering_a_form_makes_no_request`).
- [x] La sincronizzazione delle liste di domini usa e getta parte solo da un amministratore, in POST con nonce
      (`DisposableDomainsTest::test_synchronization_requires_an_administrator_post_and_nonce`).

## Rate limit

- [x] Tentativi e invii hanno un limite per indirizzo e modulo; i contatori salvano solo un HMAC dell'indirizzo
      (`RateLimitTest::test_rate_limit_refuses_after_the_limit`).
- [x] Con una cache a oggetti persistente i contatori sono atomici e non scrivono nel database; senza, usano un lock
      breve (`RateLimitTest`).
- [x] La quarantena ha un budget giornaliero (`SubmissionTest::test_suspicious_submissions_need_explicit_storage_and_a_daily_budget`).
- [x] Contatori per email, telefono e messaggio ripetuto, saturati a un massimo (`SubmissionTest::test_counters_and_rate_limits_stay_saturated`).

## Azioni amministrative

- [x] Ogni azione su `admin-post.php` richiede `manage_options` e il nonce dello stesso oggetto; il test elenca tutte
      le azioni registrate, così una nuova azione senza test lo fa fallire (`AdminActionsTest`).
- [x] Le azioni di gruppo convertono gli ID e ne elaborano al massimo cento.
- [x] Nessuna consegna doppia (lock `retry_{entry}`) e nessuna consegna di un invio sospeso senza approvazione manuale
      (`SubmissionTest::test_spam_is_held_until_an_administrator_approves_it`, `AdminActionsTest`).

## Privacy

- [x] Export e cancellazione con gli strumenti di WordPress, a lotti di 50 (`SubmissionTest::test_privacy_tools_bulk_review_and_retention`).
- [x] Le ricevute degli invii non contengono dati personali (`SubmissionTest::test_clean_submission_is_delivered_once_and_receipts_hold_no_contact_data`).

## Codice

- [x] Ogni file PHP inizia con `defined( 'ABSPATH' ) || exit;`, niente funzioni vietate, `$wpdb` solo in due file
      con `prepare()`, nessuna scrittura su disco: `composer architecture`.
- [x] PHPCS con le regole di sicurezza di WordPress senza errori; ogni `phpcs:ignore` nomina la regola e ha la
      motivazione nella stessa riga.
- [x] PHPStan livello 8 senza errori e senza baseline.
