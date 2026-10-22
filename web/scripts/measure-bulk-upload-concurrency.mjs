import { createServer } from 'node:http';

import { uploadBulkFiles } from '../src/lib/bulkUpload.ts';

const FILE_COUNT = 30;
const RESPONSE_DELAY_MS = 60;
const originalFetch = globalThis.fetch;

function listen(server) {
  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => resolve(server.address().port));
  });
}

function close(server) {
  return new Promise((resolve, reject) => {
    server.close((error) => (error ? reject(error) : resolve()));
  });
}

async function measure(label, concurrency) {
  let activeFetches = 0;
  let maxActiveFetches = 0;
  let activeServerRequests = 0;
  let maxActiveServerRequests = 0;
  let requestCount = 0;

  const server = createServer((request, response) => {
    requestCount++;
    activeServerRequests++;
    maxActiveServerRequests = Math.max(maxActiveServerRequests, activeServerRequests);

    const chunks = [];
    request.on('data', (chunk) => chunks.push(chunk));
    request.on('end', () => {
      const body = Buffer.concat(chunks).toString('utf8');
      const fileCount = (body.match(/name="files\[\]"/g) ?? []).length;
      setTimeout(() => {
        activeServerRequests--;
        response.writeHead(200, { 'content-type': 'application/json' });
        response.end(JSON.stringify({
          success: true,
          data: {
            results: Array.from({ length: fileCount }, (_, index) => ({
              index,
              success: true,
              name: 'duplicate.txt',
              file: { file_id: `file-${requestCount}-${index}` },
            })),
          },
        }));
      }, RESPONSE_DELAY_MS);
    });
  });

  const port = await listen(server);
  const instrumentedFetch = async (...args) => {
    activeFetches++;
    maxActiveFetches = Math.max(maxActiveFetches, activeFetches);
    try {
      return await originalFetch(...args);
    } finally {
      activeFetches--;
    }
  };

  const entries = Array.from({ length: FILE_COUNT }, (_, index) => ({
    file: new File(['payload'], 'duplicate.txt', { type: 'text/plain' }),
    jobId: `job-${index}`,
  }));
  const startedAt = performance.now();
  await uploadBulkFiles(entries, {
    baseUrl: `http://127.0.0.1:${port}`,
    folderId: null,
    concurrency,
    fetchImpl: instrumentedFetch,
    onBatchFailure: (_jobIds, reason) => {
      throw new Error(`Unexpected batch failure: ${reason}`);
    },
    onBatchResult: () => {},
  });
  const elapsedMs = Math.round(performance.now() - startedAt);

  await close(server);
  console.log(
    `${label} concurrency=${concurrency} requests=${requestCount} `
    + `request_size_files=${FILE_COUNT / requestCount} `
    + `max_concurrent_fetch=${maxActiveFetches} `
    + `max_concurrent_server=${maxActiveServerRequests} `
    + `elapsed_ms=${elapsedMs}`,
  );
}

await measure('before', 1);
await measure('after', 3);
