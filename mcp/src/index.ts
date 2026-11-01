#!/usr/bin/env node
/**
 * EnStorage MCP server entry point.
 *
 * Usage:
 *   ENSTORAGE_API_KEY=en_xxx ENSTORAGE_URL=https://enstorage.enpiistudio.com/api/v1 \
 *     node ./dist/index.js
 *
 * Speaks the Model Context Protocol over stdio (StdioServerTransport).
 */

import 'dotenv/config';
import { pathToFileURL } from 'node:url';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { EnStorageClient, EnStorageError, loadConfig } from './client.js';
import { registerPrompts } from './prompts.js';
import { registerResources } from './resources.js';
import { registerTools } from './tools.js';

export const SERVER_NAME = 'enstorage-mcp';
export const SERVER_VERSION = '1.0.0';

/**
 * Build a fully configured MCP server. Throws if `ENSTORAGE_API_KEY` is missing.
 */
export function createServer(client?: EnStorageClient): McpServer {
  const resolvedClient = client ?? EnStorageClient.fromEnv();

  const server = new McpServer(
    {
      name: SERVER_NAME,
      title: 'EnStorage',
      version: SERVER_VERSION,
    },
    {
      instructions:
        'Server MCP untuk EnStorage. Gunakan tool enstorage_* untuk mencari, membaca, mengunggah, dan mengelola berkas. ' +
        'ID berkas berbentuk UUID dan bisa diperoleh dari enstorage_list_files atau enstorage_search_files.',
    },
  );

  registerTools(server, resolvedClient);
  registerResources(server, resolvedClient);
  registerPrompts(server);

  return server;
}

/**
 * Connect the server to a stdio transport (used when run as a CLI).
 */
export async function runStdio(client?: EnStorageClient, server?: McpServer): Promise<McpServer> {
  const instance = server ?? createServer(client);
  const transport = new StdioServerTransport();
  await instance.connect(transport);
  return instance;
}

function isMainModule(): boolean {
  const entry = process.argv[1];
  if (!entry) return false;
  try {
    // Works for both `node dist/index.js` and the `enstorage-mcp` bin shim.
    return import.meta.url === pathToFileURL(entry).href || entry.endsWith('dist/index.js');
  } catch {
    return false;
  }
}

async function main(): Promise<void> {
  try {
    loadConfig();
  } catch (error) {
    const message = error instanceof EnStorageError ? error.message : String(error);
    process.stderr.write(`[enstorage-mcp] Konfigurasi tidak valid: ${message}\n`);
    process.stderr.write(
      '[enstorage-mcp] Set ENSTORAGE_API_KEY (format en_...) dan opsional ENSTORAGE_URL lalu jalankan ulang.\n',
    );
    process.exitCode = 1;
    return;
  }

  const server = await runStdio();
  process.stderr.write(`[enstorage-mcp] v${SERVER_VERSION} siap (stdio).\n`);

  const shutdown = async (signal: string): Promise<void> => {
    process.stderr.write(`[enstorage-mcp] Menerima ${signal}, menutup server...\n`);
    try {
      await server.close();
    } finally {
      process.exit(0);
    }
  };
  process.on('SIGINT', () => void shutdown('SIGINT'));
  process.on('SIGTERM', () => void shutdown('SIGTERM'));
}

if (isMainModule()) {
  void main().catch((error: unknown) => {
    const message = error instanceof Error ? (error.stack ?? error.message) : String(error);
    process.stderr.write(`[enstorage-mcp] Fatal: ${message}\n`);
    process.exit(1);
  });
}
