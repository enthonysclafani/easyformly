# EasyFormly rispetto agli standard di EasyRankly

> Documento storico: i nomi `easycontact` qui sotto sono quelli della 1.0.0. Dal 15 ottobre 2026 il plugin si
> chiama EasyFormly e tutti i nomi usano `easyformly` (D10).

Analisi del 13 ottobre 2026 di EasyContact 1.0.0 (branch `main` e `Refactory`) confrontato con EasyRankly (branch
`Refactory`), e stato del lavoro per portare il plugin in produzione con quegli standard. Il piano operativo è
`docs/piano-standard.md`; le decisioni sono in `docs/decisioni.md`.

## In breve

Il codice di EasyFormly era già solido sul piano del comportamento: nessuna tabella custom, invio pubblico protetto
(ticket firmati, nonce, honeypot, rate limit, idempotenza), consegne senza doppioni grazie ai lock atomici,
quarantena con revisione umana, strumenti privacy del core, disinstallazione anche in multisito.

Mancava tutto ciò che in EasyRankly rende queste qualità **verificabili e difendibili nel tempo**: nessun
`composer check`, nessuna CI, nessun PHPCS o PHPStan, test lanciabili solo a mano su un sito locale, nessun documento
di regole o di modello dati, file di sviluppo dentro lo zip distribuito.

Con il lavoro della Fase 0 e della Fase 1 del piano EasyFormly ha gli stessi strumenti di EasyRankly, PHPCS a zero
e 28 test PHPUnit verdi su WordPress 6.4 e 7.1, singolo sito e multisito. I test nuovi hanno trovato due problemi
reali, corretti con un test di regressione (vedi "Trovato e corretto").

Le decisioni D1–D9 sono state prese il 13 ottobre 2026 (proposte del piano approvate). Non bloccano un rilascio:
guidano le fasi successive (struttura, cron, HTTP), e `docs/decisioni.md` indica dove il comportamento di oggi le
rispetta già. Cosa serve prima della produzione è nella sezione "Lista per la produzione".

## Confronto per area

Legenda: ✅ allineato, 🟡 parziale o eccezione decisa ancora da applicare, ❌ assente.

| Area | EasyRankly | EasyContact 1.0.0 | Ora (`Refactory`) | Cosa resta |
|---|---|---|---|---|
| Regole di progetto | `CLAUDE.md` completo | ❌ | ✅ `CLAUDE.md` con le invarianti decise | |
| Decisioni | `docs/decisioni.md` | ❌ (nel piano) | ✅ `docs/decisioni.md`, D1–D9 prese | |
| Modello dati | `docs/data-model.md` | ❌ | ✅ | tenerlo aggiornato |
| `composer check` | architettura + PHPCS + PHPStan + PHPUnit | ❌ | ✅ stessi script | |
| PHPCS (WPCS Extra + Docs, PHPCompatibilityWP) | zero errori | ❌ (264 errori, 210 avvisi) | ✅ zero | |
| PHPStan livello 8 | zero errori | ❌ | ✅ zero errori, senza baseline (Fase 6.1) | |
| PHPUnit sulla suite di WordPress | singolo sito e multisito | ❌ (script `wp eval-file`) | ✅ 28 test, singolo sito e multisito | test per Fase 2–5 |
| Controllo architetturale | `tools/check-architecture.php` con test | ❌ | ✅ adattato, con allowlist e `TODO` | ridurre le allowlist (Fasi 2 e 4) |
| CI | statici, PHPUnit min/max/multisito, Plugin Check, azioni per SHA | ❌ | ✅ stessa struttura | primo run verde |
| Dependabot | azioni GitHub verso `Refactory` | ❌ | ✅ | |
| Sessioni cloud | `cloud-setup.sh` + hook `SessionStart` | ❌ | ✅ | |
| Subagenti | `ricercatore`, `esecutore`, `verificatore` | ❌ | ✅ adattati | |
| Pacchetto distribuibile | solo file di runtime | ❌ test, strumenti e documenti nello zip | ✅ `.gitattributes`, `tools/build-dist.sh`, verificato in CI | |
| Zero tabelle custom | ✅ | ✅ | ✅ bloccato in CI | |
| `$wpdb` solo in allowlist | nessun uso | 🟡 3 file | ✅ 2 file in allowlist con motivo (lock atomici, disinstallazione) | |
| Cron | vietato | 🟡 5 eventi | ✅ un solo evento (`easycontact_retry_delivery`), pulizie senza cron | D1, Fase 4.1 |
| Asset nel frontend | vietati | 🟡 solo con un modulo | 🟡 in allowlist, verificato da test | D2 |
| HTTP | solo admin/REST/CLI | ✅ solo invio e admin (più i nuovi tentativi, D1) | ✅ in allowlist, `tests/HttpTest.php` | D3, Fase 4.3 |
| Dipendenze PHP a runtime | nessuna | 🟡 libphonenumber, polyfill mbstring | 🟡 escluse dai controlli, documentate | D4 |
| Query sul percorso caldo | zero in più, verificato da test | ❌ 3 letture a ogni richiesta | ✅ zero, verificato da test | |
| Struttura `src/` PSR-4, `register()` | ✅ | ❌ classi statiche in `includes/` | ✅ una cartella per funzionalità, hook verificati da `hooks-in-register` | Fasi 3.1–3.3 |
| `register_setting()` / `register_post_meta()` con schema | ✅ | ❌ | ✅ schema delle impostazioni senza REST (contengono segreti), meta dei moduli registrati | Fase 3.4 |
| `args` tipizzati sulle route REST | ✅ | 🟡 | ✅ tipi e lunghezze, `__return_true` motivato | Fase 3.5 |
| Disinstallazione completa e testata | ✅ | 🟡 completa, non testata | ✅ testata anche in multisito | D5 |
| Stringhe sorgente in inglese | ✅ | ❌ italiano | ✅ inglese con traduzione `it_IT` inclusa | D9, Fase 5.3 |
| Un solo foglio di stile admin | ✅ | ✅ `admin.css` (più `frontend.css` per il modulo) | ✅ regola `one-stylesheet` | Fase 5.1 |

## Trovato e corretto

1. **Tre query a ogni richiesta.** `Plugin::init()` leggeva a ogni richiesta, anche nelle pagine senza modulo, tre
   option non autoload (`easycontact_settings`, `easycontact_version`, `easycontact_review_terms_version`). Ora il
   setup gira solo quando cambia la versione, salvata in autoload; `tests/QueriesTest.php` lo verifica. Sui siti già
   in 1.0.0 l'option della versione diventa autoload al primo cambio di versione: per avere subito il beneficio il
   rilascio deve alzare `EASYFORMLY_VERSION`.
2. **Archivio bloccato se spariscono le categorie anti-spam.** Se i termini di `easycontact_safety` vengono cancellati
   fuori dal plugin (una migrazione, una pulizia del database, uno strumento di manutenzione), l'option
   `easycontact_review_terms_version` restava a `1` e ogni nuovo invio da archiviare falliva con `archive_failed`.
   Ora `Review::write()` ricrea le categorie prima di rinunciare; test di regressione in `tests/SubmissionTest.php`.
3. **File di sviluppo nello zip.** Senza `.gitattributes` il pacchetto conteneva test e strumenti. Ora sono esclusi e
   la CI controlla il contenuto dello zip prima di Plugin Check.

Queste due correzioni di codice sono le uniche modifiche di comportamento della Fase 0: le altre righe toccate nel
plugin sono stile e docblock, verificate dagli stessi test prima e dopo.

## Punti di forza già presenti

- Dati solo nativi: due post type privati, una tassonomia nascosta, option e meta prefissati.
- Invio pubblico: ticket firmato e con scadenza, nonce, honeypot con nome per ticket, orologio firmato contro gli
  invii troppo veloci, rate limit per indirizzo con chiave HMAC (l'indirizzo non si salva), limite sul corpo della
  richiesta, idempotenza per ticket.
- Consegne: lease atomici per non consegnare due volte, tentativi limitati, invii sospetti trattenuti fino
  all'approvazione manuale, esiti per destinazione.
- HTTP verso URL forniti dall'utente con `wp_safe_remote_post()`; webhook Slack validato.
- Privacy: esportazione e cancellazione con gli strumenti del core, retention configurabile, testo per la privacy
  policy.
- Lista dei domini usa e getta inclusa nel plugin e aggiornata solo su richiesta dell'amministratore, con controlli
  su dimensione e formato prima di sostituire la lista salvata.

## Lista per la produzione

Prima del merge di `Refactory` in `main` e del rilascio (Fase 6.4 del piano, da confermare da Enthony):

- [ ] CI verde sulla pull request (statici, PHPUnit su 6.4 e 7.1 anche in multisito, Plugin Check)
- [x] Risultato di PHPStan letto nella CI e baseline committata (punto 0.9)
- [ ] Verifica manuale sul sito Studio con Query Monitor: pagina senza modulo (zero query e zero asset del plugin),
      invio riuscito, invio sospetto in revisione, approvazione, nuovo tentativo dopo un errore di Slack/MailerLite
- [ ] Prova di aggiornamento da 1.0.0 a una copia del sito reale: impostazioni, moduli e invii intatti
- [ ] Versione alzata (`EASYFORMLY_VERSION`, intestazione del plugin, `Stable tag`) e changelog in `readme.txt`
- [x] Controllo di sicurezza della Fase 6.2 sul codice che va in produzione (`docs/sicurezza.md`)
- [x] Decisioni D1–D9 prese (13 ottobre 2026)
