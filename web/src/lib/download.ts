import { getToken } from '@/lib/api';
import type { FileItem } from '@/lib/api';

/**
 * Bangun URL download langsung (native stream) untuk sebuah file.
 *
 * Memakai `download_url` bila backend sudah menyediakannya (mis. share link),
 * jika tidak fallback ke endpoint proxy `/files/{id}/download` dengan
 * `?token=` query param — lihat `AuthApiKey::extractBearer` yang mendukung
 * fallback token via query agar browser bisa men-stream file secara native
 * sebagai attachment tanpa harus memuat seluruh file ke RAM via `fetch()`.
 */
export function getFileDownloadUrl(file: Pick<FileItem, 'id'> & { download_url?: string }): string {
  if (file.download_url) return file.download_url;
  const token = getToken();
  const base = `${process.env.NEXT_PUBLIC_API_BASE}/files/${file.id}/download`;
  return token ? `${base}?token=${encodeURIComponent(token)}` : base;
}

/**
 * Bangun URL download langsung untuk sebuah folder (arsip ZIP).
 *
 * Sama seperti {@link getFileDownloadUrl}, memakai `?token=` query param agar
 * dapat dipicu native oleh browser.
 */
export function getFolderDownloadUrl(folderId: string): string {
  const token = getToken();
  const base = `${process.env.NEXT_PUBLIC_API_BASE}/folders/${folderId}/download`;
  return token ? `${base}?token=${encodeURIComponent(token)}` : base;
}

/**
 * Picu download native dengan membuat anchor sementara ber-attribut
 * `download`. Browser akan membuka stream langsung, menampilkan download bar
 * native, dan menulis ke disk tanpa membebani JS heap.
 */
export function triggerDirectDownload(url: string, filename?: string) {
  const a = document.createElement('a');
  a.href = url;
  if (filename) a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

export function parseContentDispositionFilename(header: string | null | undefined): string | null {
  if (!header) return null;

  // Coba parse format RFC 5987 / UTF-8: filename*=UTF-8''filename.ext
  const utf8Match = /filename\*=UTF-8''([^;]+)/i.exec(header);
  if (utf8Match && utf8Match[1]) {
    try {
      return decodeURIComponent(utf8Match[1]);
    } catch {
      return utf8Match[1];
    }
  }

  // Coba parse format standar: filename="filename.ext" atau filename=filename.ext
  const stdMatch = /filename="?([^";]+)"?/i.exec(header);
  if (stdMatch && stdMatch[1]) {
    return stdMatch[1].trim();
  }

  return null;
}

export function triggerBlobDownload(blob: Blob, fallbackName: string, contentDisposition?: string | null) {
  const filename = parseContentDispositionFilename(contentDisposition) || fallbackName || 'download';
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
