# Pulso – Piano di evoluzione

11/11/2026 · Fabio Mangolini. Stato aggiornato da Claude Code: `[x]` = fatto o già presente e conforme, *parziale/assente* annotato in corsivo. Il dettaglio dell'audit è in fondo, sezione **Audit Fase 0**.

## Istruzioni per Claude Code

Questo piano descrive dove deve arrivare Pulso, non cosa manca davvero: parte di quanto elencato potrebbe esistere già. Prima di implementare qualsiasi punto, verifica nel codice.

1. Esegui la Fase 0 per intero e mostrami la gap analysis prima di scrivere codice.
2. Lavora una fase alla volta, nell'ordine indicato; a fine fase fermati e riassumi cosa hai fatto.
3. Per ogni punto: se esiste già, valuta se è conforme al piano e segnalalo; se esiste in parte, completa senza riscrivere; se manca, implementa.
4. Prima di cancellare codice (UI, multi-tenant, tool MCP) elenca cosa toglieresti e aspetta la mia conferma.
5. Prima di integrare API esterne (Google Ads, Meta, Bing, CrUX), verifica la documentazione attuale online: non basarti su conoscenze datate.
6. Mantieni i test esistenti verdi e aggiungi test per ogni nuovo tool MCP.
7. Aggiorna le checkbox di questo piano man mano: spunta i punti completati o già presenti e annota quelli parziali.

Stack di riferimento: Laravel 12, Inertia, React, shadcn/ui; client Google centralizzato in `GaClientService`.

## Fase 0 – Audit del codice esistente

- [x] Elenco di tutti i tool MCP esposti oggi: nome, cosa restituisce, dimensione tipica della risposta
- [x] Elenco delle pagine e dei componenti UI, classificati in "da tenere", "da togliere", "da valutare"
- [x] Mappa dell'impalcatura multi-tenant: modelli, middleware, scope nelle query, chiavi di cache, policy
- [x] Integrazioni attive e come si autenticano
- [x] Accesso Google Ads: developer token o accesso per progetto Cloud? Il Keyword Planner funziona?
- [x] Snapshot giornalieri: cosa salvano, con che frequenza, per quanto tempo
- [x] Esistono già annotazioni, log delle chiamate MCP, job schedulati o code?
- [x] Stato dei test: cosa è coperto e cosa no

## Visione e decisioni prese

Pulso diventa il connettore MCP unico con cui gli agenti di Jérôme fanno crescere i miei prodotti in SEO, GEO e Ads. La UI si riduce a un pannello di amministrazione.

- **Single-tenant:** Pulso serve solo me. Niente onboarding utenti, consensi OAuth o scoping per tenant.
- **Niente Airbyte:** agli agenti servono dati già sintetizzati, non tabelle grezze da interrogare in SQL.
- **Token-lean prima di tutto:** ogni tool restituisce risposte aggregate e brevi, non dump di dati.
- **Nuove sorgenti = tool piccoli e specializzati**, sullo stesso modello di Pulso e Sonda.
- **Stato attuale:** GA4, Search Console, Bing Webmaster Tools (API key, dall'11/11), Keyword Planner, AdSense, index status, keyword gap.

## Fase 1 – Snellimento: Pulso headless

*Completata l'11/11/2026.*

**Da tenere nella UI**

- [x] Settings e connessioni: credenziali Google, chiave Bing (in `app_settings`, come le altre: D2), associazione siti↔proprietà — *nuova pagina Impostazioni → Bing con verifica della key e abbinamento sito↔proprietà modificabile*
- [x] Salute connessioni: token scaduti, quote API esaurite, siti non verificati — *pagina Health come home (`ConnectionHealthService`): connessioni Google e Bing, freschezza di snapshot/GSC/AdSense/Bing per proprietà, quota Google Ads, MCP, job falliti; ogni problema dice cosa sistemare. Le quote GA4/GSC non hanno un'API di lettura: emergono come dati fermi*
- [x] Log chiamate MCP: tool chiamato, agente, peso della risposta, errori — *middleware `LogMcpToolCalls` + pagina MCP log; agente da `?agent=` o header `X-Pulso-Agent`; conservato 90 giorni (`model:prune`)*
- [x] Vista dati grezzi minima — *restano Snapshots, AdSense e Keywords*

**Da togliere**

- [x] Dashboard ricche, grafici curati, report esportabili — *tolti dashboard e report traffic/content/audience con il job `RefreshAnalyticsCache`; Keywords (con CSV) resta per scelta*
- [x] Funnel builder visuale (resta il tool MCP) — *creato `get-property-funnel` (passi ad hoc, evento e/o pagina), tolta la UI; eliminati anche modelli e tabelle `funnels`/`funnel_steps`*
- [x] Impalcatura multi-tenant — *niente team né cache per tenant da togliere; tolte registrazione e colonne `plan`, `properties_limit`, `trial_ends_at`; `user_id` resta (relazioni e auth)*

**Bing**

- [x] API key salvata cifrata in `app_settings` come le altre credenziali (decisione D2)
- [x] Scoperta automatica siti con `GetUserSites` e associazione alle proprietà per dominio — *più sync giornaliero alle 06:00 di traffico, ricerche, pagine e crawl, tool `get-property-bing-search` e `get-property-bing-traffic`, sezione Bing nel summary, avviso Telegram se la key smette di funzionare*
- [x] Esporre URL submission come tool — *`submit-urls-to-bing` con la Webmaster API (`SubmitUrlBatch`, stessa key, quota controllata prima dell'invio); IndexNow non serve per ora, perché richiede un file chiave sul sito*

## Fase 2 – Registro modifiche

*Completata l'11/11/2026, salvo l'ultimo punto che va fatto in Jérôme.*

- [x] Tool `add_annotation`: data, sito, tipo (contenuto, tecnico, campagna, deploy), descrizione breve, URL coinvolti — *`add-annotation`: tipi `content`, `technical`, `campaign`, `deploy`; data di default oggi nel fuso della proprietà; i percorsi diventano URL completi; agente registrato da `?agent=`; un doppione restituisce la voce esistente*
- [x] Tool `list_annotations` per sito e intervallo di date — *`list-annotations`, anche su tutti i siti, con filtri per tipo e testo (descrizione o URL)*
- [x] Annotazioni restituite insieme ai dati nei tool di trend (snapshot, pagine, query) — *anche sorgenti, eventi, AdSense, Bing (ricerche e traffico); il summary riporta quelle degli ultimi 30 giorni*
- [ ] Scrittura automatica da parte degli agenti Jérôme quando pubblicano o modificano qualcosa — *parziale: lato Pulso è pronto (istruzioni del server MCP e descrizione del tool chiedono di annotare ogni modifica pubblicata). Resta da aggiungere la regola nei prompt degli agenti Jérôme, fuori da questo repo*

In più: pagina admin **Annotations** per controllare le voci e cancellare quelle sbagliate.

## Fase 3 – Ads in lettura (Google + Meta)

**Google Ads**

- [x] Verificare che Pulso usi l'accesso per progetto Google Cloud — *fatto: il client non manda più l'header `developer-token` e `GOOGLE_ADS_DEVELOPER_TOKEN` non serve più; autenticazione OAuth + `login-customer-id`, API v26*
- [x] Tool performance campagne (costi, conversioni, CPA) e search terms report, aggregati — *`list-ads-accounts`, `get-ads-campaigns` (con periodo precedente), `get-ads-search-terms` (senza Performance Max, che Google espone a parte)*
- [x] Tool di incrocio: query a pagamento che convertono ma non hanno una pagina organica forte — *`get-paid-organic-gap`: missing / weak / strong rispetto a Search Console*

**Meta**

- [x] ~~Provare subito l'MCP ufficiale (mcp.facebook.com/ads) solo sull'agente ads di Jérôme~~ — *superato: Fabio vuole Pulso come unico punto di connessione (11/11)*
- [x] ~~Misurare quanti token costano i suoi 29 tool in contesto~~ — *non serve più, vedi sopra*
- [x] Wrapper in Pulso sulla Marketing API (v27.0) con system user token, con pochi tool sintetici — *`list-meta-ad-accounts`, `get-meta-campaigns`, `get-meta-ads`, `get-meta-breakdown`; token cifrato in Impostazioni → Meta, stato nella pagina Health*

## Fase 4 – GEO: visibilità nelle risposte AI

**Prompt tracking**

- [x] Set di prompt per prodotto salvato in Pulso — *tabella ai_visibility_prompts, gestita dagli agenti con `set-ai-visibility-prompts` (max 30 attivi per sito, più i termini di brand)*
- [x] Job periodico che interroga i modelli via API e salva snapshot: citato sì/no, posizione, URL citato, concorrenti nominati — *solo ChatGPT (OpenAI Responses API con ricerca web, modello gpt-5.6-luna), ogni lunedì alle 07:00, tetto di 3 €/mese; modello, tetto e frequenza in Impostazioni → AI visibility. I concorrenti sono i domini citati*
- [x] Tool `get_ai_visibility` con trend e differenze rispetto allo snapshot precedente — *`get-ai-visibility`: quota di risposte che citano il sito per giro, prompt guadagnati/persi, pagina citata, domini concorrenti*

**Crawler AI**

- [x] Rilevare passaggi di GPTBot, ClaudeBot, PerplexityBot e simili, per pagina — *16 crawler AI divisi in training / search / user; tool `get-ai-crawlers` con pagine visitate, errori e contenuti nuovi non ancora visitati*
- [x] ~~Fonte: Cloudflare, se sposto lì i domini; altrimenti log del server~~ — *scelto diversamente con Fabio: sono i siti a mandare le visite a Pulso (endpoint `/api/ai-crawlers`, token per sito, middleware Laravel nel README), senza dipendere da VPS, log o DNS*

**Bing come segnale GEO**

- [ ] Usare i dati Bing anche in chiave AI — *dipende da Bing (Fase 1)*

## Fase 5 – Ads in scrittura, con guardrail

- [ ] Ogni campagna, gruppo o annuncio creato nasce in pausa
- [ ] Attivazione solo con mia approvazione tramite il bot Telegram di Jérôme — *Pulso oggi invia solo messaggi Telegram, non ne riceve*
- [ ] Tetto di budget giornaliero e mensile per account
- [ ] Nessun aumento di budget oltre una soglia senza approvazione
- [ ] Log di ogni azione di scrittura (chi, cosa, quando), scritto anche nel registro modifiche
- [ ] Google: la scrittura va costruita in Pulso. Il livello Explorer (2.880 operazioni/giorno su account reali) basta per i miei account
- [ ] Meta: l'MCP ufficiale crea già in pausa; i tetti di budget li garantisce solo il wrapper in Pulso

## Fase 6 – Salute tecnica e cannibalizzazione

- [ ] Core Web Vitals reali dalla CrUX API e audit da PageSpeed Insights API
- [ ] Controllo da sitemap: 404, canonical errati, pagine in sitemap ma non indicizzate — *parziale: `get-property-index-status` legge la sitemap e ispeziona fino a 50 URL (stato, motivo, canonical Google/utente); niente controllo HTTP 404*
- [ ] Tool di cannibalizzazione — *assente, ma i dati ci sono già: `property_search_queries` salva query × pagina × giorno*
- [ ] Mappa query↔pagina — *parziale: `get-property-search-queries` dà la pagina con più clic per ogni query*

## Principi di design dei tool MCP

- **Risposte, non dati** — *parziale: vedi pesi delle risposte nell'audit*
- **Incroci lato server** — *presente per keyword gap (Keyword Planner ↔ GSC)*
- **Gruppi di tool attivabili per agente** — *assente: un solo server, 13 tool, ~4.000 token di definizioni*
- **Scrittura solo dove serve** — *oggi tutti i tool sono in sola lettura*
- **Errori leggibili** — *parziale: messaggi chiari per Google Ads; gli altri tool restituiscono l'eccezione*

## Fuori scope e verifiche aperte

**Fuori scope per ora**

- Airbyte o altri strati ELT/warehouse
- Backlink e posizionamento dei concorrenti (API a pagamento)
- UI ricca e report per terzi

**Verifiche aperte**

- [x] Livello di accesso attuale del progetto Google Cloud — *il Keyword Planner funziona (risposte in cache fino al 10/11), quindi il progetto è almeno Basic: Explorer blocca i servizi di pianificazione*
- [x] Se serve Basic: completare la brand verification — *non serve, se il punto sopra è confermato in produzione*
- [ ] Stato dell'MCP ufficiale Meta (open beta dal 29/04/2026) e suo comportamento con Claude Code

**Fonti**

- [Google Ads API – Access levels and permissible use](https://developers.google.com/google-ads/api/docs/api-policy/access-levels)
- [Google Ads API – Deprecations (sunset developer token 09/09/2026)](https://developers.google.com/google-ads/api/docs/deprecations)
- [Meta – Features reference (Marketing API Access Tier)](https://developers.facebook.com/docs/features-reference)
- [Meta – annuncio dell'Ads MCP server per sviluppatori](https://developers.facebook.com/blog/post/2026/07/16/meta-ads-mcp-server/)

---

## Audit Fase 0 (11/11/2026)

### Tool MCP esposti (`/mcp/pulso`, `app/Mcp/Servers/PulsoServer.php`)

Pesi misurati sul DB locale, sulla proprietà con più snapshot, con i parametri di default (30 giorni). Token stimati come byte / 3,5.

| Tool | Restituisce | Peso tipico |
|---|---|---|
| `list-properties` | proprietà attive + ultimo snapshot | ~900 tok |
| `get-property-summary` | ultimo snapshot, medie 7/30 gg, anomalie, top sorgenti e pagine | ~3.600 tok |
| `get-property-snapshots` | una riga completa per giorno | ~6.400 tok |
| `get-property-sources` | sorgente/mezzo aggregati | ~750 tok |
| `get-property-pages` | top pagine aggregate | ~2.200 tok |
| `get-property-search-queries` | query GSC (30 default, max 500) | ~2.500 tok (500 righe: ~40.000) |
| `get-property-events` | eventi GA4 aggregati | ~1.300 tok |
| `get-property-adsense` | totali, periodo precedente, serie giornaliera, breakdown | non misurato (include la serie giornaliera) |
| `get-property-index-status` | ispezione URL live (max 50) | variabile, chiamate API live |
| `get-keyword-ideas` / `-metrics` / `-gap` | Keyword Planner, colonnare | già compatti |
| `search-geo-targets` | geo target ID | piccolo |

- 9 tool su 13 usano `JSON_PRETTY_PRINT`: spazi e a capo pagati in token.
- Definizioni dei 13 tool: ~4.000 token in contesto per ogni agente.
- Nessun tool funnel, annotazioni, Bing o Ads campagne.

### UI (`resources/js/pages`)

| Pagina | Classificazione |
|---|---|
| `settings/google`, `settings/mcp`, `settings/telegram`, `settings/snapshots`, `properties/index` | da tenere |
| `settings/profile`, `settings/password`, `settings/appearance`, `auth/login`, `auth/forgot/reset-password` | da tenere (minimo per l'accesso) |
| `snapshots/index`, `adsense/index` | da valutare: base per la "vista dati grezzi" |
| `keywords/index` | da valutare: utile per controlli rapidi, ma il tool MCP copre tutto |
| `dashboard/overview`, `reports/traffic`, `reports/content`, `reports/audience` | da togliere (GA4 live via `GaClientService` + `AnalyticsCache` + job `RefreshAnalyticsCache`) |
| `funnels/*` | da togliere solo dopo aver creato il tool MCP funnel (oggi non esiste) |
| `auth/register`, `auth/verify-email`, `auth/confirm-password` | da togliere: registrazione aperta, inutile e rischiosa per un servizio single-tenant |
| `welcome`, `privacy` | da valutare: `privacy` può servire per la schermata di consenso OAuth Google |

### Multi-tenant

- **Niente team**, niente cache con chiave per tenant (le cache sono per proprietà: `analytics_cache`, `Cache` di GSC/AdSense, token Google Ads globale).
- `user_id` su `ga_connections`, `ga_properties`, `funnels`, `app_settings` (unique `user_id, key`), `mcp_tokens`; `users.active_property_id`.
- Colonne morte su `users`: `plan`, `properties_limit`, `trial_ends_at` (mai lette).
- Scoping: `ResolvesMcpContext` (MCP, via `gaProperties()`), `abort_unless(... user_id ...)` in `PropertyController`, `DashboardController`, `GoogleAuthController`, `FunnelPolicy`, `exists` con `user_id` nelle form request, `HandleInertiaRequests`.
- Impostazioni per utente via `SettingService` (client ID/secret Google, Telegram, `snapshot_time`); `GenerateDailySnapshots` gira su `User::each` con l'orario di ciascuno.
- Auth MCP: `EnsureMcpTokenAuthenticated`, un token per utente (query `?token=`, Bearer o `X-MCP-Token`); nessuna distinzione per agente.
- Proposta (da confermare in Fase 1): togliere registrazione e colonne morte, tenere `user_id` dove regge relazioni e auth (rimuoverlo costa molto e non porta benefici); un utente solo.

### Integrazioni e autenticazione

| Integrazione | Autenticazione |
|---|---|
| GA4, Search Console, AdSense | OAuth Socialite per connessione (`GaConnection`, refresh token), client ID/secret per utente in `app_settings` |
| Google Ads / Keyword Planner | credenziali globali in `.env` (`config/services.php`), refresh token da `pulso:google-ads-auth`, REST v26 |
| Telegram | bot token per utente in `app_settings` (solo invio) |
| Bing | assente |

**Google Ads.** I developer token sono stati dismessi il 09/09/2026 e l'accesso ora segue il progetto Cloud; l'header viene ignorato e verrà rifiutato in una futura major. `GoogleAdsClient` manda ancora `developer-token` e lo considera obbligatorio. Il Keyword Planner funziona (22 risposte in cache, ultima del 10/11; operazioni registrate fino al 10/11), quindi il progetto è almeno Basic.

### Snapshot, job, code

- `property_snapshots`: una riga per proprietà e giorno (metriche, delta settimana/30 gg, trend, anomalie), con figli top 10 sorgenti, top 20 pagine, top 50 eventi. Generati da `GenerateDailySnapshots` ogni ora, all'orario scelto per il giorno precedente, con recupero dei giorni mancanti. Nessuna cancellazione: conservati per sempre (in locale dal 12/03/2026).
- `property_search_queries`: query × pagina × giorno, fino a 16 mesi, sync GSC ogni giorno alle 05:00.
- `property_adsense_metrics`: per giorno, sync ogni 3 ore.
- Scheduler: `RefreshAnalyticsCache` 02:00, `CheckGoogleConnections` orario, `GenerateDailySnapshots` orario, `SyncSearchConsoleData` 05:00, `SyncAdSenseData` ogni 3 ore. Coda `database`, 3 worker + `schedule:work` (s6 in `Dockerfile.coolify`).
- Annotazioni, log delle chiamate MCP: assenti.
- `failed_jobs` locale: 4 `GenerateDailySnapshots` in MaxAttemptsExceeded (ultimo 17/09).

### Test

245 test, **10 rossi già prima di questo lavoro**:
- 5 in `GenerateSnapshotsCommandTest`: `GaConnectionFactory::active()` non esiste;
- 4 in `SnapshotControllerTest`: `actingAs()` non definita;
- 1 in `GenerateDailySnapshotsTest` (recupero snapshot mancanti): asserzione fallita.

Tutti i 13 tool MCP hanno test in `tests/Feature/Mcp`. Coperti anche servizi, sync, job e controller principali.
