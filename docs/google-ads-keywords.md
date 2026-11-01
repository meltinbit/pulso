# Keyword research (Google Ads API)

Pulso usa il **KeywordPlanIdeaService** della Google Ads API (v26, trasporto REST via `Http`, nessuna estensione grpc né SDK) per:

- idee keyword da keyword di partenza e/o URL, con volume medio mensile, 12 volumi mensili, concorrenza, CPC top of page;
- metriche storiche di keyword specifiche;
- ricerca dei geo target (città, province, regioni);
- **gap** con Search Console: per ogni idea, se la property compare già (posizione media, impressioni, clic negli ultimi 90 giorni) oppure no.

## 1. Credenziali

| Variabile | Dove trovarla |
|---|---|
| `GOOGLE_ADS_DEVELOPER_TOKEN` | MCC → Amministrazione → Centro API. Il livello di accesso deve essere **Basic** ("Di base"): con "Test" non si leggono dati reali e con **Explorer** Google non consente la ricerca keyword. Basic richiede la verifica del brand in Google Auth Platform e si richiede in Google Cloud Console → API e servizi → Google Ads API → Livelli di accesso. |
| `GOOGLE_ADS_LOGIN_CUSTOMER_ID` | ID dell'MCC (solo cifre). |
| `GOOGLE_ADS_CUSTOMER_ID` | ID dell'account Google Ads sotto l'MCC usato per le richieste (solo cifre). |
| `GOOGLE_ADS_CLIENT_ID` / `GOOGLE_ADS_CLIENT_SECRET` | Google Cloud Console → API e servizi → Credenziali → client OAuth di tipo **Desktop app**. Abilita anche la "Google Ads API" nel progetto. |
| `GOOGLE_ADS_REFRESH_TOKEN` | Generato con il comando qui sotto. |

Opzionali:

| Variabile | Default | |
|---|---|---|
| `GOOGLE_ADS_API_VERSION` | `v26` | Versione REST dell'API. |
| `GOOGLE_ADS_DAILY_OPERATIONS_LIMIT` | `1000` | Tetto giornaliero di chiamate, per stare dentro la quota del token. Le risposte dalla cache non contano. |
| `GOOGLE_ADS_CACHE_DAYS` | `30` | Durata della cache su DB (`keyword_research_cache`). |
| `GOOGLE_ADS_DEFAULT_LANGUAGE` | `1004` | languageConstant di default (italiano). |
| `GOOGLE_ADS_DEFAULT_GEO` | `2380` | geoTargetConstant di default (Italia). |

### Refresh token

Con client ID e secret già nel `.env`:

```bash
php artisan pulso:google-ads-auth
```

Va lanciato in un terminale interattivo. Se la porta 8765 è occupata da un altro programma usa `--port=8799` (il client Desktop accetta qualsiasi porta locale).

1. Apri l'URL stampato e accedi con l'account Google che gestisce l'MCC.
2. Il browser viene rediretto su `http://127.0.0.1:<porta>/?code=…` e mostra un errore di connessione: è normale.
3. Incolla l'URL completo nel terminale e copia la riga `GOOGLE_ADS_REFRESH_TOKEN=…` nel `.env` e nelle variabili d'ambiente su Coolify.

Se la schermata di consenso OAuth è in modalità *Testing*, il refresh token scade dopo 7 giorni: pubblicala (*In production*) per un token duraturo.

## 2. Uso dalla UI

Sidebar → **Keywords**. Il confronto con Search Console usa la property attiva.

- Keyword di partenza separate da virgola (max 20), URL opzionale, località con autocomplete (es. "Ferrara" → provincia o città), lingua.
- Tabella ordinabile: volume, trend (sparkline degli ultimi 13 mesi e variazione dell'ultimo mese disponibile rispetto allo stesso mese dell'anno prima, così le keyword stagionali non sembrano in crollo), concorrenza e indice, CPC min–max, posizione GSC, "variant of …" o "not present".
- **Only gap** mostra solo le keyword per cui il sito non compare; **CSV** esporta la vista corrente con i volumi mensili.
- In alto: operazioni API usate oggi rispetto al limite.

## 3. Uso via MCP

| Tool | Parametri | Note |
|---|---|---|
| `get-keyword-ideas-tool` | `seeds`, `url`, `property_id`, `location`, `language`, `limit` | Con `property_id` aggiunge `gsc_pos`, `gsc_impr`, `gsc_clicks`. |
| `get-keyword-metrics-tool` | `keywords`, `location`, `language` | Include i 12 volumi mensili, allineati a `months`. |
| `search-geo-targets-tool` | `query`, `country_code` | Restituisce id, nome, tipo e percorso. |
| `get-keyword-gap-tool` | `property_id`, `seeds`, `location`, `language`, `limit`, `only_gap` | Riepilogo presenti/assenti e volume mancante. |

`location` accetta un ID (`2380`) o un nome (`Ferrara`, risolto con il primo suggerimento); `language` un codice (`it`, `en`, `de`, `fr`, `es`) o un ID languageConstant. L'output è colonnare (`cols` + `rows`) con numeri arrotondati.

## 4. Note

- **Volumi**: Google restituisce un singolo valore approssimato (arrotondato), mai una fascia; la UI lo indica come approssimazione. Se un volume manca viene mostrato `–`.
- **CPC**: nella valuta dell'account Google Ads (`GOOGLE_ADS_CUSTOMER_ID`).
- **Gap**: confronto esatto dopo normalizzazione (minuscole, accenti, spazi) con le query GSC già sincronizzate in `property_search_queries`; nessuna chiamata API aggiuntiva.
- **Varianti**: Google raggruppa le varianti vicine ("calcolo imu", "calcolatori imu") e dà a tutte lo stesso volume e la stessa serie mensile. Le keyword con metriche identiche (volume ≥ 50) sono trattate come un gruppo: se il sito compare per una, le altre sono marcate `variant_of` e non contano come gap, e nel volume mancante ogni gruppo conta una volta sola.
- **Mesi**: Pulso chiede a Google una finestra di 24 mesi e tiene gli ultimi 13 disponibili; l'ultimo mese pubblicato da Google è di solito uno o due mesi indietro.
- **Errori**: token solo test, permessi mancanti, customer ID errato, quota esaurita, refresh token scaduto e limite giornaliero sono mostrati con un messaggio leggibile (dettagli completi nel log).
