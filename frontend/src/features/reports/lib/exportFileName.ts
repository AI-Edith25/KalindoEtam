/**
 * "{ReportName}_{YYYYMMDD}-{YYYYMMDD}_{HHmm}.{ext}" — mirrors BuildsLegacyReportRows::
 * buildFileName() on the backend exactly. Needed client-side (not just server-side) because
 * downloadBlob() sets its own `download` attribute on a blob: URL, which has no Content-Disposition
 * header to fall back on — shared by all 4 Sales Report tabs' export buttons.
 */
export function reportFileName(reportName: string, dateFrom: string, dateTo: string, format: 'xlsx' | 'csv'): string {
  const toYmd = (iso: string) => iso.replaceAll('-', '')
  const now = new Date()
  const hhmm = String(now.getHours()).padStart(2, '0') + String(now.getMinutes()).padStart(2, '0')

  return `${reportName}_${toYmd(dateFrom)}-${toYmd(dateTo)}_${hhmm}.${format}`
}
