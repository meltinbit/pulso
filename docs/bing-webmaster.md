# Bing Webmaster Tools — piano di integrazione

Stato: **da implementare**. Verifiche fatte il 10/11/2026 con un'API key reale (12 siti verificati, solo uno con dati: tutti i numeri sotto vengono da quel sito).

## 1. Cosa dice l'API (verificato)

Endpoint JSON: `https://ssl.bing.com/webmaster/api.svc/json/<Metodo>?siteUrl=…&apikey=…`. La risposta è sotto la chiave `d`; le date arrivano come `/Date(1753401600000)/` (millisecondi UTC, mezzanotte). Nessun parametro di data o di limite: ogni chiamata restituisce **tutto lo storico**.

| Metodo | Granularità | Storico osservato | Campi |
|---|---|---|---|
| `GetUserSites` | — | — | `Url`, `IsVerified` |
| `GetRankAndTrafficStats` | **giornaliera** | ~16 mesi (486 giorni) | `Date`, `Clicks`, `Impressions` |
| `GetQueryStats` | **settimanale** | ~16 mesi (68 settimane) | `Date`, `Query`, `Clicks`, `Impressions`, `AvgImpressionPosition`, `AvgClickPosition` |
| `GetPageStats` | settimanale | ~16 mesi | come sopra, ma `Query` contiene l'URL della pagina |
| `GetQueryPageStats(query)` / `GetPageQueryStats(page)` | settimanale | ~16 mesi | pagine di una ricerca / ricerche di una pagina (una chiamata per ricerca o pagina) |
| `GetCrawlStats` | giornaliera | ~6 mesi (175 giorni) | `CrawledPages`, `InIndex`, `InLinks`, `CrawlErrors`, `Code2xx/301/302/4xx/5xx`, `BlockedByRobotsTxt`, `DnsFailures`, `ConnectionTimeout`, `ContainsMalware`, `AllOtherCodes` |
| `GetUrlInfo(url)` | — | — | `DiscoveryDate`, `LastCrawledDate`, `AnchorCount`, `HttpStatus` (0 sulla home testata), `IsPage` |

Cose emerse dalle prove:

- **Settimane**: le righe settimanali hanno sempre data di venerdì. Confrontate con i totali giornalieri, la riga del venerdì *F* copre i 7 giorni **da F−7 a F−1** (venerdì → giovedì). Esempio: riga del 06/11 = giorni 30/10–05/11.
- **Ritardo**: il giornaliero arriva fino a 3 giorni fa (07/11 il 10/11); l'ultima settimana completa è disponibile il venerdì stesso.
- **Totali**: la somma delle righe per ricerca (321 clic) è un po' più bassa di quella giornaliera (330), come in Search Console con le ricerche anonimizzate. I totali "veri" del sito vanno presi dal giornaliero.
- **`AvgClickPosition` vale -1** quando non c'è dato, anche con clic > 0: va salvato come `null`. La posizione da usare è `AvgImpressionPosition`, pesata per impressioni nelle somme.
- **Volumi**: 11 siti su 12 restituiscono liste vuote, perché sono stati importati in Bing il 09/11 e i dati erano ancora in elaborazione (24–48 h). Il sito che interessa è **calcolato.it**: rifare `GetQueryStats` e `GetRankAndTrafficStats` su calcolato.it prima della fase 1, per confermare che i dati arrivano e con quale storico. L'integrazione deve comunque gestire "nessun dato" senza errori.
- `GetUrlInfo` è molto più povero dell'ispezione URL di Google: niente stato di copertura. Non serve una sezione "indicizzazione Bing" per URL; basta `InIndex` del crawl giornaliero.

## 2. Decisioni

- Accesso con **API key per utente** (non OAuth): una key vale per tutti i siti verificati dell'utente e non scade. Salvata cifrata con `SettingService`, gruppo `bing`, chiave `bing_api_key`.

## 3. Disegno

### Collegamento proprietà ↔ sito Bing
- Nuova colonna `ga_properties.bing_site_url` (nullable).
- Al salvataggio della key e a ogni sync: `GetUserSites`, e per le proprietà senza `bing_site_url` si abbina per host di `website_url` (ignorando `www.` e lo slash finale). Modificabile a mano dalla pagina proprietà.

### Tabelle
| Tabella | Chiave unica | Colonne |
|---|---|---|
| `property_bing_traffic` | `ga_property_id, date` | `clicks`, `impressions` |
| `property_bing_queries` | `ga_property_id, week_start, query` | `week_start` (= F−7), `clicks`, `impressions`, `avg_impression_position`, `avg_click_position` (nullable) |
| `property_bing_pages` | `ga_property_id, week_start, page` | come sopra con `page` (1024) |
| `property_bing_crawl_stats` | `ga_property_id, date` | i campi di `GetCrawlStats` in snake_case |

Ricerca × pagina non si salva: servirebbe una chiamata per ogni ricerca.

### Servizi, job, comandi
- `BingWebmasterService`: client `Http` (timeout, retry), parsing di `/Date()/`, `BingApiException` con status e messaggio. Metodi: `userSites`, `trafficStats`, `queryStats`, `pageStats`, `crawlStats`.
- `BingSyncService::sync(GaProperty)`: scarica tutto e fa `upsert` a blocchi di 500 righe (le ultime settimane vengono riviste da Bing, quindi si sovrascrive).
- Job `SyncBingWebmasterData` ogni giorno alle 06:00 (dopo Search Console); comando `pulso:sync-bing {--property=}`.
- Key non valida (401/403 o errore di autenticazione) → avviso Telegram, come `CheckGoogleConnections`.

### Impostazioni
- `Settings/BingSettingsController` + `settings/bing.tsx`: campo API key (mascherata come il secret Google), verifica con `GetUserSites` al salvataggio, elenco dei siti Bing con la proprietà Pulso abbinata.

### MCP (regola di progetto: ogni dato nuovo arriva anche qui)
- `get-property-bing-queries`: ricerche **o** pagine (`dimension: query|page`), stessi filtri di `get-property-search-queries` (pagina/prefisso, `query_contains`, `limit` ≤ 500, `sort_by`). `from`/`to` vengono allargati alle settimane intere che li contengono, e la risposta riporta il periodo effettivo coperto.
- `get-property-bing-traffic`: clic e impressioni giornalieri + crawl (pagine scansionate, in indice, errori, 4xx/5xx) nell'intervallo.
- `get-property-summary`: sezione Bing (clic/impressioni ultimi 28 giorni vs precedenti, `InIndex` più recente).
- Aggiornare le istruzioni del server in `PulsoServer`.

### Test (Pest, `Http::fake` con fixture **sintetiche**: niente dati di clienti nel repo, AGPL)
- Parsing delle date e `-1` → `null`; calcolo di `week_start`.
- Upsert idempotente (due sync = stesse righe).
- Abbinamento proprietà per host (www / non-www / slash).
- Liste vuote → nessun errore, zero righe.
- Key non valida → eccezione + notifica.
- Tool MCP: allargamento a settimane intere, filtri, autorizzazione sulla proprietà.
- Controller impostazioni: salvataggio cifrato, key mascherata, errore se la key non è valida.

## 4. Fasi
1. Impostazioni + servizio + tabelle + sync + comando (con test).
2. Tool MCP + summary + istruzioni server.
3. (Facoltativa) Pagina "Bing" nell'app web, o card Bing nella dashboard.
