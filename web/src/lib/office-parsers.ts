/**
 * Client-side parsers for Office Open XML previews (DOCX / XLSX) and ZIP
 * archive listings. Everything runs on the already-installed `jszip` — no
 * extra dependency. Parsers are pure (input: ArrayBuffer / JSZip) so the
 * viewer components only own fetching, loading and error state.
 */
import JSZip from 'jszip';

/* -------------------------------------------------------------------------- */
/* XML helpers                                                                */
/* -------------------------------------------------------------------------- */

/** Namespace-aware element children lookup. */
export function elements(node: Element | Document, ns: string | null, local: string): Element[] {
  return Array.from(node.getElementsByTagNameNS(ns, local));
}

/** All `<w:t>` text descendant of `node`, concatenated. */
function textOf(node: Element, ns: string, local: string): string {
  const parts = node.getElementsByTagNameNS(ns, local);
  let out = '';
  for (let i = 0; i < parts.length; i++) out += parts[i]?.textContent ?? '';
  return out;
}

function parseXml(xml: string): Document {
  const doc = new DOMParser().parseFromString(xml, 'application/xml');
  if (doc.getElementsByTagName('parsererror').length > 0) {
    throw new Error('XML parse error');
  }
  return doc;
}

async function readXml(zip: JSZip, path: string): Promise<Document | null> {
  const entry = zip.file(path);
  if (!entry) return null;
  return parseXml(await entry.async('text'));
}

export class OfficeParseError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'OfficeParseError';
  }
}

/* -------------------------------------------------------------------------- */
/* DOCX                                                                       */
/* -------------------------------------------------------------------------- */

export type DocxRun = { text: string; bold: boolean; italic: boolean };

export type DocxBlock =
  | { kind: 'heading'; level: 1 | 2 | 3; runs: DocxRun[] }
  | { kind: 'paragraph'; runs: DocxRun[] }
  /** `ordered` is 1-based; unnumbered list items keep a bullet. */
  | { kind: 'listItem'; ordered: boolean; level: number; runs: DocxRun[] }
  | { kind: 'table'; rows: DocxRun[][][] };

const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

function headingLevel(styleId: string | null): 1 | 2 | 3 | null {
  if (!styleId) return null;
  const m = /^heading\s*(\d)$/i.exec(styleId.trim());
  if (!m) return null;
  const n = Number(m[1]);
  if (n === 1 || n === 2 || n === 3) return n;
  return 3;
}

function runsOfParagraph(p: Element): DocxRun[] {
  const runs: DocxRun[] = [];
  const nodes = elements(p, W_NS, 'r');
  for (const r of nodes) {
    const text = textOf(r, W_NS, 't');
    if (!text) continue;
    const rPr = r.getElementsByTagNameNS(W_NS, 'rPr')[0] ?? null;
    const bold = !!rPr?.getElementsByTagNameNS(W_NS, 'b').length;
    const italic = !!rPr?.getElementsByTagNameNS(W_NS, 'i').length;
    runs.push({ text, bold, italic });
  }
  return runs;
}

function paragraphBlock(p: Element): DocxBlock {
  const runs = runsOfParagraph(p);
  const pPr = p.getElementsByTagNameNS(W_NS, 'pPr')[0] ?? null;

  const numPr = pPr?.getElementsByTagNameNS(W_NS, 'numPr')[0] ?? null;
  if (numPr) {
    const numId = numPr.getElementsByTagNameNS(W_NS, 'numId')[0]?.getAttributeNS(W_NS, 'val') ?? '';
    const ilvlRaw = Number(numPr.getElementsByTagNameNS(W_NS, 'ilvl')[0]?.getAttributeNS(W_NS, 'val') ?? '0');
    const level = Number.isFinite(ilvlRaw) ? Math.min(3, Math.max(0, ilvlRaw)) : 0;
    // `numId=0` means "numbering removed" in OOXML — render as a plain paragraph.
    if (numId !== '0') {
      // Word uses numId 1 for the default ordered list in most templates; an
      // unordered list is the safer default for other numbering definitions.
      const ordered = numId === '1';
      return { kind: 'listItem', ordered, level, runs };
    }
  }

  const pStyle = pPr?.getElementsByTagNameNS(W_NS, 'pStyle')[0]?.getAttributeNS(W_NS, 'val') ?? null;
  const level = headingLevel(pStyle);
  if (level) return { kind: 'heading', level, runs };
  return { kind: 'paragraph', runs };
}

/** Parse `word/document.xml` into a flat list of renderable blocks. */
export function parseDocxXml(xml: string): DocxBlock[] {
  const doc = parseXml(xml);
  const body = doc.getElementsByTagNameNS(W_NS, 'body')[0];
  if (!body) throw new OfficeParseError('missing body');

  const blocks: DocxBlock[] = [];
  for (const child of Array.from(body.children)) {
    if (child.namespaceURI !== W_NS) continue;
    if (child.localName === 'p') {
      const block = paragraphBlock(child);
      const hasText = block.kind === 'table' || block.runs.some((r) => r.text.trim() !== '');
      if (hasText) blocks.push(block);
    } else if (child.localName === 'tbl') {
      const rows: DocxRun[][][] = [];
      for (const tr of Array.from(child.children)) {
        if (tr.namespaceURI !== W_NS || tr.localName !== 'tr') continue;
        const cells: DocxRun[][] = [];
        for (const tc of Array.from(tr.children)) {
          if (tc.namespaceURI !== W_NS || tc.localName !== 'tc') continue;
          const paragraphs = Array.from(tc.children).filter(
            (c) => c.namespaceURI === W_NS && c.localName === 'p',
          );
          const runs: DocxRun[] = [];
          paragraphs.forEach((p, i) => {
            if (i > 0) runs.push({ text: ' ', bold: false, italic: false });
            runs.push(...runsOfParagraph(p));
          });
          cells.push(runs);
        }
        if (cells.length > 0) rows.push(cells);
      }
      if (rows.length > 0) blocks.push({ kind: 'table', rows });
    }
  }
  return blocks;
}

export async function parseDocx(buffer: ArrayBuffer): Promise<DocxBlock[]> {
  let zip: JSZip;
  try {
    zip = await JSZip.loadAsync(buffer);
  } catch {
    throw new OfficeParseError('not a zip container');
  }
  const xml = await zip.file('word/document.xml')?.async('text');
  if (!xml) throw new OfficeParseError('missing word/document.xml');
  return parseDocxXml(xml);
}

/* -------------------------------------------------------------------------- */
/* XLSX                                                                       */
/* -------------------------------------------------------------------------- */

export const XLSX_MAX_ROWS = 200;
export const XLSX_MAX_COLS = 50;

export type SheetCell = { text: string; numeric: boolean };

export type ParsedSheet = {
  name: string;
  /** Rendered rows (≤ XLSX_MAX_ROWS), each padded to `columns`. */
  rows: (SheetCell | null)[][];
  /** Number of the spreadsheet row shown at index 0 (1-based). */
  firstRowNumber: number;
  columns: number;
  /** Spreadsheet rows present in the sheet but not rendered. */
  hiddenRows: number;
  /** Spreadsheet columns present in the sheet but not rendered. */
  hiddenColumns: number;
};

export type ParsedWorkbook = { sheets: ParsedSheet[] };

function colIndex(ref: string): number {
  const letters = /^[A-Z]+/i.exec(ref)?.[0]?.toUpperCase() ?? '';
  let n = 0;
  for (const ch of letters) n = n * 26 + (ch.charCodeAt(0) - 64);
  return n - 1;
}

function rowIndex(ref: string | null): number | null {
  if (!ref) return null;
  const digits = /(\d+)$/.exec(ref)?.[1];
  if (!digits) return null;
  const n = Number(digits);
  return Number.isFinite(n) && n > 0 ? n - 1 : null;
}

function colName(index: number): string {
  let n = index + 1;
  let out = '';
  while (n > 0) {
    const rem = (n - 1) % 26;
    out = String.fromCharCode(65 + rem) + out;
    n = Math.floor((n - 1) / 26);
  }
  return out;
}

function readSharedStrings(doc: Document | null): string[] {
  if (!doc) return [];
  const ns = doc.documentElement.namespaceURI;
  const items = elements(doc, ns, 'si');
  return items.map((si) => textOf(si, ns ?? '', 't'));
}

/** Resolve a workbook relationship id to a worksheet path inside the zip. */
function resolveTarget(target: string | null): string | null {
  if (!target) return null;
  const clean = target.replace(/^\//, '').replace(/^\.\//, '');
  if (clean.startsWith('xl/')) return clean;
  return `xl/${clean}`;
}

type SheetRef = { name: string; path: string };

function readSheetRefs(workbook: Document, rels: Document | null): SheetRef[] {
  const ns = workbook.documentElement.namespaceURI;
  const relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  const rIdAttr = `${relNs}id`;
  const targets = new Map<string, string>();
  if (rels) {
    for (const rel of elements(rels, rels.documentElement.namespaceURI, 'Relationship')) {
      const id = rel.getAttribute('Id');
      const target = rel.getAttribute('Target');
      const type = rel.getAttribute('Type') ?? '';
      const path = resolveTarget(target);
      if (id && path && type.endsWith('/worksheet')) targets.set(id, path);
    }
  }
  const sheets: SheetRef[] = [];
  const sheetEls = elements(workbook, ns, 'sheet');
  sheetEls.forEach((sheet, i) => {
    const name = sheet.getAttribute('name') ?? '';
    const rid = sheet.getAttribute(rIdAttr) ?? sheet.getAttribute('r:id');
    const path = (rid ? targets.get(rid) : undefined) ?? `xl/worksheets/sheet${i + 1}.xml`;
    sheets.push({ name, path });
  });
  return sheets;
}

function parseSheet(doc: Document, name: string, shared: string[]): ParsedSheet {
  const ns = doc.documentElement.namespaceURI ?? '';
  const rowEls = elements(doc, ns, 'row');
  const cells = new Map<number, Map<number, SheetCell>>();
  let maxRow = -1;
  let maxCol = -1;
  let firstRowNumber = 1;

  rowEls.forEach((row, order) => {
    const explicit = rowIndex(row.getAttribute('r'));
    const r = explicit ?? order;
    if (order === 0) firstRowNumber = r + 1;
    maxRow = Math.max(maxRow, r);
    const rowMap = new Map<number, SheetCell>();
    let nextCol = 0;
    const cellEls = Array.from(row.children).filter((c) => c.namespaceURI === ns && c.localName === 'c');
    for (const c of cellEls) {
      const ref = c.getAttribute('r');
      const col = ref ? colIndex(ref) : nextCol;
      nextCol = col + 1;
      maxCol = Math.max(maxCol, col);

      const type = c.getAttribute('t') ?? 'n';
      let text = '';
      let numeric = false;
      if (type === 'inlineStr') {
        text = textOf(c, ns, 't');
      } else {
        const v = c.getElementsByTagNameNS(ns, 'v')[0]?.textContent ?? '';
        if (type === 's') {
          const idx = Number(v);
          text = Number.isInteger(idx) ? (shared[idx] ?? '') : '';
        } else if (type === 'b') {
          text = v === '1' ? 'TRUE' : 'FALSE';
        } else {
          text = v;
          numeric = type === 'n' && v !== '' && Number.isFinite(Number(v));
        }
      }
      if (text !== '') rowMap.set(col, { text, numeric });
    }
    if (rowMap.size > 0) cells.set(r, rowMap);
  });

  const totalRows = Math.max(0, maxRow + 1 - (firstRowNumber - 1));
  const visibleRows = Math.min(XLSX_MAX_ROWS, totalRows);
  const columns = Math.max(0, Math.min(XLSX_MAX_COLS, maxCol + 1));
  const rows: (SheetCell | null)[][] = [];
  for (let i = 0; i < visibleRows; i++) {
    const rowMap = cells.get(firstRowNumber - 1 + i);
    const row: (SheetCell | null)[] = [];
    for (let j = 0; j < columns; j++) row.push(rowMap?.get(j) ?? null);
    rows.push(row);
  }

  return {
    name,
    rows,
    firstRowNumber,
    columns,
    hiddenRows: Math.max(0, totalRows - visibleRows),
    hiddenColumns: Math.max(0, maxCol + 1 - columns),
  };
}

export async function parseXlsx(buffer: ArrayBuffer): Promise<ParsedWorkbook> {
  let zip: JSZip;
  try {
    zip = await JSZip.loadAsync(buffer);
  } catch {
    throw new OfficeParseError('not a zip container');
  }
  const workbook = await readXml(zip, 'xl/workbook.xml');
  if (!workbook) throw new OfficeParseError('missing xl/workbook.xml');

  const [rels, sharedDoc] = await Promise.all([
    readXml(zip, 'xl/_rels/workbook.xml.rels'),
    readXml(zip, 'xl/sharedStrings.xml'),
  ]);
  const shared = readSharedStrings(sharedDoc);

  const sheets: ParsedSheet[] = [];
  for (const ref of readSheetRefs(workbook, rels)) {
    const doc = await readXml(zip, ref.path);
    if (doc) sheets.push(parseSheet(doc, ref.name, shared));
  }
  return { sheets };
}

/** Column letters for the header row of a sheet (`A`, `B`, …). */
export function xlsxColumnLabel(index: number): string {
  return colName(index);
}

/* -------------------------------------------------------------------------- */
/* ZIP archive listing                                                        */
/* -------------------------------------------------------------------------- */

export type ArchiveEntry = {
  path: string;
  name: string;
  depth: number;
  isDir: boolean;
  /** Uncompressed size when JSZip exposes it; `null` otherwise. */
  size: number | null;
};

export type ArchiveListing = {
  entries: ArchiveEntry[];
  fileCount: number;
  folderCount: number;
  totalSize: number | null;
};

type ZipEntrySize = { _data?: { uncompressedSize?: number } };

function entrySize(entry: JSZip.JSZipObject): number | null {
  const size = (entry as ZipEntrySize)._data?.uncompressedSize;
  return typeof size === 'number' && Number.isFinite(size) && size >= 0 ? size : null;
}

/** Sort: folders before files, alphabetical by name (locale-independent). */
export function compareEntries(a: ArchiveEntry, b: ArchiveEntry): number {
  if (a.isDir !== b.isDir) return a.isDir ? -1 : 1;
  return a.path.localeCompare(b.path, 'en', { numeric: true, sensitivity: 'base' });
}

export async function parseArchive(buffer: ArrayBuffer): Promise<ArchiveListing> {
  let zip: JSZip;
  try {
    zip = await JSZip.loadAsync(buffer);
  } catch {
    throw new OfficeParseError('not a zip container');
  }
  const entries: ArchiveEntry[] = [];
  let fileCount = 0;
  let folderCount = 0;
  let totalSize: number | null = 0;

  zip.forEach((path, entry) => {
    const dirty = path.startsWith('__MACOSX/') || path.split('/').some((s) => s === '.DS_Store');
    if (dirty) return;
    const segments = path.split('/').filter(Boolean);
    if (segments.length === 0) return;
    const size = entry.dir ? null : entrySize(entry);
    entries.push({
      path,
      name: segments[segments.length - 1] ?? path,
      depth: segments.length - 1,
      isDir: entry.dir,
      size,
    });
    if (entry.dir) folderCount += 1;
    else fileCount += 1;
    if (!entry.dir) totalSize = totalSize === null || size === null ? null : totalSize + size;
  });

  entries.sort(compareEntries);
  return { entries, fileCount, folderCount, totalSize };
}
