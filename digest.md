# Digest

- 2026-11-02 12:43 · Creati i file di memoria di progetto (open, decisions, digest) · none · conferma assunzioni A1-A6
- 2026-11-09 · Filtri pagina/prefisso URL, 'la ricerca contiene' e limit (fino a 500) in get-property-search-queries · none · —
- 2026-11-09 · PROJECT.md scritto e confermato da Fabio · none · —
- 2026-11-10 · Ricerca e verifica API Bing Webmaster Tools con key reale; piano in docs/bing-webmaster.md · none · fase 1 da avviare
- 2026-11-11 · Fase 0 del piano di evoluzione (headless/MCP): audit e gap analysis in docs/piano-evoluzione.md, nessuna modifica al codice · none · revisione di Fabio, poi Fase 1
- 2026-11-11 · Fase 1 del piano di evoluzione: test verdi, tool MCP funnel, tolti dashboard/report/funnel UI/registrazione, pagina Health, log chiamate MCP, integrazione Bing completa (sync, impostazioni, 3 tool MCP), DB locale pulso_dev · none · revisione di Fabio, poi Fase 2
- 2026-11-11 · Fase 2 del piano di evoluzione: registro modifiche (tabella property_annotations, tool add-annotation e list-annotations, annotazioni nei tool di trend, pagina admin Annotations) · none · regola nei prompt degli agenti Jérôme
- 2026-11-11 · Fase 3 del piano di evoluzione (Google): client Ads senza developer token, tool list-ads-accounts, get-ads-campaigns, get-ads-search-terms, get-paid-organic-gap · none · revisione di Fabio, prova Meta MCP in Jérôme
- 2026-11-11 · Fase 3, correzioni dopo la prova reale: gap pagato/organico filtrato per dominio della property, parametro site in get-ads-search-terms, valuta letta dall'account se non ci sono campagne · none · revisione di Fabio, poi Fase 4
- 2026-11-11 · Fase 3, Meta Ads in Pulso (D5): MetaAdsService sulla Marketing API v27.0, tool list-meta-ad-accounts, get-meta-campaigns, get-meta-ads, get-meta-breakdown, Impostazioni → Meta, stato nella pagina Health · none · token Meta da creare e prova reale
- 2026-11-11 · Fase 4, prompt tracking (D6): tabelle ai_visibility, client OpenAI Responses con ricerca web, giro settimanale con tetto mensile, tool set-ai-visibility-prompts e get-ai-visibility, Impostazioni → AI visibility, Health · none · API key OpenAI, prompt degli agenti, fonte crawler AI
- 2026-11-11 · Fase 4, crawler AI (D7): endpoint /api/ai-crawlers con token per sito, conteggi giornalieri per bot e pagina, tool get-ai-crawlers, middleware Laravel nel README · none · collegare i siti, Bing come segnale GEO
- 2026-11-11 · Fase 4 chiusa: Bing come segnale GEO in get-ai-visibility (query Bing più vicina a ogni prompt, trend Bing 28 giorni) · none · revisione di Fabio, poi Fase 5
- 2026-11-11 · Crawler AI: verifica degli IP sugli elenchi pubblicati dai fornitori (OpenAI, Anthropic, Perplexity, Google, DuckDuckGo, Common Crawl, Mistral; Amazon via DNS), visite false contate a parte ed escluse da get-ai-crawlers, campo ip nel middleware del README · none · ip nel middleware di calcolato, meltinbit.com, Fase 6
