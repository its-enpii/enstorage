export const BULK_FILES_PER_REQUEST = 10;
export const BULK_REQUEST_CONCURRENCY = 3;

export type BulkUploadEntry = {
  file: File;
  jobId: string;
};

export type BulkUploadOutcome = {
  jobId: string;
  success: boolean;
  reason?: string;
  fileId?: string;
};

export type BulkUploadBatch<T> = {
  items: T[];
  index: number;
};

export function createBulkUploadBatches<T>(
  items: readonly T[],
  batchSize = BULK_FILES_PER_REQUEST,
): BulkUploadBatch<T>[] {
  const batches: BulkUploadBatch<T>[] = [];
  for (let offset = 0; offset < items.length; offset += batchSize) {
    batches.push({
      items: items.slice(offset, offset + batchSize),
      index: batches.length,
    });
  }
  return batches;
}

export async function runBoundedConcurrentBatches<T>(
  batches: readonly BulkUploadBatch<T>[],
  concurrency: number,
  runBatch: (batch: BulkUploadBatch<T>) => Promise<void>,
): Promise<void> {
  let nextBatch = 0;

  async function worker(): Promise<void> {
    while (nextBatch < batches.length) {
      const batch = batches[nextBatch++];
      await runBatch(batch);
    }
  }

  await Promise.all(
    Array.from({ length: Math.min(concurrency, batches.length) }, () => worker()),
  );
}

type BulkUploadOptions = {
  baseUrl: string;
  authToken?: string | null;
  folderId: string | null;
  concurrency?: number;
  fetchImpl?: typeof fetch;
  onBatchFailure: (jobIds: string[], reason: string) => void;
  onBatchResult: (outcomes: BulkUploadOutcome[]) => void;
};

export async function uploadBulkFiles(
  entries: readonly BulkUploadEntry[],
  options: BulkUploadOptions,
): Promise<void> {
  const batches = createBulkUploadBatches(entries);
  const request = options.fetchImpl ?? fetch;

  await runBoundedConcurrentBatches(
    batches,
    options.concurrency ?? BULK_REQUEST_CONCURRENCY,
    async (batch) => {
      const formData = new FormData();
      batch.items.forEach(({ file }) => formData.append('files[]', file));
      if (options.folderId) formData.append('folder_id', options.folderId);

      try {
        const response = await request(`${options.baseUrl}/files/bulk-upload`, {
          method: 'POST',
          headers: options.authToken ? { Authorization: `Bearer ${options.authToken}` } : {},
          credentials: 'omit',
          body: formData,
        });
        const body = (await response.json().catch(() => null)) as {
          message?: string;
          data?: {
            results?: {
              index: number;
              success: boolean;
              reason?: string;
              file?: { file_id?: string };
            }[];
          };
        } | null;
        if (!response.ok) {
          options.onBatchFailure(
            batch.items.map(({ jobId }) => jobId),
            body?.message ?? `HTTP ${response.status}`,
          );
          return;
        }

        const resultByIndex = new Map(
          (body?.data?.results ?? []).map((result) => [result.index, result]),
        );
        options.onBatchResult(
          batch.items.map(({ jobId }, index) => {
            const result = resultByIndex.get(index);
            return {
              jobId,
              success: result?.success ?? false,
              reason: result?.reason,
              fileId: result?.file?.file_id,
            };
          }),
        );
      } catch {
        options.onBatchFailure(
          batch.items.map(({ jobId }) => jobId),
          'network',
        );
      }
    },
  );
}
