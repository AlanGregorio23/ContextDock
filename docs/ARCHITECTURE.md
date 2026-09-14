# ContextDock — architettura MVP

Decisione del 14 settembre 2026. Questo documento sostituisce il flusso con chiamate dirette ai provider del brief originale.

## Confine del prodotto

ContextDock è un archivio e motore di contesto self-hosted. Non genera risposte finali, non chiama OpenAI/Anthropic/Google e non richiede le loro chiavi. Le AI nelle rispettive app richiedono contesto a ContextDock. La cronologia delle app esterne non è automaticamente accessibile: si conserva solo ciò che viene esplicitamente importato o inviato alle API di ContextDock.

## Responsabilità e stack

| Componente | Responsabilità |
| --- | --- |
| Laravel 13, PHP 8.4 | Autenticazione, proprietà dati, workspace/progetti, CRUD, validazione, ranking, Context Pack, audit, queue, backup |
| Blade, Livewire, Alpine, Tailwind | Dashboard, gestione e Context Inspector; nessun bottone Send to AI |
| PostgreSQL 17 + pgvector | Dati RAW e memorie separati; vettori a 1024 dimensioni; cosine distance |
| Redis 7 | Queue, cache e sessioni |
| Ollama, qwen3-embedding:0.6b | Solo embedding di query, memorie e chunk; non classifica e non genera testo |
| laravel/mcp | Trasporto MCP, validazione protocollare, tool sottili sopra i Service |
| App AI esterna | Richiede i tool, interpreta il Context Pack e genera la risposta |

Monolite, nessun repository pattern o database vettoriale separato. Il modello di embedding è registrato con ogni vettore. Un cambio modello richiede reindicizzazione; non si confrontano vettori di modelli diversi.

## Schema e migrations

Le migrations Laravel base creano users, session/reset, cache, jobs e failed_jobs. La migration dominio abilita vector e crea:

| Tabella | Campi principali / vincoli |
| --- | --- |
| workspaces | id, user_id, name |
| projects | id, user_id, workspace_id, name, description, settings JSON; relazione workspace/proprietario coerente |
| conversations | id, user_id, workspace_id, project_id, title, source |
| messages | id, conversation_id, role, content, metadata JSON, created_at; append-only |
| memories | id, user_id, workspace_id?, project_id?, conversation_id?, scope, type, title, content, summary?, importance, confidence, source_type, source_id?, embedding vector(1024)?, embedding_model?, embedding_status, metadata JSON, is_pinned, expires_at?, last_used_at?, usage_count, timestamps |
| documents | id, user_id, workspace_id, project_id, name, path, status, error?, timestamps |
| document_chunks | id, document_id, position, content, embedding vector(1024), embedding_model; unico documento/posizione |
| context_runs | id, user_id, project_id, query, pack JSON, debug JSON, estimated_tokens, timestamps |
| audit_logs | id, user_id?, action, subject_type?, subject_id?, metadata JSON, created_at; nessuna credenziale/payload RAW |
| personal_access_tokens | Sanctum: hash del token, capacità di lettura/scrittura, scadenza e revoca |

Scope esplicito: global = solo user; workspace = user+workspace; project = user+workspace+project; session = anche conversation. Nessun parametro user_id inviato dal client è attendibile. Proprietario ricavato dalla sessione/token. MVP senza condivisione fra utenti; schema pronto per membership successive. FK composite e CHECK proteggono la coerenza dei livelli.

Retrieval: filtro per proprietario e livelli accessibili prima della distanza, scadenze escluse. CTE MATERIALIZED per ricerca esatta sul sottoinsieme autorizzato; B-tree sui campi di scope. Evitiamo HNSW globale nel primo MVP per non perdere recall dopo filtri stretti. HNSW/partizionamento saranno introdotti con benchmark realistici. Pinned inclusi anche se embedding pendente, sempre entro scope, scadenza e budget.

## Directory

```text
app/Contracts/           contratto embedding sostituibile
app/Data/                scope e input condivisi
app/Services/            WorkspaceService, MemoryService, EmbeddingService,
                         RetrievalService, ContextRankingService, ContextBuilder,
                         DocumentService, ConversationService, BackupService
app/Models/              Eloquent e cast, vettori mai serializzati per default
app/Policies/            proprietà dei dati
app/Http/Requests/       validazione HTTP
app/Http/Controllers/    adattatori HTTP sottili
app/Livewire/            interazioni dashboard
app/Mcp/Servers/         registrazione server
app/Mcp/Tools/           adattatori tool verso i Service
app/Jobs/                embedding, documenti, backup
resources/views/        layout e dashboard Blade
routes/{web,api,ai}.php  tre interfacce sopra il medesimo core
docker/                 PHP, nginx e bootstrap
integrations/           bridge stdio e guida client
tests/Feature/          isolamento, retrieval pgvector, API/MCP e UI
```

## Docker Compose proposto

Servizi app (PHP-FPM), nginx (unica porta host, loopback predefinito 8080), postgres, redis, ollama, worker, scheduler e init (one-shot migrations). App/worker/scheduler condividono la stessa immagine. Named volumes distinti per PostgreSQL, Redis, Ollama, storage Laravel, documenti e backup. Dipendenze con healthcheck; niente password di default distribuite. Setup genera segreti locali esclusi da git. `down` senza `-v` conserva i dati. Modello scaricato esplicitamente durante installazione, non a ogni avvio. CPU di default, nessuna GPU obbligatoria.

## Flusso completo

1. App AI → MCP autenticato o bridge stdio → tool (es. get_project_context).
2. Token ContextDock o sessione identifica l'utente; capacità e proprietà progetto verificate.
3. Scope risolto lato server, inclusione opzionale del contesto della sola sessione selezionata.
4. Ollama genera embedding della query; la ricerca filtra SQL, poi applica cosine distance su memorie e chunk autorizzati.
5. Ranking deterministico: similarità .50, importanza .20, recency .10, confidence .10, uso .10; pesi configurabili. Pinned prioritari ma mai fuori budget.
6. ContextBuilder deduplica, seleziona risultati, applica budget byte/token stimati sull'intero JSON, registra inclusioni/esclusioni e provenienza.
7. Context Pack versionato: project, global_preferences, pinned_memories, relevant_memories, decisions, documents, conversation_context, metadata.
8. Audit e statistiche di retrieval salvati. Tool restituisce il pack, l'app AI risponde autonomamente.
9. Eventuali messaggi RAW o memorie sono salvati solo con una chiamata esplicita autorizzata. Nessuna estrazione automatica implicita.

L'Inspector mostra query, modello/dimensioni, candidati, similarità, ranking, esclusioni, contenuto finale e stima token. Consente copia/esportazione del pack; non invia ad AI esterne. Contenuti recuperati sono dati non fidati, non istruzioni di sistema.

## Interfacce

MCP: search_memory, store_memory, get_project_context, search_documents, get_project_decisions, get_pinned_memories, get_recent_context. Integrazioni desktop tramite bridge stdio → HTTP MCP autenticato. Token ContextDock diversi da API key di provider; capability read/write e scadenza. Nessun accesso anonimo a dati.

API: POST /api/memory/search, /api/memory/store, /api/context, /api/context/debug, /api/documents; GET /api/projects/{project}/context; endpoint CRUD e append RAW. Service riutilizzabili in futuro da skill/plugin senza dipendenza da MCP.

MCP HTTP con Bearer non implica compatibilità con ogni app. Client cloud non raggiungono localhost: HTTPS e OAuth/Passport sono una milestone dedicata. Non si pubblica automaticamente la macchina su Internet.

## Milestone e criteri di completamento

1. **Fondazioni**: Docker installabile, login, workspace/progetti, ownership e migrations pgvector.
2. **Memoria e retrieval**: CRUD multilivello, embedding queue, ricerca semantica e pinned, isolamento verificato su PostgreSQL reale.
3. **Context e integrazione MVP**: Context Pack limitato, Inspector, conversazioni RAW, sette tool MCP, token revocabili, bridge client e test protocollo.
4. **Documenti iniziali e operatività**: TXT/Markdown/codice, chunking e ricerca, audit/jobs, backup manuale con procedura restore. PDF/DOCX, scheduling/retention articolata e statistiche avanzate successive.
5. **Compatibilità estesa**: OAuth per connector remoti, membership, PDF/DOCX, estrazione opzionale con modello generativo separato, routing di operazioni locali, benchmark a 250k elementi.

L'implementazione corrente copre 1–3 e una base operativa della 4. Le funzioni non presenti devono essere dichiarate, mai mostrate come già operative.

## Riferimenti verificati

- https://laravel.com/framework/docs/13.x/mcp
- https://laravel.com/framework/docs/releases
- https://docs.ollama.com/api/embed
- https://ollama.com/library/qwen3-embedding
- https://github.com/pgvector/pgvector
