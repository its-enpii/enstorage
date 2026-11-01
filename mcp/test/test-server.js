#!/usr/bin/env node
/**
 * End-to-end verification for the EnStorage MCP server.
 *
 * 1. Starts a mock EnStorage REST API (node:http) that honours the
 *    `{ success, data, message, meta }` envelope and the `Bearer en_...` API key.
 * 2. Spawns `dist/index.js` over stdio and talks to it with the official
 *    @modelcontextprotocol/sdk client.
 * 3. Asserts server initialization, tool/resource/prompt listing, tool calls,
 *    resource reads, prompt rendering, and error propagation for `!success`.
 *
 * Run with: npm run build && npm test
 */

import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const SERVER_ENTRY = path.join(ROOT, 'dist', 'index.js');
const API_KEY = 'en_testkey1_abcdefghijklmnopqrstuvwxyz0123456789ab';

const EXPECTED_TOOLS = [
  'enstorage_get_dashboard',
  'enstorage_list_files',
  'enstorage_search_files',
  'enstorage_get_file_detail',
  'enstorage_read_file',
  'enstorage_upload_file',
  'enstorage_create_folder',
  'enstorage_create_share_link',
];

let passed = 0;
let failed = 0;
const failures = [];

function test(name, fn) {
  return Promise.resolve()
    .then(fn)
    .then(() => {
      passed += 1;
      console.log(`  ✓ ${name}`);
    })
    .catch((error) => {
      failed += 1;
      failures.push({ name, error });
      console.log(`  ✗ ${name}\n      ${error && error.message ? error.message : error}`);
    });
}

// ---------------------------------------------------------------------------
// Mock EnStorage API
// ---------------------------------------------------------------------------

const FIXTURE_TEXT_ID = '11111111-1111-4111-8111-111111111111';
const FIXTURE_BINARY_ID = '22222222-2222-4222-8222-222222222222';
const FIXTURE_MISSING_ID = '33333333-3333-4333-8333-333333333333';

const state = {
  uploads: [],
  folders: [],
  shareLinks: [],
};

function json(res, status, body) {
  const payload = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) });
  res.end(payload);
}

function envelope(res, status, data, message = 'OK', meta = {}) {
  json(res, status, { success: true, data, message, meta });
}

function fileResource(id, overrides = {}) {
  return {
    id,
    name: 'catatan.md',
    original_name: 'catatan.md',
    mime_type: 'text/markdown',
    size: 23,
    folder_id: null,
    upload_status: 'done',
    is_starred: false,
    has_thumbnail: false,
    share_token: null,
    metadata: null,
    created_at: '2026-10-01T00:00:00+00:00',
    updated_at: '2026-10-01T00:00:00+00:00',
    ...overrides,
  };
}

async function readBody(req) {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  return Buffer.concat(chunks);
}

function createMockApi() {
  return createServer(async (req, res) => {
    const url = new URL(req.url ?? '/', 'http://localhost');
    const route = `${req.method} ${url.pathname}`;

    if (req.headers.authorization !== `Bearer ${API_KEY}`) {
      return json(res, 401, { success: false, data: null, message: 'Unauthenticated.', meta: {} });
    }

    const p = url.pathname;

    if (route === 'GET /api/v1/dashboard') {
      return envelope(res, 200, {
        storage: { total: 32212254720, used: 10485760000, free: 21726494720, percentage: 32.5, accounts_count: 3 },
        categories: [
          { type: 'doc', count: 42, size: 991232 },
          { type: 'image', count: 795, size: 1258291200 },
        ],
        recent_files: [fileResource(FIXTURE_TEXT_ID)],
        summary: { files_count: 864, files_size: 4075199460, folders_count: 24, accounts_count: 3 },
      }, 'Dashboard berhasil dimuat.');
    }

    if (route === 'GET /api/v1/files') {
      const type = url.searchParams.get('type');
      if (type === 'image') {
        return envelope(res, 200, [], 'Daftar berkas.', { pagination: { current_page: 1, per_page: 25, total: 0, last_page: 1 } });
      }
      return envelope(res, 200, [fileResource(FIXTURE_TEXT_ID)], 'Daftar berkas.', {
        pagination: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
        echo: { per_page: url.searchParams.get('per_page'), folder_id: url.searchParams.get('folder_id') },
      });
    }

    if (route === 'GET /api/v1/search/files') {
      const q = url.searchParams.get('q') ?? '';
      if (q === 'zzzzz') {
        return envelope(res, 200, [], 'Hasil pencarian.', {
          query: q,
          pagination: { page: 1, per_page: 25, total: 0, last_page: 1 },
          did_you_mean: [{ name: 'laporan tahunan.pdf', score: 0.31 }],
        });
      }
      return envelope(
        res,
        200,
        [fileResource(FIXTURE_TEXT_ID, { name: 'Laporan Q1.md', highlight: '**Laporan** Q1.md', score: 0.875 })],
        'Hasil pencarian.',
        { query: q, query_normalized: q.toLowerCase(), meta_echo: { recursive: url.searchParams.get('recursive') } },
      );
    }

    const detailMatch = p.match(/^\/api\/v1\/files\/([^/]+)\/detail$/);
    if (req.method === 'GET' && detailMatch) {
      const id = decodeURIComponent(detailMatch[1]);
      if (id === FIXTURE_MISSING_ID) {
        // HTTP 200 with a `!success` envelope: the client must still treat it as an error.
        return json(res, 200, { success: false, data: null, message: 'File tidak ditemukan.', meta: {} });
      }
      return envelope(res, 200, {
        id,
        name: id === FIXTURE_BINARY_ID ? 'diagram.png' : 'catatan.md',
        mime_type: id === FIXTURE_BINARY_ID ? 'image/png' : 'text/markdown',
        size: 23,
        folder: { id: 'folder-1', name: 'Documents', path: 'Work/Documents' },
        dimensions: id === FIXTURE_BINARY_ID ? { width: 640, height: 480 } : null,
        pages: null,
        upload_status: 'done',
        is_starred: false,
        has_thumbnail: id === FIXTURE_BINARY_ID,
      }, 'Detail file.');
    }

    const downloadMatch = p.match(/^\/api\/v1\/files\/([^/]+)\/download$/);
    if (req.method === 'GET' && downloadMatch) {
      const id = decodeURIComponent(downloadMatch[1]);
      if (id === FIXTURE_MISSING_ID) {
        return json(res, 404, { success: false, data: null, message: 'File tidak ditemukan.', meta: {} });
      }
      if (id === FIXTURE_BINARY_ID) {
        const bytes = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0xff, 0x10]);
        res.writeHead(200, { 'Content-Type': 'image/png', 'Content-Length': bytes.length });
        return res.end(bytes);
      }
      const text = '# Catatan\nHalo EnStorage!';
      res.writeHead(200, { 'Content-Type': 'text/markdown; charset=utf-8', 'Content-Length': Buffer.byteLength(text) });
      return res.end(text);
    }

    const fileMatch = p.match(/^\/api\/v1\/files\/([^/]+)$/);
    if (req.method === 'GET' && fileMatch) {
      return envelope(res, 200, fileResource(decodeURIComponent(fileMatch[1])), 'Detail file.');
    }

    if (route === 'POST /api/v1/files/upload') {
      const contentType = req.headers['content-type'] ?? '';
      if (!contentType.startsWith('multipart/form-data')) {
        return json(res, 422, { success: false, data: { errors: { file: ['The file field is required.'] } }, message: 'Validasi gagal.', meta: {} });
      }
      const body = await readBody(req);
      const raw = body.toString('latin1');
      const nameMatch = raw.match(/name="file"; filename="([^"]+)"/);
      const name = nameMatch ? nameMatch[1] : null;
      if (!name) {
        return json(res, 422, { success: false, data: { errors: { file: ['The file field is required.'] } }, message: 'Validasi gagal.', meta: {} });
      }
      state.uploads.push({ name, folderId: /name="folder_id"\r\n\r\n([^\r\n]+)/.exec(raw)?.[1] ?? null, size: body.length });
      return envelope(res, 202, {
        accepted: [{ file_id: FIXTURE_TEXT_ID, name, size: body.length, status: 'pending', shareable: true, share_token: 'abc', share_url: 'https://app.enstorage.id/s/abc' }],
        rejected: [],
        count: 1,
      }, 'File berhasil diupload.');
    }

    if (route === 'GET /api/v1/folders') {
      return envelope(res, 200, [{ id: 'folder-1', name: 'Documents', path: '/Documents', parent_id: null }], 'Daftar folder.');
    }

    if (route === 'POST /api/v1/folders') {
      const raw = await readBody(req);
      let body = {};
      try {
        body = JSON.parse(raw.toString('utf-8'));
      } catch {
        body = {};
      }
      if (body.name === 'Duplicate') {
        return json(res, 409, { success: false, data: null, message: 'Folder dengan nama ini sudah ada.', meta: {} });
      }
      const folder = { id: 'folder-new', name: body.name, parent_id: body.parent_id ?? null, path: `/${body.name}` };
      state.folders.push(folder);
      return envelope(res, 201, folder, 'Folder berhasil dibuat.');
    }

    const shareMatch = p.match(/^\/api\/v1\/files\/([^/]+)\/share-links$/);
    if (req.method === 'POST' && shareMatch) {
      const body = JSON.parse((await readBody(req)).toString('utf-8') || '{}');
      if (body.expires_at && new Date(body.expires_at).getTime() <= Date.now()) {
        return json(res, 422, { success: false, data: { errors: { expires_at: ['expires_at harus di masa depan.'] } }, message: 'Validasi gagal.', meta: {} });
      }
      const link = {
        id: 'link-1',
        token: 'tok123',
        url: 'https://enstorage.test/s/tok123',
        preview_url: 'https://enstorage.test/s/tok123?view=1',
        expires_at: body.expires_at ?? null,
        max_views: body.max_views ?? null,
        views_count: 0,
        revoked_at: null,
        is_active: true,
      };
      state.shareLinks.push({ fileId: decodeURIComponent(shareMatch[1]), ...link });
      return envelope(res, 201, link, 'Share link berhasil dibuat.');
    }

    return json(res, 404, { success: false, data: null, message: `Route ${route} tidak ditemukan.`, meta: {} });
  });
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function textOf(result) {
  return (result.content ?? []).map((c) => c.text ?? '').join('\n');
}

async function main() {
  console.log('EnStorage MCP — end-to-end test\n');

  const api = createMockApi();
  await new Promise((resolve) => api.listen(0, '127.0.0.1', resolve));
  const address = api.address();
  const baseUrl = `http://127.0.0.1:${address.port}/api/v1`;
  console.log(`Mock EnStorage API: ${baseUrl}\n`);

  const transport = new StdioClientTransport({
    command: process.execPath,
    args: [SERVER_ENTRY],
    cwd: ROOT,
    env: { ...process.env, ENSTORAGE_URL: baseUrl, ENSTORAGE_API_KEY: API_KEY },
    stderr: 'pipe',
  });
  const client = new Client({ name: 'enstorage-mcp-test', version: '1.0.0' });

  try {
    await test('server initializes over stdio', async () => {
      await client.connect(transport);
      const info = client.getServerVersion();
      assert.equal(info?.name, 'enstorage-mcp');
      assert.equal(info?.version, '1.0.0');
    });

    await test('lists the 8 expected tools', async () => {
      const { tools } = await client.listTools();
      const names = tools.map((t) => t.name).sort();
      assert.deepEqual(names, [...EXPECTED_TOOLS].sort());
      for (const tool of tools) {
        assert.ok(tool.description && tool.description.length > 10, `tool ${tool.name} has a description`);
      }
      const detail = tools.find((t) => t.name === 'enstorage_get_file_detail');
      assert.deepEqual(detail.inputSchema.required, ['file_id']);
    });

    await test('lists resources (vault + file template)', async () => {
      const { resourceTemplates } = await client.listResourceTemplates();
      const { resources } = await client.listResources();
      const uris = [...resourceTemplates.map((r) => r.uriTemplate), ...resources.map((r) => r.uri)];
      assert.ok(uris.includes('enstorage://vault'), `enstorage://vault not found in ${uris.join(', ')}`);
      assert.ok(uris.includes('enstorage://file/{id}'), `enstorage://file/{id} not found in ${uris.join(', ')}`);
    });

    await test('lists prompts (summarize_document)', async () => {
      const { prompts } = await client.listPrompts();
      const prompt = prompts.find((p) => p.name === 'summarize_document');
      assert.ok(prompt, 'summarize_document missing');
      assert.ok(prompt.arguments.some((a) => a.name === 'file_id'));
    });

    await test('enstorage_get_dashboard returns quota summary', async () => {
      const result = await client.callTool({ name: 'enstorage_get_dashboard', arguments: {} });
      assert.notEqual(result.isError, true);
      const text = textOf(result);
      assert.match(text, /Storage:/);
      assert.equal(result.structuredContent.dashboard.storage.percentage, 32.5);
    });

    await test('enstorage_list_files returns files + pagination', async () => {
      const result = await client.callTool({
        name: 'enstorage_list_files',
        arguments: { per_page: 10, folder_id: '' },
      });
      assert.notEqual(result.isError, true);
      assert.match(textOf(result), /catatan\.md/);
      assert.match(textOf(result), /halaman 1\/1, total 1/);
      assert.equal(result.structuredContent.files.length, 1);
    });

    await test('enstorage_search_files returns highlight + did-you-mean', async () => {
      const hit = await client.callTool({ name: 'enstorage_search_files', arguments: { query: 'lapor' } });
      assert.match(textOf(hit), /\*\*Laporan\*\* Q1\.md/);

      const miss = await client.callTool({ name: 'enstorage_search_files', arguments: { query: 'zzzzz', recursive: true } });
      assert.match(textOf(miss), /Mungkin maksud Anda: laporan tahunan\.pdf/);
    });

    await test('enstorage_get_file_detail returns folder path', async () => {
      const result = await client.callTool({ name: 'enstorage_get_file_detail', arguments: { file_id: FIXTURE_TEXT_ID } });
      assert.match(textOf(result), /Work\/Documents/);
      assert.equal(result.structuredContent.file.id, FIXTURE_TEXT_ID);
    });

    await test('enstorage_read_file returns text content', async () => {
      const result = await client.callTool({ name: 'enstorage_read_file', arguments: { file_id: FIXTURE_TEXT_ID } });
      assert.match(textOf(result), /Halo EnStorage!/);
      assert.equal(result.structuredContent.kind, 'text');
      assert.equal(result.structuredContent.truncated, false);
    });

    await test('enstorage_read_file honours max_bytes truncation', async () => {
      const result = await client.callTool({ name: 'enstorage_read_file', arguments: { file_id: FIXTURE_TEXT_ID, max_bytes: 5 } });
      assert.equal(result.structuredContent.truncated, true);
      assert.equal(result.structuredContent.bytes_read, 5);
      assert.equal(result.structuredContent.text, '# Cat');
    });

    await test('enstorage_read_file reports binary content as base64', async () => {
      const result = await client.callTool({ name: 'enstorage_read_file', arguments: { file_id: FIXTURE_BINARY_ID } });
      assert.equal(result.structuredContent.kind, 'binary');
      assert.match(textOf(result), /\[binary content: image\/png/);
      assert.equal(Buffer.from(result.structuredContent.base64, 'base64').toString('hex').slice(0, 8), '89504e47');
    });

    await test('enstorage_upload_file sends multipart form data', async () => {
      const result = await client.callTool({
        name: 'enstorage_upload_file',
        arguments: { name: 'ringkasan.md', content: '# Ringkasan\nisi', folder_id: 'folder-1' },
      });
      assert.notEqual(result.isError, true);
      assert.match(textOf(result), /Upload "ringkasan\.md" diterima/);
      const upload = state.uploads.at(-1);
      assert.ok(upload, 'upload not received by mock API');
      assert.equal(upload.name, 'ringkasan.md');
      assert.equal(upload.folderId, 'folder-1');
    });

    await test('enstorage_create_folder creates a folder', async () => {
      const result = await client.callTool({ name: 'enstorage_create_folder', arguments: { name: 'Arsip 2026' } });
      assert.match(textOf(result), /Folder "Arsip 2026" dibuat/);
      assert.equal(state.folders.at(-1).name, 'Arsip 2026');
    });

    await test('enstorage_create_share_link sets expiry from hours', async () => {
      const result = await client.callTool({
        name: 'enstorage_create_share_link',
        arguments: { file_id: FIXTURE_TEXT_ID, expires_in_hours: 24, max_views: 5 },
      });
      assert.match(textOf(result), /https:\/\/enstorage\.test\/s\/tok123/);
      const link = state.shareLinks.at(-1);
      assert.equal(link.max_views, 5);
      const deltaHours = (new Date(link.expires_at).getTime() - Date.now()) / 3_600_000;
      assert.ok(deltaHours > 23.9 && deltaHours <= 24, `expiry delta was ${deltaHours}h`);
    });

    await test('reads enstorage://vault resource', async () => {
      const result = await client.readResource({ uri: 'enstorage://vault' });
      const text = result.contents[0].text;
      assert.match(text, /EnStorage Vault/);
      assert.match(text, /Terpakai\s+: 9\.8 GB/);
    });

    await test('reads enstorage://file/{id} resource', async () => {
      const result = await client.readResource({ uri: `enstorage://file/${FIXTURE_TEXT_ID}` });
      const text = result.contents[0].text;
      assert.match(text, /Work\/Documents/);
      assert.match(text, /Halo EnStorage!/);
    });

    await test('renders summarize_document prompt', async () => {
      const result = await client.getPrompt({ name: 'summarize_document', arguments: { file_id: FIXTURE_TEXT_ID } });
      const text = result.messages[0].content.text;
      assert.match(text, /enstorage_read_file/);
      assert.ok(text.includes(FIXTURE_TEXT_ID));
    });

    await test('propagates API errors (404 -> isError)', async () => {
      const result = await client.callTool({ name: 'enstorage_get_file_detail', arguments: { file_id: FIXTURE_MISSING_ID } });
      assert.ok(result.isError, 'expected isError for upstream 404');
    });

    await test('surfaces 409 conflict message from !success envelope', async () => {
      const result = await client.callTool({ name: 'enstorage_create_folder', arguments: { name: 'Duplicate' } });
      assert.equal(result.isError, true);
      assert.match(textOf(result), /sudah ada/);
    });

    await test('rejects invalid tool input via schema validation', async () => {
      let error = null;
      try {
        await client.callTool({ name: 'enstorage_read_file', arguments: { file_id: '', max_bytes: -1 } });
      } catch (caught) {
        error = caught;
      }
      // The SDK may either throw or return an isError result; both are acceptable.
      if (!error) {
        const result = await client.callTool({ name: 'enstorage_read_file', arguments: { file_id: '', max_bytes: -1 } });
        assert.equal(result.isError, true);
      }
    });
  } finally {
    await client.close().catch(() => {});
    await new Promise((resolve) => api.close(resolve));
  }

  console.log(`\n${passed} passed, ${failed} failed`);
  if (failed > 0) {
    for (const { name, error } of failures) {
      console.error(`\nFAILED: ${name}\n${error && error.stack ? error.stack : error}`);
    }
    process.exit(1);
  }
}

main().catch((error) => {
  console.error('Test harness crashed:', error);
  process.exit(1);
});
