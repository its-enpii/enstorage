/**
 * Turns one declarative `SnippetCall` into the code samples rendered by
 * `MultiLangSnippet` (cURL, JavaScript, PHP cURL, PHP Guzzle). Every documented
 * request exists once, so the language variants can never drift apart from each
 * other or from the endpoint catalog.
 */

export type SnippetMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export type FilePart = { field: string; path: string; type?: string };

export type SnippetCall = {
  method: SnippetMethod;
  /** Public endpoint: no `Authorization` header is emitted. */
  public?: boolean;
  /** Path template relative to the API prefix; `{param}` is replaced. */
  path: string;
  pathParams?: Record<string, string>;
  query?: Record<string, string | number | boolean | undefined>;
  /** JSON request body. */
  body?: unknown;
  /** Plain multipart fields. */
  fields?: Record<string, string | number | boolean>;
  /** Multipart file parts. */
  files?: FilePart[];
  /** Binary response: save the stream under this filename. */
  binary?: string;
  /** Expected status line, e.g. `202 Accepted`. */
  expect?: string;
  /** Comment lines shown above the request. */
  notes?: string[];
};

export type SnippetSet = {
  curl: string;
  javascript: string;
  php: string;
  phpGuzzle: string;
};

export const SAMPLE_BASE = 'https://api.example.com/api/v1';
export const SAMPLE_KEY = 'en_a1b2c3d4_e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4';
export const SAMPLE_FILE_ID = '9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7';
export const SAMPLE_FOLDER_ID = '4d0cbe31-0a58-4c6f-9d2b-7f1e5a3c8b64';
export const SAMPLE_ACCOUNT_ID = '6c4f1d8a-2b97-4e51-9f0c-38ad7e2b5c14';
export const SAMPLE_SHARE_ID = 'b2f7d914-3c6a-4f0d-8e51-6a2d9c7b4f10';
export const SAMPLE_SHARE_TOKEN = '3f9a1c7e5b2d48af90c6e1d7a3b5c8f0';

/** Placeholders accepted in `path`, resolved to believable sample values. */
const PATH_SAMPLES: Record<string, string> = {
  id: SAMPLE_FILE_ID,
  fileId: SAMPLE_FILE_ID,
  folderId: SAMPLE_FOLDER_ID,
  accountId: SAMPLE_ACCOUNT_ID,
  token: SAMPLE_SHARE_TOKEN,
  shareId: SAMPLE_SHARE_ID,
  keyId: '3d7e1a52-9c46-4b18-8f0d-2e5a7c1b9d43',
  webhookId: '8a2f5c90-1d74-4b6e-9c3a-5f0d8e2b7146',
  chunkIndex: '0',
};

function resolvePath(call: SnippetCall) {
  return call.path.replace(/\{([A-Za-z0-9_]+)\}/g, (_match, name: string) => {
    const value = call.pathParams?.[name] ?? PATH_SAMPLES[name];
    return value ?? `{${name}}`;
  });
}

function queryString(call: SnippetCall) {
  const pairs = Object.entries(call.query ?? {}).map(([key, value]) => `${key}=${value ?? ''}`);
  return pairs.length ? `?${pairs.join('&')}` : '';
}

function headerEntries(call: SnippetCall): Array<[string, string]> {
  const accept = call.binary ? '*/*' : 'application/json';
  const headers: Array<[string, string]> = call.public
    ? [['Accept', accept]]
    : [['Authorization', 'Bearer <key>'], ['Accept', accept]];
  if (call.body !== undefined) headers.splice(1, 0, ['Content-Type', 'application/json']);
  return headers;
}

function commentBlock(prefix: string, lines: string[] = []) {
  return lines.filter(Boolean).map((line) => `${prefix} ${line}`);
}

/** JavaScript object literal, pretty printed from `pad` inward. */
function jsValue(value: unknown, pad: string): string {
  if (Array.isArray(value)) {
    if (value.every((item) => typeof item !== 'object' || item === null)) {
      return `[${value.map((item) => jsLiteral(item)).join(', ')}]`;
    }
    const inner = value.map((item) => `${pad}  ${jsValue(item, `${pad}  `)},`);
    return `[\n${inner.join('\n')}\n${pad}]`;
  }
  if (value && typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>);
    if (entries.length === 0) return '{}';
    const inner = entries.map(
      ([key, item]) => `${pad}  ${jsKey(key)}: ${jsValue(item, `${pad}  `)},`,
    );
    return `{\n${inner.join('\n')}\n${pad}}`;
  }
  return jsLiteral(value);
}

function jsLiteral(value: unknown) {
  if (typeof value === 'boolean' || value === null || value === undefined) return String(value);
  if (typeof value === 'number') return String(value);
  return `'${String(value)}'`;
}

function jsKey(key: string) {
  return /^[A-Za-z_$][\w$]*$/.test(key) ? key : `'${key}'`;
}

/** PHP array literal with `=>` arrows, pretty printed from `pad` inward. */
function phpValue(value: unknown, pad: string): string {
  if (Array.isArray(value)) {
    if (value.every((item) => typeof item !== 'object' || item === null)) {
      return `[${value.map((item) => phpLiteral(item)).join(', ')}]`;
    }
    const inner = value.map((item) => `${pad}  ${phpValue(item, `${pad}  `)},`);
    return `[\n${inner.join('\n')}\n${pad}]`;
  }
  if (value && typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>);
    if (entries.length === 0) return '[]';
    const inner = entries.map(
      ([key, item]) => `${pad}  '${key}' => ${phpValue(item, `${pad}  `)},`,
    );
    return `[\n${inner.join('\n')}\n${pad}]`;
  }
  return phpLiteral(value);
}

function phpLiteral(value: unknown) {
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (value === null || value === undefined) return 'null';
  if (typeof value === 'number') return String(value);
  return `'${String(value)}'`;
}

/** Compact single-line JSON for the cURL `-d` payload. */
function compactJson(value: unknown) {
  return JSON.stringify(value ?? {});
}

/* ---------------------------------- curl ---------------------------------- */

function buildCurl(call: SnippetCall): string {
  const url = `"$ENSTORAGE_BASE${resolvePath(call)}${queryString(call)}"`;
  const lines: string[] = [...commentBlock('#', call.notes)];
  if (call.expect) lines.push(`# Expected: ${call.expect}`);

  const parts = [`curl -sS -X ${call.method} ${url}`];
  for (const [name, value] of headerEntries(call)) {
    parts.push(`  -H "${name}: ${value.replace('<key>', '$ENSTORAGE_KEY')}"`);
  }
  for (const file of call.files ?? []) {
    parts.push(`  -F "${file.field}=@${file.path}"`);
  }
  for (const [key, value] of Object.entries(call.fields ?? {})) {
    parts.push(`  -F "${key}=${value}"`);
  }
  if (call.body !== undefined) parts.push(`  -d '${compactJson(call.body)}'`);
  if (call.binary) parts.push(`  -o "${call.binary}"`);
  lines.push(parts.join(' \\\n'));
  return lines.join('\n');
}

/* -------------------------------- javascript ------------------------------- */

function buildJavaScript(call: SnippetCall): string {
  const path = resolvePath(call);
  const query = queryString(call);
  const lines: string[] = [...commentBlock('//', call.notes)];

  const imports = [
    ...(call.files?.length ? ['readFile'] : []),
    ...(call.binary ? ['writeFile'] : []),
  ];
  if (imports.length) lines.push(`import { ${imports.join(', ')} } from 'node:fs/promises';`);
  lines.push('', `const BASE = '${SAMPLE_BASE}';`);
  if (!call.public) lines.push(`const KEY = '${SAMPLE_KEY}';`);

  if (call.files?.length) {
    lines.push('', 'const form = new FormData();');
    for (const file of call.files) {
      const type = file.type ?? 'application/octet-stream';
      const name = file.path.split('/').pop() ?? file.path;
      lines.push(
        `form.append('${file.field}', new Blob([await readFile('${file.path}')], { type: '${type}' }), '${name}');`,
      );
    }
    for (const [key, value] of Object.entries(call.fields ?? {})) {
      lines.push(`form.append('${key}', '${value}');`);
    }
  }

  lines.push('', `const response = await fetch(\`\${BASE}${path}${query}\`, {`);
  lines.push(`  method: '${call.method}',`);
  lines.push('  headers: {');
  if (!call.public) lines.push('    Authorization: `Bearer ${KEY}`,');
  if (call.body !== undefined) lines.push("    'Content-Type': 'application/json',");
  if (call.body === undefined && !call.binary) lines.push("    Accept: 'application/json',");
  lines.push('  },');
  if (call.body !== undefined) {
    lines.push(`  body: JSON.stringify(${jsValue(call.body, '  ')}),`);
  } else if (call.files?.length) {
    lines.push('  // The multipart boundary is added by fetch, not by us.');
    lines.push('  body: form,');
  }
  lines.push('});');

  if (call.binary) {
    lines.push(
      '',
      '// Binary body: buffer the stream and write it straight to disk.',
      'const chunks = [];',
      'for await (const chunk of response.body) chunks.push(Buffer.from(chunk));',
      `await writeFile('${call.binary}', Buffer.concat(chunks));`,
      `console.log('${call.binary}', response.status, response.headers.get('content-type'));`,
    );
  } else {
    lines.push(
      '',
      `const body = await response.json(); ${call.expect ? `// ${call.expect}` : ''}`.trimEnd(),
      'console.log(body.success, body.data, body.message);',
    );
  }
  return lines.join('\n').replace(/^\n+/, '');
}

/* ----------------------------------- php ---------------------------------- */

function phpRawHeader(name: string, value: string) {
  return value.includes('<key>')
    ? `'${name}: ${value.replace('<key>', '').trimEnd()} ' . $key`
    : `'${name}: ${value}'`;
}

function phpPairHeader(name: string, value: string) {
  return value.includes('<key>')
    ? `'${name}' => '${value.replace('<key>', '').trimEnd()} ' . $key,`
    : `'${name}' => '${value}',`;
}

function phpHead(call: SnippetCall, guzzle: boolean): string[] {
  const notes = commentBlock('//', call.notes);
  const lines = ['<?php', '', ...(notes.length ? [...notes, ''] : [])];
  if (guzzle) {
    lines.push(
      '// composer require guzzlehttp/guzzle',
      "require __DIR__ . '/vendor/autoload.php';",
      '',
      'use GuzzleHttp\\Client;',
      'use GuzzleHttp\\Exception\\RequestException;',
      '',
      `$client = new Client(['base_uri' => '${SAMPLE_BASE}', 'timeout' => 60]);`,
    );
  } else {
    lines.push(`$base = '${SAMPLE_BASE}';`);
  }
  if (call.public) return lines;
  lines.push(`$key = '${SAMPLE_KEY}';`);
  return lines;
}

function buildPhp(call: SnippetCall): string {
  const path = resolvePath(call);
  const query = queryString(call);
  const lines = phpHead(call, false);

  lines.push('', `$curl = curl_init($base . '${path}${query}');`, 'curl_setopt_array($curl, [');
  lines.push('    CURLOPT_RETURNTRANSFER => true,');
  lines.push(`    CURLOPT_CUSTOMREQUEST  => '${call.method}',`);
  lines.push('    CURLOPT_HTTPHEADER     => [');
  for (const [name, value] of headerEntries(call)) {
    lines.push(`        ${phpRawHeader(name, value)},`);
  }
  lines.push('    ],');

  const fields: string[] = [];
  for (const file of call.files ?? []) {
    const type = file.type ?? 'application/octet-stream';
    const name = file.path.split('/').pop() ?? file.path;
    fields.push(`'${file.field}' => new CURLFile('${file.path}', '${type}', '${name}'),`);
  }
  for (const [key, value] of Object.entries(call.fields ?? {})) {
    fields.push(`'${key}' => '${value}',`);
  }

  if (call.body !== undefined) {
    lines.push(`    CURLOPT_POSTFIELDS     => json_encode(${phpValue(call.body, '    ')}),`);
  } else if (fields.length) {
    lines.push('    CURLOPT_POSTFIELDS     => [');
    for (const field of fields) lines.push(`        ${field}`);
    lines.push('    ],');
  }
  lines.push(
    ']);',
    '',
    '$response = curl_exec($curl);',
    '',
    'if ($response === false) {',
    '    fwrite(STDERR, curl_error($curl) . PHP_EOL);',
    '} else {',
    call.binary ? `    file_put_contents('${call.binary}', $response);` : '    echo $response;',
    '}',
    '',
    'curl_close($curl);',
  );
  return lines.join('\n');
}

function buildPhpGuzzle(call: SnippetCall): string {
  const path = resolvePath(call);
  const query = queryString(call);
  const lines = phpHead(call, true);

  const options: string[] = [
    [
      "    'headers' => [",
      ...headerEntries(call).map(([name, value]) => `      ${phpPairHeader(name, value)}`),
      '    ],',
    ].join('\n'),
  ];
  if (call.body !== undefined) options.push(`    'json' => ${phpValue(call.body, '    ')},`);

  const parts: string[] = [];
  for (const file of call.files ?? []) {
    parts.push(
      `      ['name' => '${file.field}', 'filename' => '${file.path}', 'contents' => fopen('${file.path}', 'r')],`,
    );
  }
  for (const [key, value] of Object.entries(call.fields ?? {})) {
    parts.push(`      ['name' => '${key}', 'contents' => '${value}'],`);
  }
  if (parts.length) options.push(["    'multipart' => [", ...parts, '    ],'].join('\n'));

  lines.push('', 'try {');
  lines.push(`    $response = $client->request('${call.method}', '${path}${query}', [`);
  for (const option of options) lines.push(option);
  lines.push('    ]);', '');
  lines.push(`    $status = $response->getStatusCode(); ${call.expect ? `// ${call.expect}` : ''}`.trimEnd());
  if (call.binary) {
    lines.push(`    $response->getBody()->writeTo(fopen('${call.binary}', 'w'));`);
  } else {
    lines.push('    $payload = json_decode((string) $response->getBody(), true);');
    lines.push('    echo json_encode($payload, JSON_PRETTY_PRINT), PHP_EOL;');
  }
  lines.push(
    '} catch (RequestException $error) {',
    '    $status = $error->getResponse()?->getStatusCode() ?? 0;',
    '    fwrite(STDERR, $error->getMessage() . PHP_EOL);',
    '}',
  );
  return lines.join('\n');
}

/** Build every language variant for one documented request. */
export function buildSnippets(call: SnippetCall): SnippetSet {
  return {
    curl: buildCurl(call),
    javascript: buildJavaScript(call),
    php: buildPhp(call),
    phpGuzzle: buildPhpGuzzle(call),
  };
}
