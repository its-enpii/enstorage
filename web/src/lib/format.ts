export function bytes(n: number | null | undefined): string {
  if (n === null || n === undefined || n === 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let v = n;
  let i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  const fixed = v < 10 && i > 0 ? 1 : 0;
  return `${v.toFixed(fixed)} ${units[i]}`;
}

/** Uppercase file extension extracted from a name (without the dot). */
export function fileExtension(name: string | null | undefined): string {
  if (!name) return '';
  const base = name.split(/[?#]/)[0];
  const dot = base.lastIndexOf('.');
  if (dot <= 0 || dot === base.length - 1) return '';
  const ext = base.slice(dot + 1);
  return ext.length > 6 ? ext.slice(0, 6) : ext.toUpperCase();
}
