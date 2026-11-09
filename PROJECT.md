# Pulso

## Obiettivo
Dashboard GA4 per agenzie e freelance che raccoglie ogni giorno, per ogni proprietà, i dati di Google Analytics 4, Search Console e AdSense, li salva nel tempo e li rende leggibili sia dall'app web sia da Claude tramite il server MCP, così da capire come va un sito e dove intervenire senza aprire le singole console Google.

## Criteri di successo
- Ogni proprietà collegata ha lo snapshot giornaliero all'orario scelto dall'utente, senza buchi.
- I dati Search Console coprono fino a 16 mesi giorno per giorno, e i totali su qualsiasi intervallo sono corretti.
- Da Claude, via MCP, si risponde a domande SEO e di traffico concrete (pagine, ricerche, indicizzazione, guadagni AdSense, keyword) senza passare dalle console Google.
- Un token Google scaduto o una connessione persa vengono segnalati subito (Telegram), non scoperti settimane dopo.

## Aree
- Snapshot GA4: metriche, sorgenti, pagine, eventi, trend e anomalie.
- Search Console: ricerche e pagine per giorno, ispezione dell'indicizzazione degli URL.
- AdSense: guadagni per giorno, per paese, piattaforma e unità pubblicitaria.
- Keyword research: Google Ads Keyword Planner e confronto (gap) con Search Console.
- Server MCP (/mcp/pulso): gli strumenti per Claude, con token per utente.
- Notifiche e account: Telegram, connessioni Google, impostazioni per utente.

## Project rules
- Open source con licenza AGPL-3.0: niente segreti o dati di clienti nel repo.
- Ogni dato nuovo che finisce nell'app arriva anche nel server MCP, con descrizioni del tool e istruzioni del server aggiornate.
- Deploy su Coolify con Dockerfile.coolify: le dipendenze che servono a runtime (per esempio laravel/mcp) vanno tra quelle di produzione.
