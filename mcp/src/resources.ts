/**
 * MCP resources for EnStorage.
 *
 * - `enstorage://vault`     → vault status + storage quota summary.
 * - `enstorage://file/{id}` → file metadata and (text) contents for a file id.
 */

import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { ResourceTemplate } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { ReadResourceResult } from '@modelcontextprotocol/sdk/types.js';
import { DEFAULT_MAX_DOWNLOAD_BYTES, EnStorageClient } from './client.js';
import { formatBytes } from './tools.js';

function textResource(uri: string, mimeType: string, text: string): ReadResourceResult {
  return { contents: [{ uri, mimeType, text }] };
}

async function vaultSummary(client: EnStorageClient): Promise<string> {
  const data = await client.getDashboard();
  const storage = data.storage;
  const summary = data.summary;
  const lines = ['EnStorage Vault', '================'];

  if (storage) {
    lines.push(
      `Storage total : ${formatBytes(storage.total)}`,
      `Terpakai      : ${formatBytes(storage.used)} (${storage.percentage ?? '-'}%)`,
      `Bebas         : ${formatBytes(storage.free)}`,
      `Akun Drive    : ${storage.accounts_count ?? '-'}`,
    );
  } else {
    lines.push('Storage: (tidak tersedia)');
  }

  if (summary) {
    lines.push(
      `Berkas        : ${summary.files_count ?? '-'} (${formatBytes(summary.files_size)})`,
      `Folder        : ${summary.folders_count ?? '-'}`,
    );
  }

  if (data.categories?.length) {
    lines.push('', 'Kategori:');
    for (const category of data.categories) {
      lines.push(`- ${category.type}: ${category.count} berkas, ${formatBytes(category.size)}`);
    }
  }

  if (data.recent_files?.length) {
    lines.push('', 'Berkas terbaru:');
    for (const file of data.recent_files.slice(0, 10)) {
      lines.push(`- ${file.name} (${file.mime_type ?? 'unknown'}, ${formatBytes(file.size ?? null)}) — ${file.id}`);
    }
  }

  return lines.join('\n');
}

async function fileResource(client: EnStorageClient, uri: string, id: string): Promise<ReadResourceResult> {
  const decodedId = decodeURIComponent(id);
  const detail = await client.getFileDetail(decodedId);
  const lines = [
    `Nama      : ${detail.name}`,
    `ID        : ${detail.id}`,
    `MIME      : ${detail.mime_type ?? '-'}`,
    `Ukuran    : ${formatBytes(detail.size ?? null)}`,
    `Folder    : ${detail.folder?.path ?? detail.folder?.name ?? '(root)'}`,
    `Status    : ${detail.upload_status ?? '-'}`,
    `Bintang   : ${detail.is_starred ? 'ya' : 'tidak'}`,
  ];
  if (detail.pages !== undefined && detail.pages !== null) lines.push(`Halaman   : ${detail.pages}`);
  if (detail.dimensions) {
    const dims =
      typeof detail.dimensions === 'string'
        ? detail.dimensions
        : `${detail.dimensions.width ?? '?'}×${detail.dimensions.height ?? '?'}`;
    lines.push(`Dimensi   : ${dims}`);
  }

  const mime = (detail.mime_type ?? '').toLowerCase();
  const readable =
    mime.startsWith('text/') ||
    /json|xml|yaml|csv|markdown|javascript|sql/.test(mime) ||
    mime === '' ||
    mime === 'application/octet-stream';

  if (readable) {
    try {
      const content = await client.downloadFile(decodedId, DEFAULT_MAX_DOWNLOAD_BYTES);
      lines.push('', '--- Isi berkas ---');
      lines.push(content.text ?? content.note);
      return textResource(uri, detail.mime_type ?? 'text/plain', lines.join('\n'));
    } catch (error) {
      const reason = error instanceof Error ? error.message : String(error);
      lines.push('', `(Isi tidak dapat dibaca: ${reason})`);
      return textResource(uri, 'text/plain', lines.join('\n'));
    }
  }

  lines.push('', `(Berkas biner ${detail.mime_type}; gunakan tool enstorage_read_file untuk membaca base64.)`);
  return textResource(uri, 'text/plain', lines.join('\n'));
}

/**
 * Register all EnStorage resources on the given MCP server.
 */
export function registerResources(server: McpServer, client: EnStorageClient): void {
  server.registerResource(
    'enstorage_vault',
    'enstorage://vault',
    {
      title: 'EnStorage Vault Summary',
      description: 'Ringkasan status vault EnStorage: kuota storage gabungan, kategori berkas, dan berkas terbaru.',
      mimeType: 'text/plain',
    },
    async (uri) => {
      const text = await vaultSummary(client);
      return textResource(uri.href, 'text/plain', text);
    },
  );

  server.registerResource(
    'enstorage_file',
    new ResourceTemplate('enstorage://file/{id}', {
      list: undefined,
      complete: {
        id: (value) => (value ? [encodeURIComponent(value)] : []),
      },
    }),
    {
      title: 'EnStorage File',
      description: 'Metadata dan konten teks sebuah berkas EnStorage berdasarkan ID.',
      mimeType: 'text/plain',
    },
    async (uri, variables) => {
      const raw = variables.id;
      const id = Array.isArray(raw) ? (raw[0] ?? '') : (raw ?? '');
      if (!id) {
        return textResource(uri.href, 'text/plain', 'ID berkas kosong. Gunakan enstorage://file/<id>.');
      }
      return fileResource(client, uri.href, id);
    },
  );
}
