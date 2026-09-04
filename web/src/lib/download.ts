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
