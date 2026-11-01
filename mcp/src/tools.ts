/**
 * MCP tool registrations for EnStorage.
 *
 * Every tool is a thin wrapper around `EnStorageClient` that returns both a
 * human/LLM readable text block and a structured JSON payload.
 */

import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import { DEFAULT_MAX_DOWNLOAD_BYTES, MAX_DOWNLOAD_BYTES as ENSTORAGE_BINARY_CONTENT_LIMIT, EnStorageClient } from './client.js';
import type { FileResource } from './types.js';

/** Quote filenames safely for URL templates (spaces → %20, keep slashes). */
export function encodeFileUri(id: string): string {
  return encodeURIComponent(id);
}

/** Format bytes into a short human string. */
export function formatBytes(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined || !Number.isFinite(bytes)) return '-';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let value = Math.max(0, bytes);
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function ok(text: string, structured: Record<string, unknown>): CallToolResult {
  return {
    content: [{ type: 'text', text }],
    structuredContent: structured,
  };
}

function fail(error: unknown): CallToolResult {
  const message = error instanceof Error ? error.message : String(error);
  return {
    isError: true,
    content: [{ type: 'text', text: `❌ ${message}` }],
  };
}

function summarizeFiles(items: FileResource[], totalLabel: string): string {
  if (items.length === 0) return 'Tidak ada berkas yang cocok.';
  const lines = items.map((file, index) => {
    const label = file.highlight ?? file.name ?? '(tanpa nama)';
    const size = formatBytes(typeof file.size === 'number' ? file.size : null);
    const status = file.upload_status ? ` · ${file.upload_status}` : '';
    return `${index + 1}. ${label} — ${file.mime_type ?? 'unknown'} · ${size}${status}\n   id: ${file.id}\n   enstorage://file/${encodeFileUri(file.id)}`;
  });
  return `${totalLabel}\n\n${lines.join('\n')}`;
}

function paginationLine(meta: unknown): string {
  if (!meta || typeof meta !== 'object' || Array.isArray(meta)) return '';
  const pagination = (meta as Record<string, unknown>).pagination;
  if (!pagination || typeof pagination !== 'object') return '';
  const p = pagination as Record<string, unknown>;
  const page = p.current_page ?? p.page ?? '?';
  const last = p.last_page ?? '?';
  const total = p.total ?? '?';
  return ` (halaman ${page}/${last}, total ${total})`;
}

const fileTypeEnum = z
  .enum(['image', 'pdf', 'doc', 'video', 'audio', 'app', 'archive'])
  .describe('Kategori berkas: image, pdf, doc, video, audio, app, archive.');

/**
 * Register all EnStorage tools on the given MCP server.
 */
export function registerTools(server: McpServer, client: EnStorageClient): void {
  server.registerTool(
    'enstorage_get_dashboard',
    {
      title: 'EnStorage Dashboard',
      description:
        'Mendapatkan ringkasan kuota penyimpanan Google Drive gabungan dan metrik kategori berkas di EnStorage.',
      inputSchema: {},
      annotations: { readOnlyHint: true, openWorldHint: true },
    },
    async () => {
      try {
        const data = await client.getDashboard();
        const storage = data.storage;
        const lines: string[] = ['📊 Ringkasan EnStorage'];
        if (storage) {
          lines.push(
            `Storage: ${formatBytes(storage.used)} terpakai dari ${formatBytes(storage.total)} (${storage.percentage ?? '-'}% · ${formatBytes(storage.free)} bebas · ${storage.accounts_count ?? '?'} akun Drive)`,
          );
        }
        if (data.summary) {
          lines.push(
            `Vault: ${data.summary.files_count ?? '?'} berkas (${formatBytes(data.summary.files_size)}), ${data.summary.folders_count ?? '?'} folder.`,
          );
        }
        if (data.categories?.length) {
          lines.push(
            'Kategori: ' +
              data.categories.map((c) => `${c.type}=${c.count} (${formatBytes(c.size)})`).join(', '),
          );
        }
        if (data.recent_files?.length) {
          lines.push(`\nBerkas terbaru:\n${summarizeFiles(data.recent_files, '')}`.trim());
        }
        return ok(lines.join('\n'), { dashboard: data });
      } catch (error) {
        return fail(error);
      }
    },
  );

  server.registerTool(
    'enstorage_list_files',
    {
      title: 'EnStorage List Files',
      description:
        'Menampilkan daftar berkas dan folder di EnStorage dengan opsi filter kategori dan pagination.',
      inputSchema: {
        folder_id: z
          .string()
          .optional()
          .describe('UUID folder. Kosongkan untuk semua berkas (lintas folder) atau isi "root" untuk root.'),
        type: fileTypeEnum.optional(),
        search: z.string().optional().describe('Filter nama berkas (case-insensitive).'),
        page: z.number().int().min(1).optional().describe('Halaman (default 1).'),
        per_page: z.number().int().min(1).max(100).optional().describe('Item per halaman (default 25, maks 100).'),
      },
      annotations: { readOnlyHint: true, openWorldHint: true },
    },
    async ({ folder_id, type, search, page, per_page }) => {
      try {
        const result = await client.listFiles({
          folder_id,
          type,
          search,
          page,
          per_page,
        });
        return ok(
          summarizeFiles(result.items, `${result.items.length} berkas ditemukan${paginationLine(result.meta)}.`),
          { files: result.items, meta: result.meta ?? null, message: result.message },
        );
      } catch (error) {
        return fail(error);
      }
    },
  );

  server.registerTool(
    'enstorage_search_files',
    {
      title: 'EnStorage Search Files',
      description:
        'Pencarian cerdas (fuzzy typo-tolerant) berkas di seluruh vault EnStorage berdasarkan nama atau isi.',
      inputSchema: {
        query: z.string().min(1).max(100).describe('Kata kunci pencarian.'),
        folder_id: z.string().optional().describe('Batasi pencarian ke folder tertentu (UUID).'),
        recursive: z.boolean().optional().describe('Telusuri seluruh subtree folder jika true.'),
        type: fileTypeEnum.optional(),
      },
      annotations: { readOnlyHint: true, openWorldHint: true },
    },
    async ({ query, folder_id, recursive, type }) => {
      try {
        const result = await client.searchFiles({ q: query, folder_id, recursive, type });
        const meta = result.meta as Record<string, unknown> | null;
        const suggestions = Array.isArray(meta?.did_you_mean)
          ? (meta!.did_you_mean as Array<{ name: string; score: number }>)
          : [];
        let text = summarizeFiles(
          result.items,
          `🔍 Hasil pencarian "${query}": ${result.items.length} berkas${paginationLine(result.meta)}.`,
        );
        if (result.items.length === 0 && suggestions.length > 0) {
          text += `\n\nMungkin maksud Anda: ${suggestions.map((s) => `${s.name} (${Math.round(s.score * 100)}%)`).join(', ')}`;
        }
        return ok(text, { files: result.items, meta: result.meta ?? null, message: result.message });
      } catch (error) {
        return fail(error);
      }
    },
  );

  server.registerTool(
    'enstorage_get_file_detail',
    {
      title: 'EnStorage File Detail',
      description:
        'Mendapatkan metadata lengkap sebuah berkas (path folder hierarki, status lock, dimensi, jumlah halaman PDF).',
      inputSchema: {
        file_id: z.string().min(1).describe('UUID berkas.'),
      },
      annotations: { readOnlyHint: true, openWorldHint: true },
    },
    async ({ file_id }) => {
      try {
        const detail = await client.getFileDetail(file_id);
        const lines = [
          `📄 ${detail.name}`,
          `id: ${detail.id}`,
          `mime: ${detail.mime_type ?? '-'}`,
          `ukuran: ${formatBytes(typeof detail.size === 'number' ? detail.size : null)}`,
          `folder: ${detail.folder?.path ?? detail.folder?.name ?? '(root)'}`,
          `status upload: ${detail.upload_status ?? '-'}`,
          `bintang: ${detail.is_starred ? 'ya' : 'tidak'}`,
        ];
        if (detail.pages !== undefined && detail.pages !== null) lines.push(`halaman PDF: ${detail.pages}`);
        if (detail.dimensions) {
          const dims =
            typeof detail.dimensions === 'string'
              ? detail.dimensions
              : `${detail.dimensions.width ?? '?'}×${detail.dimensions.height ?? '?'}`;
          lines.push(`dimensi: ${dims}`);
        }
        lines.push(`resource: enstorage://file/${encodeFileUri(detail.id)}`);
        return ok(lines.join('\n'), { file: detail });
      } catch (error) {
        return fail(error);
      }
    },
  );

  server.registerTool(
    'enstorage_read_file',
    {
      title: 'EnStorage Read File',
      description:
        'Mengunduh dan membaca isi teks berkas (markdown, text, kode, csv, json) dari EnStorage langsung ke konteks AI.',
      inputSchema: {
        file_id: z.string().min(1).describe('UUID berkas.'),
        max_bytes: z
          .number()
          .int()
          .min(1)
          .max(ENSTORAGE_BINARY_CONTENT_LIMIT)
          .optional()
          .describe(
            `Batas maksimum byte yang dibaca (default ${DEFAULT_MAX_DOWNLOAD_BYTES}, maks ${ENSTORAGE_BINARY_CONTENT_LIMIT}).`,
          ),
      },
      annotations: { readOnlyHint: true, openWorldHint: true },
    },
    async ({ file_id, max_bytes }) => {
      try {
        const content = await client.downloadFile(file_id, max_bytes ?? DEFAULT_MAX_DOWNLOAD_BYTES);
        const header = `📄 ${file_id} · ${content.mimeType} · ${formatBytes(content.bytesRead)}${
          content.totalBytes !== null ? ` dari ${formatBytes(content.totalBytes)}` : ''
        }${content.truncated ? ' (dipotong)' : ''}`;

        if (content.kind === 'binary') {
          return ok(`${header}\n${content.note}\n\n${content.base64 ?? ''}`.trim(), {
            file_id,
            mime_type: content.mimeType,
            kind: content.kind,
            truncated: content.truncated,
            bytes_read: content.bytesRead,
            total_bytes: content.totalBytes,
            base64: content.base64 ?? '',
          });
        }

        return ok(`${header}\n\n${content.text ?? ''}`, {
          file_id,
          mime_type: content.mimeType,
          kind: content.kind,
          truncated: content.truncated,
          bytes_read: content.bytesRead,
          total_bytes: content.totalBytes,
          text: content.text ?? '',
        });
      } catch (error) {
        return fail(error);
      }
    },
  );

  server.registerTool(
    'enstorage_upload_file',
    {
      title: 'EnStorage Upload File',
      description: 'Mengunggah berkas teks/dokumen baru ke EnStorage.',
      inputSchema: {
        name: z.string().min(1).max(255).describe('Nama berkas lengkap dengan ekstensi, mis. "catatan.md".'),
        content: z.string().describe('Isi berkas (UTF-8).'),
        mime_type: z.string().optional().describe('MIME type opsional, mis. "text/markdown".'),
        folder_id: z.string().optional().describe('UUID folder tujuan (opsional, default root).'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
    },
    async ({ name, content, mime_type, folder_id }) => {
      try {
        const result = await client.uploadFile(name, content, mime_type, folder_id);
        const accepted = result.accepted ?? [];
        const rejected = result.rejected ?? [];
        const lines = [`⬆️ Upload "${name}" diterima (${accepted.length} berkas).`];
        for (const item of accepted) {
          lines.push(
            `- ${item.name} → id: ${item.file_id} (status: ${item.status ?? 'pending'})${item.share_url ? `\n  share: ${item.share_url}` : ''}`,
          );
        }
        for (const item of rejected) lines.push(`- ❌ ${item.name}: ${item.reason}`);
        lines.push(
          'Catatan: upload diproses asinkron; gunakan enstorage_get_file_detail setelah beberapa saat untuk memeriksa status.',
        );
        return ok(lines.join('\n'), { upload: result });
      } catch (error) {
        return fail(error);
      }
    },
  );

  server.registerTool(
    'enstorage_create_folder',
    {
      title: 'EnStorage Create Folder',
      description: 'Membuat folder baru di EnStorage.',
      inputSchema: {
        name: z.string().min(1).max(255).describe('Nama folder baru.'),
        parent_id: z.string().optional().describe('UUID folder induk (opsional, default root).'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
    },
    async ({ name, parent_id }) => {
      try {
        const folder = await client.createFolder(name, parent_id);
        return ok(
          `📁 Folder "${folder.name}" dibuat.\nid: ${folder.id}\npath: ${folder.path ?? '-'}\nparent: ${folder.parent_id ?? '(root)'}`,
          { folder },
        );
      } catch (error) {
        return fail(error);
      }
    },
  );

  server.registerTool(
    'enstorage_create_share_link',
    {
      title: 'EnStorage Create Share Link',
      description:
        'Membuat tautan publik sementara (share link) untuk berkas di EnStorage dengan batas waktu dan jumlah tayang.',
      inputSchema: {
        file_id: z.string().min(1).describe('UUID berkas.'),
        expires_in_hours: z
          .number()
          .positive()
          .max(24 * 365)
          .optional()
          .describe('Masa berlaku dalam jam sejak sekarang (opsional; kosong = tanpa kedaluwarsa).'),
        max_views: z.number().int().min(1).max(10000).optional().describe('Batas jumlah tayang (1–10000).'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
    },
    async ({ file_id, expires_in_hours, max_views }) => {
      try {
        const expiresAt =
          expires_in_hours !== undefined
            ? new Date(Date.now() + expires_in_hours * 60 * 60 * 1000).toISOString()
            : undefined;
        const link = await client.createShareLink(file_id, expiresAt, max_views);
        const lines = [
          '🔗 Share link dibuat.',
          `url: ${link.url}`,
          ...(link.preview_url ? [`preview: ${link.preview_url}`] : []),
          `kedaluwarsa: ${link.expires_at ?? 'tidak ada'}`,
          `maks tayang: ${link.max_views ?? 'tidak terbatas'}`,
        ];
        return ok(lines.join('\n'), { share_link: link });
      } catch (error) {
        return fail(error);
      }
    },
  );
}
