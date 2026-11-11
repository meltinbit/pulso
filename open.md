# Open

## Open questions
- Piano di evoluzione: Fabio deve rivedere la gap analysis della Fase 0 (docs/piano-evoluzione.md) prima della Fase 1 (11/11).
- Bing key in .env (nuovo piano) contro D1 (key per utente in app_settings): quale vale? (11/11)
- Funnel: il piano dice "resta il tool MCP", ma non esiste; crearlo prima di togliere la UI o abbandonare i funnel? (11/11)
- Bing: nella fase 1 serve anche una pagina/card nell'app web, o bastano impostazioni + MCP? (docs/bing-webmaster.md, fase 3)

## Parked

## Assumptions
- A1: Stack Laravel 12 + Inertia v2 + React 19 + Tailwind v4 + shadcn/ui, MySQL 8 — è quello in uso dal primo commit (02/11).
- A2: Deploy su Coolify con Dockerfile.coolify (serversideup/php 8.2) al posto di Nixpacks — commit 5ed13a6 (02/11).
- A3: Licenza AGPL-3.0, progetto open source — commit 9d0fb4a (02/11).
- A4: Impostazioni per utente (snapshot_time, Telegram, proprietà attiva salvata sull'utente) invece che globali — commit bd44c4a e 19e3e6a (02/11).
- A5: Server MCP /mcp/pulso protetto da token per utente, con laravel/mcp tra le dipendenze di produzione — commit d661d40 e d35e87f (02/11).
- A6: Righe Search Console salvate per giorno (fino a 16 mesi) invece di una top list troncata — commit 98ecee4 (02/11).

## Backlog
- B2: 10 test rossi preesistenti (GaConnectionFactory::active(), actingAs() mancante, recupero snapshot) — da sistemare a inizio Fase 1 (11/11).
- B1: Integrazione Bing Webmaster Tools — piano e verifiche API in docs/bing-webmaster.md; prima ricontrollare i dati di calcolato.it, ancora in elaborazione su Bing (10/11).
