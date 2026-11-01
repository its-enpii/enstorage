# @its-enpii/enstorage-mcp

Model Context Protocol (MCP) server resmi untuk **EnStorage** — menghubungkan agen AI
(Claude Desktop, Cursor, Hermes Agent, Claude Code, dan client MCP lain) ke vault
EnStorage sehingga agen dapat **mencari, membaca, mengunggah, dan mengelola berkas**
langsung dari percakapan.

Dibangun dengan TypeScript dan [`@modelcontextprotocol/sdk`](https://github.com/modelcontextprotocol/typescript-sdk),
berbicara MCP lewat **stdio**.

---

## Fitur

| Kategori | Nama | Fungsi |
|----------|------|--------|
| Tool | `enstorage_get_dashboard` | Kuota storage gabungan (multi akun Google Drive) + metrik kategori berkas |
| Tool | `enstorage_list_files` | Daftar berkas dengan filter kategori, pencarian nama, dan pagination |
| Tool | `enstorage_search_files` | Pencarian fuzzy typo-tolerant dengan highlight & `did_you_mean` |
| Tool | `enstorage_get_file_detail` | Metadata lengkap: path folder, status lock, dimensi, jumlah halaman PDF |
| Tool | `enstorage_read_file` | Baca isi teks berkas (markdown, txt, kode, csv, json) langsung ke konteks AI |
| Tool | `enstorage_upload_file` | Unggah berkas teks/dokumen baru |
| Tool | `enstorage_create_folder` | Buat folder baru |
| Tool | `enstorage_create_share_link` | Buat share link publik dengan batas waktu & jumlah tayang |
| Resource | `enstorage://vault` | Ringkasan status vault & kuota storage |
| Resource | `enstorage://file/{id}` | Metadata + isi teks berkas berdasarkan ID |
| Prompt | `summarize_document` | Template instruksi untuk merangkum dokumen (`file_id`) |

> Catatan keamanan konteks: `enstorage_read_file` membatasi pembacaan default **512 KB**
> (maksimum 8 MB) agar tidak membanjiri context window LLM. Berkas biner (gambar,
> arsip, PDF) dikembalikan sebagai indikasi `[binary content: <mime>]` + potongan base64.

---

## Persyaratan

- Node.js **≥ 18.17** (disarankan 20+; memakai `fetch`, `FormData`, `Blob` bawaan)
- API Key EnStorage berformat `en_<8-char-prefix>_<40-char-secret>` — buat di dashboard
  EnStorage → menu **API Keys**. Key hanya ditampilkan sekali saat dibuat.
  - Scope minimal `read` untuk pencarian/pembacaan, `write` untuk unggah / buat folder / share link.

---

## Environment Variables

| Variable | Wajib | Default | Keterangan |
|----------|-------|---------|------------|
| `ENSTORAGE_URL` | tidak | `https://enstorage.enpiistudio.com/api/v1` | Base URL REST API (harus berakhir di `/api/v1`) |
| `ENSTORAGE_API_KEY` | **ya** | — | API Key EnStorage (`en_...`) |

File `.env` lokal di direktori kerja otomatis dibaca (via `dotenv/config`):

```dotenv
ENSTORAGE_URL=https://enstorage.enpiistudio.com/api/v1
ENSTORAGE_API_KEY=en_a1b2c3d4_e5f6g7h8...
```

---

## Instalasi & Build

```bash
cd mcp
npm install
npm run build   # menghasilkan dist/index.js
npm test        # verifikasi end-to-end (mock API + MCP client)
```

Jalankan server secara langsung:

```bash
npm start       # node ./dist/index.js (stdio)
```

Atau instal sebagai package global agar tersedia perintah `enstorage-mcp`:

```bash
npm install -g @its-enpii/enstorage-mcp
# atau tanpa instalasi global:
npx -y @its-enpii/enstorage-mcp
```

---

## Konfigurasi Client

### 1. Claude Desktop (`claude_desktop_config.json`)

Lokasi file:

- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`
- Linux: `~/.config/Claude/claude_desktop_config.json`

```json
{
  "mcpServers": {
    "enstorage": {
      "command": "npx",
      "args": ["-y", "@its-enpii/enstorage-mcp"],
      "env": {
        "ENSTORAGE_URL": "https://enstorage.enpiistudio.com/api/v1",
        "ENSTORAGE_API_KEY": "en_a1b2c3d4_e5f6g7h8..."
      }
    }
  }
}
```

Jika memakai build lokal, ganti `command` menjadi `"node"` dan `args` menjadi
`["/absolute/path/to/enstorage/mcp/dist/index.js"]`. Restart Claude Desktop setelah menyimpan.

### 2. Cursor (`.cursor/mcp.json`)

Buat `.cursor/mcp.json` di root project (atau `~/.cursor/mcp.json` untuk global):

```json
{
  "mcpServers": {
    "enstorage": {
      "command": "node",
      "args": ["/absolute/path/to/enstorage/mcp/dist/index.js"],
      "env": {
        "ENSTORAGE_URL": "https://enstorage.enpiistudio.com/api/v1",
        "ENSTORAGE_API_KEY": "en_a1b2c3d4_e5f6g7h8..."
      }
    }
  }
}
```

Cek di **Cursor Settings → MCP**: server `enstorage` harus berstatus *enabled* dengan
10 entri (8 tools, 2 resources; prompt `summarize_document` tersedia terpisah).

### 3. Hermes Agent (`~/.hermes/config.yaml`)

Tambahkan blok `mcp_servers` berikut ke `~/.hermes/config.yaml` (buat blok baru jika belum ada):

```yaml
mcp_servers:
  enstorage:
    command: node
    args:
      - /absolute/path/to/enstorage/mcp/dist/index.js
    env:
      ENSTORAGE_URL: "https://enstorage.enpiistudio.com/api/v1"
      ENSTORAGE_API_KEY: "en_a1b2c3d4_e5f6g7h8..."
    enabled: true
```

Alternatif lewat CLI:

```bash
hermes mcp add enstorage \
  --command node \
  --args /absolute/path/to/enstorage/mcp/dist/index.js
```

lalu tambahkan `env` / `enabled: true` pada entri `mcp_servers.enstorage` hasil `hermes mcp add`.
Verifikasi dengan `hermes mcp test enstorage` dan lihat daftarnya via `hermes mcp list`.

### 4. Claude Code CLI

```bash
# Scope user (tersedia di semua project)
claude mcp add enstorage \
  --scope user \
  --env ENSTORAGE_URL=https://enstorage.enpiistudio.com/api/v1 \
  --env ENSTORAGE_API_KEY=en_a1b2c3d4_e5f6g7h8... \
  -- npx -y @its-enpii/enstorage-mcp

# Atau memakai build lokal
claude mcp add enstorage \
  --scope user \
  --env ENSTORAGE_API_KEY=en_a1b2c3d4_e5f6g7h8... \
  -- node /absolute/path/to/enstorage/mcp/dist/index.js

# Verifikasi
claude mcp list
# Di dalam sesi Claude Code:
/mcp
```

---

## Contoh Penggunaan

Setelah terhubung, cukup minta ke agen dalam bahasa natural:

- “Tampilkan kuota storage EnStorage saya dan 5 berkas terbaru.”
- “Cari dokumen lapran kuartal 1 tahun ini.” (typo-tolerant)
- “Baca isi berkas `catatan.md` dan buat ringkasannya.”
- “Buat folder `Arsip 2026` di root, lalu unggah ringkasan analisis ke folder itu.”
- “Buat share link untuk laporan.pdf yang berlaku 24 jam dengan maksimal 5 kali tayang.”

Atau secara eksplisit memakai prompt `summarize_document` dengan argumen `file_id`.

---

## Pengembangan

```bash
npm run build   # tsc → dist/
npm start       # jalankan server
npm test        # test end-to-end (mock REST API + MCP client stdio)
```

Struktur:

```
mcp/
├── package.json
├── tsconfig.json
├── README.md
├── src/
│   ├── index.ts       # entry point (#!/usr/bin/env node, StdioServerTransport)
│   ├── client.ts      # HTTP client EnStorage (envelope, download terbatas, upload multipart)
│   ├── tools.ts       # 8 MCP tools
│   ├── resources.ts   # resource enstorage://vault & enstorage://file/{id}
│   ├── prompts.ts     # prompt summarize_document
│   └── types.ts       # tipe bersama
└── test/
    └── test-server.js # mock API + skenario end-to-end
```

Penanganan error: envelope EnStorage `{ success: false }` (bahkan dengan HTTP 200)
maupun status HTTP error (401/403/404/409/422/429/5xx) dipetakan menjadi hasil tool
`isError: true` dengan pesan dari `message`/`data.errors` plus hint tindakan.

---

## Lisensi

MIT © EnPii Studio
