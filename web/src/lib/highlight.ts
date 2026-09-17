/**
 * Dependency-free tokenizer for the code samples on the landing page and in the
 * `/docs` portal. Rules are plain regex alternatives compiled once per language,
 * so a single pass yields the flat token stream the renderer paints with
 * Tailwind color classes (never an inline hex value).
 */

export type CodeLang = 'bash' | 'javascript' | 'php' | 'json' | 'http';

export type TokenKind =
  | 'comment'
  | 'status'
  | 'string'
  | 'property'
  | 'header'
  | 'url'
  | 'secret'
  | 'keyword'
  | 'method'
  | 'variable'
  | 'flag'
  | 'number'
  | 'punct'
  | 'plain';

export type CodeToken = { text: string; kind: TokenKind };

type Rule = { kind: TokenKind; source: string };

const HTTP_METHOD = String.raw`GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS`;
const STATUS_LINE_SOURCE = String.raw`\b[1-5]\d{2}\b(?:\s+[A-Z][A-Za-z ]{1,24})?`;
const URL_SOURCE = String.raw`https?:\/\/[^\s"'` + '`' + String.raw`\\<>]+`;
const STRING_D = String.raw`"(?:[^"\\\n]|\\.)*"?`;
const STRING_S = String.raw`'(?:[^'\\\n]|\\.)*'?`;
const STRING_B = '`' + String.raw`(?:[^` + '`' + String.raw`\\]|\\[\s\S])*` + '`';

/** Header names worth calling out inside string literals. */
const HEADER_NAMES = new Set([
  'Authorization',
  'Content-Type',
  'Accept',
  'Accept-Language',
  'X-API-Key',
  'X-Webhook-Signature',
  'Content-Disposition',
  'Content-Length',
  'User-Agent',
  'Cookie',
  'Range',
]);

/** Quoted or bare object keys, resolved in `refine` once a colon follows. */
const HEADER_ALT = [...HEADER_NAMES].join('|');
const HEADER_KEY = String.raw`\b(?:${HEADER_ALT})(?=\s*:)`;
const PLAIN_KEY = String.raw`\b[A-Za-z_$][\w$]*(?=\s*:)`;
const HEADER_KEY_PHP = String.raw`\b(?:${HEADER_ALT})(?=\s*=>)`;
const PLAIN_KEY_PHP = String.raw`\b[A-Za-z_$][\w$]*(?=\s*=>)`;

const RULES: Record<CodeLang, Rule[]> = {
  bash: [
    { kind: 'comment', source: String.raw`#[^\n]*` },
    { kind: 'string', source: STRING_D },
    { kind: 'string', source: STRING_S },
    { kind: 'url', source: URL_SOURCE },
    { kind: 'variable', source: String.raw`\$\{?[A-Za-z_][A-Za-z0-9_]*\}?` },
    { kind: 'flag', source: String.raw`-{1,2}[A-Za-z][A-Za-z0-9_-]*` },
    {
      kind: 'keyword',
      source: String.raw`\b(?:curl|export|echo|printf|node|php|jq|Bearer|true|false|null)\b`,
    },
    { kind: 'method', source: String.raw`\b(?:${HTTP_METHOD})\b` },
    { kind: 'number', source: String.raw`\b\d+(?:\.\d+)?\b` },
    { kind: 'punct', source: String.raw`\\\\|=>|[:=,{}\[\]()|&;?]|\|\|` },
  ],
  javascript: [
    { kind: 'comment', source: String.raw`\/\/[^\n]*` },
    { kind: 'comment', source: String.raw`\/\*[\s\S]*?\*\/` },
    { kind: 'header', source: HEADER_KEY },
    { kind: 'property', source: PLAIN_KEY },
    { kind: 'string', source: STRING_B },
    { kind: 'string', source: STRING_D },
    { kind: 'string', source: STRING_S },
    { kind: 'url', source: URL_SOURCE },
    {
      kind: 'keyword',
      source: String.raw`\b(?:const|let|var|function|async|await|return|new|import|from|export|try|catch|finally|throw|if|else|for|of|in|while|typeof|class|extends)\b`,
    },
    {
      kind: 'keyword',
      source: String.raw`\b(?:true|false|null|undefined|NaN|JSON|FormData|Blob|Error|Object|Array)\b`,
    },
    {
      kind: 'variable',
      source: String.raw`\b(?:fetch|console|process|navigator|window|document|Response|request)\b`,
    },
    { kind: 'method', source: String.raw`\b(?:${HTTP_METHOD})\b` },
    { kind: 'number', source: String.raw`\b\d+(?:\.\d+)?\b` },
    { kind: 'punct', source: String.raw`=>|\.\.\.|\?\.|[:=,;{}()\[\]?|&]` },
  ],
  php: [
    { kind: 'comment', source: String.raw`\/\/[^\n]*` },
    { kind: 'comment', source: String.raw`#[^\n]*` },
    { kind: 'comment', source: String.raw`\/\*[\s\S]*?\*\/` },
    { kind: 'string', source: STRING_D },
    { kind: 'string', source: STRING_S },
    { kind: 'url', source: URL_SOURCE },
    { kind: 'keyword', source: String.raw`<\?php|\?>` },
    { kind: 'header', source: HEADER_KEY_PHP },
    { kind: 'property', source: PLAIN_KEY_PHP },
    {
      kind: 'keyword',
      source: String.raw`\b(?:function|public|private|protected|static|return|new|use|namespace|class|extends|implements|const|echo|print|array|fn|if|else|elseif|foreach|as|for|while|try|catch|finally|throw|true|false|null|CURLOPT_[A-Z0-9_]+|PHP_EOL)\b`,
    },
    {
      kind: 'variable',
      source: String.raw`\b(?:GuzzleHttp\\Client|RequestException|json_encode|json_decode|curl_init|curl_setopt_array|curl_setopt|curl_exec|curl_close|curl_error|http_build_query|fopen|fclose|CURLFILE)\b`,
    },
    { kind: 'method', source: String.raw`\b(?:${HTTP_METHOD})\b` },
    { kind: 'variable', source: String.raw`\$[A-Za-z_][A-Za-z0-9_]*` },
    { kind: 'number', source: String.raw`\b\d+(?:\.\d+)?\b` },
    { kind: 'punct', source: String.raw`=>|->|\.\.\.|[:=,;{}()\[\]?|&]` },
  ],
  json: [
    { kind: 'string', source: STRING_D },
    { kind: 'number', source: String.raw`-?\b\d+(?:\.\d+)?(?:[eE][-+]?\d+)?\b` },
    { kind: 'keyword', source: String.raw`\b(?:true|false|null)\b` },
    { kind: 'punct', source: String.raw`[:=,{}\[\]]` },
  ],
  http: [
    { kind: 'comment', source: String.raw`#[^\n]*` },
    { kind: 'method', source: String.raw`\b(?:${HTTP_METHOD})\b` },
    { kind: 'url', source: String.raw`(?<=\s)\/[^\s'"]*` },
    { kind: 'status', source: STATUS_LINE_SOURCE },
    { kind: 'header', source: String.raw`[A-Za-z][A-Za-z0-9-]*(?=\s*:)` },
    { kind: 'secret', source: String.raw`\ben_[A-Za-z0-9_]+` },
    { kind: 'url', source: URL_SOURCE },
    { kind: 'keyword', source: String.raw`\b(?:HTTP\/[\d.]+|Bearer)\b` },
    { kind: 'string', source: STRING_D },
    { kind: 'number', source: String.raw`\b\d+(?:\.\d+)?\b` },
    { kind: 'punct', source: String.raw`[:=,{}\[\]]` },
  ],
};

/** Cache of compiled scanners: one RegExp per language, built lazily. */
const COMPILED = new Map<CodeLang, { regex: RegExp; rules: Rule[] }>();

function scanner(lang: CodeLang) {
  const cached = COMPILED.get(lang);
  if (cached) return cached;
  const rules = RULES[lang];
  const built = { regex: new RegExp(rules.map((rule) => `(${rule.source})`).join('|'), 'gm'), rules };
  COMPILED.set(lang, built);
  return built;
}

function unwrap(text: string) {
  const first = text[0];
  const last = text[text.length - 1];
  const quoted = (first === '"' || first === "'" || first === '`') && last === first && text.length > 1;
  return { open: quoted ? first : '', inner: quoted ? text.slice(1, -1) : text };
}

/**
 * Split a string literal into coloured fragments. Quotes stay glued to their
 * content so a whole `"key"` still reads as one token, and `${…}` runs inside
 * template literals resolve as variables.
 */
function tokenizeString(text: string): CodeToken[] {
  const { open, inner } = unwrap(text);
  const close = open && text.endsWith(open) && text.length > 1 ? open : '';
  const body = close ? inner : inner;

  const emit = (fragments: CodeToken[]): CodeToken[] => {
    const tokens: CodeToken[] = [];
    if (open) tokens.push({ text: open, kind: 'string' });
    tokens.push(...fragments.filter((token) => token.text !== ''));
    if (close) tokens.push({ text: close, kind: 'string' });
    return tokens;
  };

  if (!body) return [{ text, kind: 'string' }];
  if (open === '"' && /\$\{?[A-Za-z_]/.test(body)) return emit(tokenizeInterpolation(body, true));

  const header = /^([A-Za-z][A-Za-z0-9-]*)(:\s*)([\s\S]*)$/.exec(body);
  if (header && HEADER_NAMES.has(header[1])) {
    return emit([
      { text: header[1], kind: 'header' },
      { text: header[2], kind: 'punct' },
      ...tokenizeRest(header[3]),
    ]);
  }

  if (open === '`' && body.includes('${')) return emit(tokenizeInterpolation(body, false));
  return emit(tokenizeRest(body));
}

/** Break `` `${BASE}/files` `` or `"$BASE/files"` into interpolation + strings. */
function tokenizeInterpolation(value: string, shell: boolean): CodeToken[] {
  const tokens: CodeToken[] = [];
  const expr = shell ? /\$\{[^}]*\}|\$[A-Za-z_]\w*/g : /\$\{[^}]*\}/g;
  let last = 0;
  let match: RegExpExecArray | null;
  while ((match = expr.exec(value)) !== null) {
    if (match.index > last) tokens.push(...tokenizeLiteral(value.slice(last, match.index)));
    tokens.push({ text: match[0], kind: 'variable' });
    last = match.index + match[0].length;
  }
  if (last < value.length) tokens.push(...tokenizeLiteral(value.slice(last)));
  return tokens;
}

function tokenizeLiteral(value: string): CodeToken[] {
  return value ? [{ text: value, kind: 'string' }] : [];
}

function tokenizeRest(value: string): CodeToken[] {
  const bearer = /^Bearer(\s+)([\s\S]*)$/i.exec(value);
  if (bearer) {
    return [
      { text: 'Bearer', kind: 'keyword' },
      { text: bearer[1], kind: 'plain' },
      ...tokenizeLiteral(bearer[2]).map((token) =>
        /^en_/.test(token.text) ? { text: token.text, kind: 'secret' as const } : token,
      ),
    ];
  }
  if (/^en_[A-Za-z0-9_]+$/.test(value)) return [{ text: value, kind: 'secret' }];
  if (new RegExp(`^${URL_SOURCE}$`).test(value)) return [{ text: value, kind: 'url' }];
  if (new RegExp(`^(?:${HTTP_METHOD})$`).test(value)) return [{ text: value, kind: 'method' }];
  return tokenizeLiteral(value);
}

/** Inside comments, HTTP status lines deserve their own colour. */
function tokenizeComment(text: string): CodeToken[] {
  return [{ text, kind: 'comment' }];
}

function ruleIndexFor(groups: Array<string | undefined>, count: number) {
  for (let index = 0; index < count; index += 1) {
    if (groups[index] !== undefined) return index;
  }
  return -1;
}

/**
 * Object keys and PHP assoc keys read as labels, not values: recolour a quoted
 * group when a colon (JS/JSON) or `=>` (PHP array) follows its closing quote.
 */
function refine(tokens: CodeToken[]): CodeToken[] {
  for (let index = 0; index < tokens.length; index += 1) {
    const token = tokens[index];
    const isQuote = token.kind === 'string' && QUOTES.has(token.text);
    if (!isQuote && token.kind !== 'string' && token.kind !== 'property' && token.kind !== 'header') {
      continue;
    }

    let groupEnd = index;
    if (isQuote) {
      groupEnd = -1;
      for (let scan = index + 1; scan < tokens.length; scan += 1) {
        if (tokens[scan].kind === 'string' && tokens[scan].text === token.text) {
          groupEnd = scan;
          break;
        }
      }
      if (groupEnd === -1) continue;
    }

    const next = tokens.slice(groupEnd + 1).find((candidate) => candidate.text.trim() !== '');
    if (!next || !(next.text === ':' || next.text === '=>')) continue;

    const bare = tokens
      .slice(isQuote ? index + 1 : index, isQuote ? groupEnd : groupEnd + 1)
      .map((part) => part.text)
      .join('');
    const kind: TokenKind = HEADER_NAMES.has(bare) ? 'header' : 'property';
    for (let part = index; part <= groupEnd; part += 1) {
      tokens[part] = { text: tokens[part].text, kind };
    }
    index = groupEnd;
  }
  return tokens;
}

const QUOTES = new Set(['"', "'", '`']);

export function tokenizeCode(code: string, lang: CodeLang = 'bash'): CodeToken[] {
  const { regex, rules } = scanner(lang);
  const tokens: CodeToken[] = [];
  let last = 0;
  let match: RegExpExecArray | null;
  regex.lastIndex = 0;

  while ((match = regex.exec(code)) !== null) {
    if (match[0] === '') {
      regex.lastIndex += 1;
      continue;
    }
    if (match.index > last) tokens.push({ text: code.slice(last, match.index), kind: 'plain' });
    const rule = rules[ruleIndexFor(match.slice(1), rules.length)];
    const text = match[0];
    if (rule?.kind === 'string') tokens.push(...tokenizeString(text));
    else if (rule?.kind === 'comment') tokens.push(...tokenizeComment(text));
    else tokens.push({ text, kind: rule?.kind ?? 'plain' });
    last = match.index + text.length;
  }
  if (last < code.length) tokens.push({ text: code.slice(last), kind: 'plain' });

  return refine(tokens);
}

/** Best-effort language guess for callers without an explicit hint. */
export function detectLang(code: string): CodeLang {
  if (/^\s*<\?php/m.test(code)) return 'php';
  if (/^\s*(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+\//m.test(code)) return 'http';
  if (/\bfetch\s*\(|\bconst\s+[\w$]+\s*=|async\s*\(/.test(code)) return 'javascript';
  if (/^\s*[{[]/.test(code.trim())) return 'json';
  return 'bash';
}
