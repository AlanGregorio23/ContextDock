import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { CallToolRequestSchema, ListToolsRequestSchema } from '@modelcontextprotocol/sdk/types.js';

test('official SDK bridge forwards authenticated discovery and tool calls over HTTP', async (t) => {
  const secret = 'test-only-contextdock-token';
  const requests = [];
  const upstream = new Server({ name: 'test-contextdock', version: '1.0.0' }, { capabilities: { tools: {} } });
  upstream.setRequestHandler(ListToolsRequestSchema, () => ({ tools: [{
    name: 'search_memory', description: 'Test memory search',
    inputSchema: { type: 'object', properties: { project_id: { type: 'integer' } }, required: ['project_id'] },
  }] }));
  upstream.setRequestHandler(CallToolRequestSchema, (request) => ({
    content: [{ type: 'text', text: JSON.stringify(request.params.arguments) }],
  }));
  const httpTransport = new StreamableHTTPServerTransport({ sessionIdGenerator: randomUUID, enableJsonResponse: true });
  await upstream.connect(httpTransport);
  const http = createServer(async (request, response) => {
    requests.push(request.headers.authorization);
    if (request.headers.authorization !== `Bearer ${secret}`) {
      response.writeHead(401).end();
      return;
    }
    try {
      const chunks = [];
      for await (const chunk of request) chunks.push(chunk);
      const body = chunks.length ? JSON.parse(Buffer.concat(chunks).toString()) : undefined;
      await httpTransport.handleRequest(request, response, body);
    } catch {
      if (!response.headersSent) response.writeHead(500);
      response.end();
    }
  });
  await new Promise((resolve) => http.listen(0, '127.0.0.1', resolve));
  const client = new Client({ name: 'test-desktop', version: '1.0.0' });
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: [fileURLToPath(new URL('../index.mjs', import.meta.url))],
    env: { ...process.env, CONTEXTDOCK_URL: `http://127.0.0.1:${http.address().port}/mcp`, CONTEXTDOCK_TOKEN: secret },
    stderr: 'pipe',
  });
  let stderr = '';
  transport.stderr?.on('data', (chunk) => { stderr += chunk; });
  t.after(async () => {
    await client.close();
    await upstream.close();
    http.closeAllConnections();
    await new Promise((resolve) => http.close(resolve));
  });
  try {
    await client.connect(transport);
  } catch (error) {
    throw new Error(`Bridge initialization failed: ${stderr}`, { cause: error });
  }
  const tools = await client.listTools();
  assert.equal(tools.tools[0].name, 'search_memory');
  const result = await client.callTool({ name: 'search_memory', arguments: { project_id: 7 } });
  assert.deepEqual(JSON.parse(result.content[0].text), { project_id: 7 });
  assert.ok(requests.length >= 3);
  assert.ok(requests.every((value) => value === `Bearer ${secret}`));
  assert.ok(!stderr.includes(secret));
});
