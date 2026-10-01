import { apiClient } from '@/shared/services/apiClient'
import { downloadBlob } from './downloadBlob'

/** Rejected-rows CSV for any import batch — same generic `{batch}/failed-rows` endpoint every import module writes to. */
export async function downloadImportBatchFailedRows(batchId: string, module: string): Promise<void> {
  const { data } = await apiClient.get(`/import/batches/${batchId}/failed-rows`, { responseType: 'blob' })
  downloadBlob(`${module}-import-failed-rows.csv`, data as Blob)
}
