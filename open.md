# Open

## Open questions
- Fase 4, prompt tracking: Fabio deve creare una API key OpenAI (con credito) e salvarla in Impostazioni → AI visibility; poi gli agenti di Jérôme scrivono i prompt per sito con set-ai-visibility-prompts (11/11).
- Fase 4, crawler AI: calcolato.it collegato e verificato in produzione (11/11, 67 visite il primo giorno); deve aggiungere `'ip' => $request->ip()` al middleware perché Pulso verifichi gli IP (trustProxies è già `*`). Da collegare anche meltinbit.com: Astro 6 con adapter node standalone ma pagine prerenderizzate, quindi serve un piccolo server Node davanti all'handler (11/11).
- Produzione: togliere GOOGLE_ADS_DEVELOPER_TOKEN dal .env di Coolify (non serve più) (11/11).
- Jérôme: aggiungere ai prompt degli agenti la regola "chiama add-annotation di Pulso ogni volta che pubblichi o modifichi qualcosa su un sito" e il parametro &agent= negli URL dei connettori (11/11).
- Deploy: add_verification_to_ai_crawler_visits_table va in produzione al prossimo deploy, insieme al codice che la usa (le migration precedenti sono già applicate: i crawler di calcolato.it arrivano in produzione) (11/11).

## Parked
- P1: Fase 5 (Ads in scrittura con guardrail) rimandata da Fabio: oggi non ci sono campagne Google né Meta; prima di riprenderla serve decidere il meccanismo di approvazione via Telegram (bot di Jérôme o Pulso) (11/11).

## Assumptions
- A7: Il DB 10.200.0.1/pulso_db è quello di produzione (dati reali, failed_jobs da /app); da locale si lavora su pulso_dev (MySQL 8 in Docker, container pulso-dev-mysql, porta 3307) e non si lanciano migration sul DB condiviso (11/11).
- A1: Stack Laravel 12 + Inertia v2 + React 19 + Tailwind v4 + shadcn/ui, MySQL 8 — è quello in uso dal primo commit (02/11).
- A2: Deploy su Coolify con Dockerfile.coolify (serversideup/php 8.2) al posto di Nixpacks — commit 5ed13a6 (02/11).
- A3: Licenza AGPL-3.0, progetto open source — commit 9d0fb4a (02/11).
- A4: Impostazioni per utente (snapshot_time, Telegram, proprietà attiva salvata sull'utente) invece che globali — commit bd44c4a e 19e3e6a (02/11).
- A5: Server MCP /mcp/pulso protetto da token per utente, con laravel/mcp tra le dipendenze di produzione — commit d661d40 e d35e87f (02/11).
- A6: Righe Search Console salvate per giorno (fino a 16 mesi) invece di una top list troncata — commit 98ecee4 (02/11).

## Backlog
