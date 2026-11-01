/**
 * MCP prompts for EnStorage.
 */

import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { GetPromptResult } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';

/**
 * Register all EnStorage prompts on the given MCP server.
 */
export function registerPrompts(server: McpServer): void {
  server.registerPrompt(
    'summarize_document',
    {
      title: 'Summarize EnStorage Document',
      description:
        'Menganalisis dan merangkum sebuah berkas di EnStorage berdasarkan ID-nya.',
      argsSchema: {
        file_id: z.string().min(1).describe('UUID berkas yang akan diringkas.'),
      },
    },
    ({ file_id }): GetPromptResult => {
      const id = file_id.trim();
      const prompt = [
        `Anda diminta menganalisis dan merangkum dokumen berikut yang tersimpan di EnStorage.`,
        ``,
        `Langkah-langkah:`,
        `1. Panggil tool \`enstorage_get_file_detail\` dengan file_id: ${id} untuk mengetahui nama, tipe, ukuran, dan metadata lain.`,
        `2. Baca isi berkas dengan tool \`enstorage_read_file\` (file_id: ${id}, max_bytes sesuai kebutuhan). Jika berkas berupa PDF/gambar biner, jelaskan bahwa isinya biner dan rangkum berdasarkan metadata yang tersedia.`,
        `3. Jika konten terpotong (truncated), sebutkan hal tersebut dalam ringkasan.`,
        ``,
        `Format jawaban (Bahasa Indonesia):`,
        `- **Ringkasan Eksekutif** (3–5 kalimat)`,
        `- **Poin-Poin Utama** (bullet list)`,
        `- **Struktur / Bagian Dokumen** (jika relevan)`,
        `- **Kesimpulan & Rekomendasi**`,
        ``,
        `Selalu sertakan nama berkas dan ID tersebut di awal jawaban Anda.`,
      ].join('\n');

      return {
        description: `Ringkas dokumen EnStorage ${id}`,
        messages: [
          {
            role: 'user',
            content: { type: 'text', text: prompt },
          },
        ],
      };
    },
  );
}
