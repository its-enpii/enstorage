import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.resolve(__dirname, '../..');

const apiPhpPath = path.join(rootDir, 'backend/routes/api.php');
const catalogPath = path.join(rootDir, 'web/src/lib/apiCatalog.ts');

if (!fs.existsSync(apiPhpPath)) {
  console.error(`ERROR: Backend routes not found at ${apiPhpPath}`);
  process.exit(1);
}

if (!fs.existsSync(catalogPath)) {
  console.error(`ERROR: API catalog not found at ${catalogPath}`);
  process.exit(1);
}

// Read and parse apiCatalog.ts
const catalogContent = fs.readFileSync(catalogPath, 'utf8');

// Normalize param names: {id}, {fileId}, {folderId}, {shareId}, {token} -> {param}
function normPath(p) {
  return '/' + p.replace(/^\/?api\/v1\/?/, '').replace(/^\//, '').replace(/\{[^}]+\}/g, '{param}');
}

// 1. Extract endpoints from ENDPOINT_GROUPS in catalog
const groupEndpoints = [];
const groupMatch = catalogContent.match(/export const ENDPOINT_GROUPS: EndpointGroup\[\] = (\[[\s\S]*?\]);/);
if (groupMatch) {
  const epRegex = /method:\s*['"]([A-Z]+)['"]\s*,\s*path:\s*['"]([^'"]+)['"]/g;
  let m;
  while ((m = epRegex.exec(groupMatch[1])) !== null) {
    groupEndpoints.push({ method: m[1], path: m[2], norm: normPath(m[2]) });
  }
}

// 2. Extract detailed reference entries in REFERENCE
const refEntries = [];
const refStartIndex = catalogContent.indexOf('export const REFERENCE: ReferenceEntry[] = [');
if (refStartIndex !== -1) {
  const refSection = catalogContent.slice(refStartIndex);
  const entryRegex = /id:\s*['"]([^'"]+)['"][\s\S]*?method:\s*['"]([A-Z]+)['"]\s*,\s*path:\s*['"]([^'"]+)['"][\s\S]*?response:\s*\{[\s\S]*?status:\s*['"]([0-9]+)['"]/g;
  let m;
  while ((m = entryRegex.exec(refSection)) !== null) {
    refEntries.push({ id: m[1], method: m[2], path: m[3], status: m[4], norm: normPath(m[3]) });
  }
}

// 3. Known consumer backend routes
// Extracted from php artisan route:list --path=api
const CONSUMER_ROUTES = [
  // Auth (11)
  { method: 'POST', path: '/auth/register' },
  { method: 'POST', path: '/auth/login' },
  { method: 'POST', path: '/auth/logout' },
  { method: 'GET', path: '/auth/me' },
  { method: 'PATCH', path: '/auth/me' },
  { method: 'POST', path: '/auth/change-password' },
  { method: 'PATCH', path: '/auth/locale' },
  { method: 'POST', path: '/auth/google' },
  { method: 'GET', path: '/auth/google/redirect' },
  { method: 'GET', path: '/auth/google/callback' },
  { method: 'DELETE', path: '/auth/account' },

  // API Keys (3)
  { method: 'GET', path: '/api-keys' },
  { method: 'POST', path: '/api-keys' },
  { method: 'DELETE', path: '/api-keys/{id}' },

  // Storage summary (1)
  { method: 'GET', path: '/storage/summary' },

  // Google Accounts (11)
  { method: 'GET', path: '/google-accounts' },
  { method: 'GET', path: '/google-accounts/{id}' },
  { method: 'PATCH', path: '/google-accounts/{id}' },
  { method: 'DELETE', path: '/google-accounts/{id}' },
  { method: 'POST', path: '/google-accounts/{id}/sync-quota' },
  { method: 'POST', path: '/google-accounts/scan' },
  { method: 'POST', path: '/google-accounts/{id}/scan' },
  { method: 'GET', path: '/google-accounts/oauth/redirect' },
  { method: 'POST', path: '/google-accounts/oauth/exchange' },
  { method: 'POST', path: '/google-accounts/oauth/callback' },
  { method: 'GET', path: '/google-accounts/oauth/callback-web' },

  // Files & Uploads (20)
  { method: 'POST', path: '/files/upload' },
  { method: 'POST', path: '/files/upload-from-url' },
  { method: 'POST', path: '/files/upload/init' },
  { method: 'POST', path: '/files/upload/{fileId}/chunk/{chunkIndex}' },
  { method: 'POST', path: '/files/upload/{fileId}/complete' },
  { method: 'GET', path: '/files' },
  { method: 'GET', path: '/files/by-hashes' },
  { method: 'POST', path: '/files/by-metadata' },
  { method: 'GET', path: '/files/{id}' },
  { method: 'GET', path: '/files/{id}/status' },
  { method: 'GET', path: '/files/{id}/download' },
  { method: 'GET', path: '/files/{id}/thumbnail' },
  { method: 'PATCH', path: '/files/{id}' },
  { method: 'PUT', path: '/files/{id}/move' },
  { method: 'DELETE', path: '/files/{id}' },
  { method: 'POST', path: '/files/bulk-delete' },
  { method: 'POST', path: '/files/{id}/share' },
  { method: 'DELETE', path: '/files/{id}/share' },
  { method: 'GET', path: '/s/{token}' },
  { method: 'GET', path: '/s/{token}/view' },

  // Folders (9)
  { method: 'GET', path: '/folders' },
  { method: 'POST', path: '/folders' },
  { method: 'GET', path: '/folders/{id}' },
  { method: 'PATCH', path: '/folders/{id}' },
  { method: 'DELETE', path: '/folders/{id}' },
  { method: 'GET', path: '/folders/{id}/download' },
  { method: 'PUT', path: '/folders/{id}/move' },
  { method: 'POST', path: '/folders/{id}/share' },
  { method: 'DELETE', path: '/folders/{id}/share' },

  // Share Links (5)
  { method: 'GET', path: '/files/{id}/share-links' },
  { method: 'POST', path: '/files/{id}/share-links' },
  { method: 'GET', path: '/folders/{id}/share-links' },
  { method: 'POST', path: '/folders/{id}/share-links' },
  { method: 'DELETE', path: '/share-links/{id}' },

  // Search & Recent (2)
  { method: 'GET', path: '/search/files' },
  { method: 'GET', path: '/recent' },

  // Webhooks (4)
  { method: 'GET', path: '/webhooks' },
  { method: 'POST', path: '/webhooks' },
  { method: 'PATCH', path: '/webhooks/{id}' },
  { method: 'DELETE', path: '/webhooks/{id}' },

  // Notifications (4)
  { method: 'GET', path: '/notifications/settings' },
  { method: 'PATCH', path: '/notifications/settings' },
  { method: 'POST', path: '/notifications/token' },
  { method: 'DELETE', path: '/notifications/token' },

  // Admin / Health (1)
  { method: 'GET', path: '/admin/ping' },
];

console.log('='.repeat(80));
console.log('ENSTORAGE API DOCUMENTATION COVERAGE AUDIT');
console.log(`Backend Consumer Endpoints : ${CONSUMER_ROUTES.length}`);
console.log(`Web Directory Endpoints    : ${groupEndpoints.length}`);
console.log(`Detailed Reference Cards   : ${refEntries.length}`);
console.log('='.repeat(80));

let missingFromCatalog = 0;
let missingFromReference = 0;

for (const route of CONSUMER_ROUTES) {
  const n = normPath(route.path);
  const inCatalog = groupEndpoints.some(e => e.method === route.method && e.norm === n);
  const inRef = refEntries.some(e => e.method === route.method && e.norm === n);

  if (!inCatalog) missingFromCatalog++;
  if (!inRef) missingFromReference++;

  const catStatus = inCatalog ? 'CATALOG: OK ' : 'CATALOG: MISS';
  const refStatus = inRef ? 'DETAILS: OK ' : 'DETAILS: MISS';
  const flag = (!inCatalog || !inRef) ? '(!)' : '   ';

  console.log(`${flag} [${route.method.padEnd(6)}] ${route.path.padEnd(45)} | ${catStatus} | ${refStatus}`);
}

console.log('-'.repeat(80));
const catPercent = ((CONSUMER_ROUTES.length - missingFromCatalog) / CONSUMER_ROUTES.length * 100).toFixed(1);
const refPercent = ((CONSUMER_ROUTES.length - missingFromReference) / CONSUMER_ROUTES.length * 100).toFixed(1);

console.log(`Catalog Directory Coverage : ${CONSUMER_ROUTES.length - missingFromCatalog} / ${CONSUMER_ROUTES.length} (${catPercent}%)`);
console.log(`Detailed Reference Coverage: ${CONSUMER_ROUTES.length - missingFromReference} / ${CONSUMER_ROUTES.length} (${refPercent}%)`);

if (missingFromCatalog === 0 && missingFromReference === 0) {
  console.log('\n✅ AUDIT PASSED: 100% of consumer backend routes are documented with full details!');
  process.exit(0);
} else {
  console.log(`\n❌ AUDIT INCOMPLETE: ${missingFromCatalog} missing from catalog, ${missingFromReference} missing detailed request/response.`);
  process.exit(1);
}
