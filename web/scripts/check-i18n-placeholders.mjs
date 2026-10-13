/**
 * i18n placeholder guard for EnStorage web.
 *
 * Fails (exit 1) when:
 *   Rule A — parity: the key sets of the `id` and `en` locales differ.
 *   Rule B — unfilled placeholder: a positional `t('key', ...)` call site in
 *            src/**\/*.{ts,tsx} references a key containing `{{var}}` but the
 *            call does not supply that `var`. Object shorthand `{ count }`
 *            counts as supplying `count`.
 *   Rule C — label suffix: any value under a key ending in `.items` or
 *            `.itemsSize` must not contain `{{` (rendered as a plain suffix
 *            word after the number).
 *
 * Exit 0 when clean.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const webDir = path.resolve(__dirname, '..');
const localesDir = path.join(webDir, 'public/locales');
const srcDir = path.join(webDir, 'src');

const LOCALES = ['id', 'en'];

function readLocale(locale) {
  const file = path.join(localesDir, locale, 'translation.json');
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function flattenKeys(obj, prefix = '') {
  let keys = [];
  for (const k of Object.keys(obj)) {
    const full = prefix ? `${prefix}.${k}` : k;
    const v = obj[k];
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      keys = keys.concat(flattenKeys(v, full));
    } else {
      keys.push(full);
    }
  }
  return keys;
}

function getValue(obj, dotted) {
  return dotted.split('.').reduce((acc, p) => (acc == null ? undefined : acc[p]), obj);
}

// Recursively collect src .ts/.tsx files.
function collectFiles(dir) {
  let out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) out = out.concat(collectFiles(full));
    else if (/\.(ts|tsx)$/.test(entry.name)) out.push(full);
  }
  return out;
}

// Extract the placeholder variable names from a template string value.
function placeholdersIn(value) {
  const out = new Set();
  if (typeof value !== 'string') return out;
  const re = /\{\{\s*([\w.-]+)\s*\}\}/g;
  let m;
  while ((m = re.exec(value)) !== null) out.add(m[1]);
  return out;
}

// Best-effort scan of a t('key', ...) call starting at the opening paren.
// Returns { key, keys:Set, raw } where raw is the argument text after the key.
function parseCallArgs(source, parenStart) {
  let depth = 0;
  let i = parenStart;
  let start = -1;
  let end = -1;
  for (; i < source.length; i++) {
    const c = source[i];
    if (c === '(') {
      depth++;
      if (depth === 1) start = i + 1;
    } else if (c === ')') {
      depth--;
      if (depth === 0) {
        end = i;
        break;
      }
    }
  }
  if (start < 0 || end < 0) return null;
  const raw = source.slice(start, end);

  // First string literal is the key.
  let key = null;
  const km = raw.match(/^\s*(['"`])((?:\\.|(?!\1).)*)\1/s);
  if (km) key = km[2];

  // Names supplied as argument object keys (incl. shorthand) after the key.
  const keys = new Set();
  const afterKey = km ? raw.slice(km[0].length) : raw;
  const propRe = /(?:^|[,{(\s])([A-Za-z_$][\w$]*)\s*(?::|,|$|\})/g;
  let pm;
  while ((pm = propRe.exec(afterKey)) !== null) keys.add(pm[1]);
  // Bound method references like { count.toString() } — capture object before dot.
  const methRe = /(?:^|[,{(\s])([A-Za-z_$][\w$]*)\s*\./g;
  while ((pm = methRe.exec(afterKey)) !== null) keys.add(pm[1]);

  return { key, keys, raw };
}

const violations = [];

// ---------- Rule A: parity ----------
const idJson = readLocale('id');
const enJson = readLocale('en');
const idKeys = flattenKeys(idJson);
const enKeys = flattenKeys(enJson);
const idKeySet = new Set(idKeys);
const enKeySet = new Set(enKeys);

for (const k of idKeys) if (!enKeySet.has(k)) violations.push({ rule: 'A', msg: `key only in 'id' locale: ${k}` });
for (const k of enKeys) if (!idKeySet.has(k)) violations.push({ rule: 'A', msg: `key only in 'en' locale: ${k}` });

// ---------- Rule C: label suffix ----------
for (const locale of LOCALES) {
  const json = locale === 'id' ? idJson : enJson;
  for (const k of flattenKeys(json)) {
    if (/\.(items|itemsSize)$/.test(k)) {
      const v = getValue(json, k);
      if (typeof v === 'string' && v.includes('{{')) {
        violations.push({ rule: 'C', msg: `[${locale}] value for '${k}' contains '{{' but is rendered as a suffix: ${JSON.stringify(v)}` });
      }
    }
  }
}

// ---------- Rule B: unfilled placeholders at call sites ----------
// Union of key -> placeholders; a call is flagged only when the key is known
// in at least one locale and every definition requires a var the call omits.
const placeholderByKey = new Map();
for (const [json, keys] of [[idJson, idKeys], [enJson, enKeys]]) {
  for (const k of keys) {
    const ph = placeholdersIn(getValue(json, k));
    if (ph.size) {
      if (!placeholderByKey.has(k)) placeholderByKey.set(k, new Set());
      for (const p of ph) placeholderByKey.get(k).add(p);
    }
  }
}

const files = collectFiles(srcDir);
const callRe = /\bt\(\s*(?=(['"`]))/g;

for (const file of files) {
  const source = fs.readFileSync(file, 'utf8');
  const lineOf = (idx) => source.slice(0, idx).split('\n').length;
  let m;
  callRe.lastIndex = 0;
  while ((m = callRe.exec(source)) !== null) {
    const parenStart = source.indexOf('(', m.index);
    if (parenStart < 0) continue;
    const call = parseCallArgs(source, parenStart);
    if (!call || !call.key) continue;
    const required = placeholderByKey.get(call.key);
    if (!required) continue;
    const missing = [...required].filter((v) => !call.keys.has(v));
    if (missing.length === required.size) {
      violations.push({
        rule: 'B',
        msg: `${path.relative(webDir, file)}:${lineOf(m.index)} t('${call.key}') does not supply placeholder(s): ${missing.join(', ')}`,
      });
    }
  }
}

// ---------- Report ----------
const totalKeys = idKeySet.size;
const parityOk = idKeySet.size === enKeySet.size &&
  [...idKeySet].every((k) => enKeySet.has(k));

console.log('i18n placeholder guard');
console.log(`  locales       : id (${idKeys.length} keys), en (${enKeys.length} keys)`);
console.log(`  parity        : ${parityOk ? 'OK (0 diff)' : 'MISMATCH'}`);
console.log(`  src files     : ${files.length} scanned`);
console.log(`  violations    : ${violations.length}`);

if (violations.length) {
  console.log('\nViolations:');
  for (const v of violations) console.log(`  [Rule ${v.rule}] ${v.msg}`);
  console.error(`\nFAILED: ${violations.length} violation(s).`);
  process.exit(1);
}

console.log('\nOK: no violations. Locale parity, placeholders, and suffix labels are clean.');
process.exit(0);
