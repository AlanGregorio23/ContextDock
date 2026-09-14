# ContextDock

> **Self-hosted Context & Memory Engine per Applicazioni AI Esterne**  
> Archivista locale, motore di retrieval semantico con **pgvector** e generatore deterministico di **Context Pack** per ChatGPT, Claude, Cursor, Copilot e agenti esterni.

---

## 💡 Filosofia e Confine del Prodotto

ContextDock nasce con una regola architetturale precisa: **nessuna chiamata diretta e nessuna dipendenza da API di provider AI esterni (OpenAI, Anthropic, Google, ecc.)**.

Il flusso è invertito rispetto ai sistemi tradizionali:
1. Le **applicazioni AI esterne** (client desktop, estensioni IDE, web app) interrogano ContextDock tramite **MCP (Model Context Protocol)** o **REST API**.
2. ContextDock esegue la ricerca vettoriale locale su **PostgreSQL 17 + pgvector** utilizzando gli embedding calcolati localmente da **Ollama** (`qwen3-embedding:0.6b` a 1024 dimensioni).
3. ContextDock applica uno scoring deterministico, filtra per proprietà e permessi, rispetta i limiti di token/byte e restituisce un **Context Pack** strutturato.
4. L'applicazione AI esterna riceve il Context Pack e lo utilizza per generare la risposta finale.

```
┌────────────────────────────────────────────────────────────┐
│      App AI Esterna (Claude Desktop, ChatGPT, IDE, Agente) │
└──────────────────────────────┬─────────────────────────────┘
                               │ MCP (HTTP / stdio bridge) o REST API
                               ▼
┌────────────────────────────────────────────────────────────┐
│                       ContextDock                          │
│  - Controllo Token Sanctum & Scopes (context:read / write) │
│  - Risoluzione Scoping (global, workspace, project, sess)  │
│  - Ranking deterministico & Budgeting Token/Byte           │
└──────────────┬───────────────────────────────┬─────────────┘
               │                               │
               ▼                               ▼
  ┌─────────────────────────┐     ┌─────────────────────────┐
  │  PostgreSQL 17+pgvector │     │    Ollama (locale)      │
  │  - Cosine Distance      │     │  - qwen3-embedding:0.6b │
  │  - Vettori 1024-d       │     │  - Solo embedding 1024d │
  │  - Materialized CTEs    │     │  - Zero chiamate cloud  │
  └─────────────────────────┘     └─────────────────────────┘
```

---

## 🛠️ Stack Tecnologico

- **Core & Backend**: Laravel 13, PHP 8.4 (PHP-FPM)
- **Database Relazionale & Vettoriale**: PostgreSQL 17 con estensione `pgvector`
- **Embedding Locale**: Ollama con modello `qwen3-embedding:0.6b`
- **Code, Cache & Sessioni**: Redis 7
- **Interfaccia MCP**: `laravel/mcp` su endpoint HTTP `/mcp` + Bridge stdio Node.js 22 per client desktop
- **Frontend & Dashboard**: Blade + Livewire + Alpine.js + Tailwind CSS (Tema Dark)
- **Orchestrazione**: Docker & Docker Compose multi-servizio

---

## 🚀 Guida Rapida all'Avvio

### 1. Prerequisiti
- **Docker** e **Docker Compose** installati e funzionanti.
- (Opzionale) **Node.js 22+** se si desidera eseguire localmente l'MCP stdio bridge per Claude Desktop.

### 2. Configurazione Ambiente
Se non è già presente, crea il file `.env` partendo dall'esempio:
```bash
cp .env.example .env
```
*(Nota: la porta predefinita per l'applicazione è configurata su `APP_PORT=8090` nel file `.env` per evitare conflitti con eventuali servizi sulla porta 8080).*

### 3. Avvio dei Servizi con Docker Compose
Avvia l'intero stack in background:
```bash
docker compose up -d
```
Questo comando avvia:
- `postgres` (pgvector 17)
- `redis` (Redis 7)
- `ollama` (Ollama 0.18)
- `init` (esegue automaticamente `php artisan migrate --force`)
- `app` (PHP 8.4 FPM)
- `worker` (Queue worker per elaborazione embedding asincroni)
- `scheduler` (Schedulazione e retention periodica)
- `nginx` (Server web esposto su `http://127.0.0.1:8090`)

### 4. Scaricare il Modello di Embedding Locale
Scarica il modello `qwen3-embedding:0.6b` all'interno del container Ollama (operazione richiesta solo al primo avvio):
```bash
docker compose exec ollama ollama pull qwen3-embedding:0.6b
```

### 5. Inserimento Dati Demo (Seeding)
Per creare l'utente di test, workspace e memorie dimostrative:
```bash
docker compose exec app php artisan db:seed
```
Credenziali predefinite create:
- **Email**: `test@example.com`
- **Password**: `password`

### 6. Accesso alla Dashboard
Apri il browser all'indirizzo:
```text
http://localhost:8090
```
Effettua il login per accedere alle sezioni:
- **Panoramica**: visualizzazione rapida di workspace e progetti.
- **Progetti**: gestione progetti e relative impostazioni.
- **Memorie**: archivio decisioni, note, preferenze e ricerca semantica vettoriale.
- **Documenti**: caricamento documenti di testo/codice/markdown con chunking automatico.
- **Context Inspector**: test in tempo reale delle query con calcolo similarità, debug dei ranking e stima token.
- **MCP Tokens**: creazione e revoca di Personal Access Token (Sanctum) con permessi `context:read` e `context:write`.
- **Audit & Backup**: storico eventi di accesso e generazione/scaricamento backup JSON.

---

## 🧩 Multilevel Scoping & Ranking

### Livelli di Scope
Ogni informazione in ContextDock rispetta rigorosamente una gerarchia di visibilità:
1. `global`: preferenze e regole dell'utente valide per tutti i progetti.
2. `workspace`: contesto condiviso all'interno di uno spazio di lavoro.
3. `project`: memorie, regole e decisioni legate al singolo progetto.
4. `session`: limitato alla specifica conversazione o sessione di lavoro in corso.

### Formula di Ranking Deterministico
I risultati delle ricerche semantiche vengono ordinati combinando cinque fattori pesati:
$$\text{Score} = (0.50 \times \text{Similarità Cosina}) + (0.20 \times \text{Importanza}) + (0.10 \times \text{Recency}) + (0.10 \times \text{Confidence}) + (0.10 \times \text{Frequenza d'uso})$$

Le memorie contrassegnate come **Pinned** hanno priorità assoluta e vengono sempre incluse nel Context Pack entro il limite di token impostato.

---

## 🤖 Integrazione MCP (Model Context Protocol)

ContextDock espone **7 strumenti MCP ufficiali**:

| Tool | Descrizione |
| --- | --- |
| `get_project_context` | Costruisce e restituisce il Context Pack completo per il progetto specificato, con stima token e budget. |
| `search_memory` | Esegue una ricerca semantica sulle memorie autorizzate tramite cosine distance su pgvector. |
| `store_memory` | Salva una nuova memoria (`decision`, `preference`, `architecture`, `note`, ecc.) e mette in coda la generazione dell'embedding. |
| `get_project_decisions` | Restituisce tutte le decisioni archiviate per il progetto corrente. |
| `get_pinned_memories` | Recupera le memorie fissate in alto (pinned) per il progetto o l'utente. |
| `get_recent_context` | Recupera le ultime conversazioni e memorie recenti ordinate per data. |
| `search_documents` | Esegue una ricerca vettoriale sui frammenti (chunk) dei documenti indicizzati. |

### Configurazione per Claude Desktop (o altri client MCP)

I client desktop comunicano con ContextDock tramite l'adattatore stdio Node.js incluso in `integrations/mcp-bridge`.

1. Genera un token Sanctum nella dashboard di ContextDock (`http://localhost:8090`) abilitando `context:read` (e `context:write` se il client deve salvare memorie).
2. Modifica il file di configurazione del client MCP (es. `claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "contextdock": {
      "command": "node",
      "args": [
        "C:/Users/TUO_UTENTE/.../ContextDock/integrations/mcp-bridge/index.mjs"
      ],
      "env": {
        "CONTEXTDOCK_URL": "http://127.0.0.1:8090/mcp",
        "CONTEXTDOCK_TOKEN": "1|tuo_sanctum_token_qui..."
      }
    }
  }
}
```

---

## 🌐 API REST Principali

Tutte le chiamate API richiedono l'header: `Authorization: Bearer <SANCTUM_TOKEN>`.

- `POST /api/memory/search`: Ricerca memorie (`project_id`, `query`, `limit`).
- `POST /api/memory/store`: Memorizzazione dato (`project_id`, `scope`, `type`, `title`, `content`).
- `POST /api/context`: Generazione Context Pack (`project_id`, `query`, `budget_tokens`).
- `POST /api/context/debug`: Generazione Context Pack con telemetria e scoring dettagliato.
- `GET  /api/projects/{project}/context`: Recupero rapido contesto progetto.
- `POST /api/documents`: Upload documento di testo o codice.
- `POST /api/conversations`: Creazione sessione di conversazione.
- `POST /api/conversations/{id}/messages`: Append messaggio raw.

---

## 🧪 Esecuzione dei Test

Tutti i test possono essere eseguiti localmente senza dipendenze esterne (utilizzando SQLite in memoria e mock controllati):

### Suite di Test Laravel (PHPUnit)
```bash
docker run --rm -v "%cd%:/app" -w /app composer:2 php artisan test
```
*Oppure sul container attivo:*
```bash
docker compose exec app php artisan test
```

### Suite di Test MCP Bridge (Node.js)
```bash
cd integrations/mcp-bridge
npm test
```

---

## 💾 Backup e Retention

- **Backup Manuale**: Può essere avviato da dashboard o tramite il job `CreateBackup`, generando un archivio JSON su storage isolato.
- **Retention Automatica**: Il comando schedulato (eseguito giornalmente dal container `scheduler`) ripulisce i vecchi Context Runs oltre i 30 giorni e gli audit log oltre i 90 giorni.

---

## 📄 Licenza

Rilasciato sotto licenza MIT.
