# Decisions

- D1: Bing Webmaster Tools collegato con API key per utente, non OAuth — più semplice, una key vale per tutti i siti verificati e non scade (10/11).
- D2: Bing resta con la API key in app_settings come le altre credenziali (conferma D1, non in .env come proposto dal piano di evoluzione); Bing si implementa da zero nella Fase 1 seguendo docs/bing-webmaster.md (11/11).
- D3: Funnel solo via MCP (get-property-funnel, passi ad hoc evento/pagina, dati GA4 live): tolta la UI funnel; la pagina Keywords resta (11/11).
- D4: Pulso headless: la home del pannello è la pagina Health (salute connessioni e freschezza dati); tolti dashboard, report traffic/content/audience, registrazione e colonne plan/properties_limit/trial_ends_at (11/11).
- D5: Pulso è l'unico punto di connessione degli agenti: Meta Ads entra come wrapper in Pulso sulla Marketing API (token utente di sistema cifrato in app_settings), non come MCP ufficiale di Meta collegato a Jérôme (11/11).
