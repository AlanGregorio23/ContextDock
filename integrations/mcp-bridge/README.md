# ContextDock MCP bridge

Small transport adapter for desktop clients that launch a local MCP process. Laravel remains responsible for authentication, authorization, retrieval, memory writes, and Context Packs. No external AI API key is used.

Requires Node.js 22 or newer and a running ContextDock installation:

```sh
cd integrations/mcp-bridge
npm ci
npm test
```

Create a ContextDock personal access token with `context:read`. Add `context:write` only when the client should save memories. Configure the token through your client's secret/environment settings; do not commit it or put it in command-line arguments.

Example MCP client configuration (replace the absolute path and token locally):

```json
{
  "mcpServers": {
    "contextdock": {
      "command": "node",
      "args": ["C:/absolute/path/ContextDock/integrations/mcp-bridge/index.mjs"],
      "env": {
        "CONTEXTDOCK_URL": "http://127.0.0.1:8080/mcp",
        "CONTEXTDOCK_TOKEN": "YOUR_CONTEXTDOCK_PERSONAL_ACCESS_TOKEN"
      }
    }
  }
}
```

`CONTEXTDOCK_URL` is the complete MCP endpoint, including `/mcp`. HTTP is accepted only for loopback; remote endpoints require HTTPS. Redirects are rejected so credentials are never forwarded to a redirected endpoint. Standard output contains SDK protocol messages only.

The server exposes `search_memory`, `store_memory`, `get_project_context`, `search_documents`, `get_project_decisions`, `get_pinned_memories`, and `get_recent_context`. Supply the project ID shown in ContextDock. Search tools also require `query`; optional `conversation_id` includes only that session's context. Document search requires indexed documents. Storing a memory queues local embedding generation and does not immediately guarantee semantic availability.

Clients that support Streamable HTTP and custom bearer headers can connect directly to `/mcp` without this bridge. Sanctum tokens are ContextDock credentials, not AI provider credentials.

## Remote AI applications

A cloud-hosted application cannot reach your PC's `localhost`. This bridge works only when the client can execute a local stdio process. Remote applications need a reachable HTTPS MCP endpoint plus an authentication flow they support. Some clients require OAuth; OAuth discovery/authorization is a future adapter capability and is not implemented by this MVP. Do not assume universal ChatGPT/Claude compatibility from MCP support alone. Publishing the endpoint is a separate deployment decision.

Browser-origin requests are allowed only for `APP_URL` by default. Additional trusted origins may be configured using the Laravel `contextdock.mcp.allowed_origins` array. Native clients normally omit `Origin`; bearer authentication remains required.

Laravel MCP and this bridge's official SDK versions are locked in their respective lockfiles. The bridge uses protocol negotiation implemented by the SDK, not a custom JSON-RPC parser.
