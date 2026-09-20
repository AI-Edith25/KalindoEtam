import { Fragment, useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronRight, Download, RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { SectionNav } from '@/components/shared/SectionNav'
import { Card, CardContent } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import {
  exportCustomerOutstandingArchive,
  fetchCustomerOutstandingArchiveDetail,
  fetchCustomerOutstandingSnapshots,
} from '../api/customerOutstandingArchiveApi'
import { CustomerOutstandingArchiveFiltersBar } from '../components/CustomerOutstandingArchiveFiltersBar'
import { CustomerOutstandingArchiveImportDialog } from '../components/CustomerOutstandingArchiveImportDialog'
import { emptyCustomerOutstandingArchiveFilters } from '../lib/reportFilters'
import type { CustomerOutstandingArchiveFilterValues } from '../types'

/**
 * Standalone reference notebook for imported legacy AR export snapshots -- deliberately not
 * wired to accounts-receivables/ar-detail or any other live Sales/Invoice/Customer/AR data.
 * Visual style mirrors AccountsReceivableDetailReportPage's "Perincian Piutang" grouped view
 * (customer groups, subtotal rows, Grand Total footer) since that's this app's own established
 * pattern for this exact shape of data, not a new one invented here.
 */
export function CustomerOutstandingArchivePage() {
  const canImport = useHasPermission('reports.ar_archive.import')
  const [importOpen, setImportOpen] = useState(false)
  const [historyOpen, setHistoryOpen] = useState(false)
  const [selectedSnapshotId, setSelectedSnapshotId] = useState<string | null>(null)
  const [filters, setFilters] = useState<CustomerOutstandingArchiveFilterValues>(emptyCustomerOutstandingArchiveFilters)
  const [isExporting, setIsExporting] = useState(false)

  const snapshotsQuery = useQuery({ queryKey: ['customer-outstanding-snapshots'], queryFn: fetchCustomerOutstandingSnapshots })

  // Default to the newest snapshot once the list loads -- never overrides a snapshot the user
  // already picked (e.g. right after importing a new one, which sets it explicitly itself).
  useEffect(() => {
    if (selectedSnapshotId === null && snapshotsQuery.data && snapshotsQuery.data.length > 0) {
      setSelectedSnapshotId(snapshotsQuery.data[0].id)
    }
  }, [snapshotsQuery.data, selectedSnapshotId])

  const detailQuery = useQuery({
    queryKey: ['customer-outstanding-archive-detail', selectedSnapshotId, filters],
    queryFn: () => fetchCustomerOutstandingArchiveDetail(selectedSnapshotId as string, filters),
    enabled: !!selectedSnapshotId,
    placeholderData: (previous) => previous,
  })

  const selectedSnapshot = snapshotsQuery.data?.find((s) => s.id === selectedSnapshotId) ?? detailQuery.data?.snapshot

  const runExport = async (format: 'xlsx' | 'csv') => {
    if (!selectedSnapshotId) return
    setIsExporting(true)
    try {
      const blob = await exportCustomerOutstandingArchive(selectedSnapshotId, filters, format)
      downloadBlob(`PiutangCustomerArsip.${format}`, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="Piutang Customer (Arsip Import)"
        description="Catatan referensi sisa piutang customer dari file export sistem lama — bukan laporan live."
        count={detailQuery.data ? `${formatNumber(detailQuery.data.customers.length)} customer` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Export CSV', icon: Download, disabled: !selectedSnapshotId || isExporting, onClick: () => runExport('csv') },
              { label: 'Export XLSX', icon: Download, disabled: !selectedSnapshotId || isExporting, onClick: () => runExport('xlsx') },
              { label: 'Refresh', icon: RotateCw, onClick: () => detailQuery.refetch(), disabled: detailQuery.isFetching },
            ]}
            primary={{ label: 'Import Data', icon: Upload, disabled: !canImport, onClick: () => setImportOpen(true) }}
          />
        }
      />

      <Card>
        <CardContent className="py-3 text-sm text-muted-foreground">
          Data import manual — bukan data live. Snapshot per {selectedSnapshot ? formatDate(selectedSnapshot.snapshot_as_of_date) : '—'}.
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Snapshot / As Of Date</span>
          <Select value={selectedSnapshotId ?? undefined} onValueChange={setSelectedSnapshotId}>
            <SelectTrigger className="w-64">
              <SelectValue placeholder={snapshotsQuery.isLoading ? 'Memuat…' : 'Pilih snapshot…'} />
            </SelectTrigger>
            <SelectContent>
              {(snapshotsQuery.data ?? []).map((snapshot) => (
                <SelectItem key={snapshot.id} value={snapshot.id}>
                  {formatDate(snapshot.snapshot_as_of_date)} — {snapshot.source_filename}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <CustomerOutstandingArchiveFiltersBar value={filters} onChange={setFilters} />
      </div>

      <Card>
        <button type="button" onClick={() => setHistoryOpen((v) => !v)} className="flex w-full items-center gap-2 p-3 text-left text-sm font-medium">
          {historyOpen ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
          Riwayat Import
        </button>
        {historyOpen && (
          <CardContent className="pt-0">
            <div className="overflow-x-auto rounded-md border">
              <Table>
                <TableBody>
                  <TableRow className="bg-muted/50 font-medium">
                    <TableCell>File</TableCell>
                    <TableCell>Waktu Import</TableCell>
                    <TableCell className="text-right">Baris</TableCell>
                    <TableCell className="text-right">Customer</TableCell>
                    <TableCell>Diimpor Oleh</TableCell>
                  </TableRow>
                  {(snapshotsQuery.data ?? []).map((snapshot) => (
                    <TableRow key={snapshot.id}>
                      <TableCell>{snapshot.source_filename}</TableCell>
                      <TableCell>{formatDate(snapshot.created_at)}</TableCell>
                      <TableCell className="text-right">{formatNumber(snapshot.total_rows)}</TableCell>
                      <TableCell className="text-right">{formatNumber(snapshot.total_customers)}</TableCell>
                      <TableCell>{snapshot.importer?.name ?? '—'}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </CardContent>
        )}
      </Card>

      {!selectedSnapshotId ? (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">Belum ada snapshot — import file untuk memulai.</CardContent>
        </Card>
      ) : !detailQuery.data || detailQuery.data.customers.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center gap-2 py-10 text-center text-muted-foreground">
            <Download className="size-8 opacity-40" />
            {detailQuery.isLoading ? 'Loading…' : 'Tidak ada data yang cocok dengan filter.'}
          </CardContent>
        </Card>
      ) : (
        <>
          <div className="overflow-x-auto rounded-md border">
            <Table>
              <TableBody>
                {detailQuery.data.customers.map((customer) => (
                  <Fragment key={customer.customer_code}>
                    <TableRow className="bg-muted/20">
                      <TableCell colSpan={8} className="font-medium">
                        {customer.customer_code} — {customer.customer_name}
                      </TableCell>
                    </TableRow>
                    <TableRow className="bg-muted/50 text-xs">
                      <TableCell>Date</TableCell>
                      <TableCell>Ref. No</TableCell>
                      <TableCell className="text-right">Invoice Amt</TableCell>
                      <TableCell className="text-right">Paid</TableCell>
                      <TableCell className="text-right">Unpaid</TableCell>
                      <TableCell>Due Date</TableCell>
                      <TableCell className="text-right">Overdue Amt</TableCell>
                      <TableCell>Status</TableCell>
                    </TableRow>
                    {customer.rows.map((row) => (
                      <TableRow key={row.id}>
                        <TableCell>{formatDate(row.txn_date)}</TableCell>
                        <TableCell>{row.ref_no}</TableCell>
                        <TableCell className="text-right">{formatCurrency(row.invoice_amount)}</TableCell>
                        <TableCell className="text-right">{formatCurrency(row.paid_amount)}</TableCell>
                        <TableCell className="text-right">{formatCurrency(row.unpaid_amount)}</TableCell>
                        <TableCell>{formatDate(row.due_date)}</TableCell>
                        <TableCell className="text-right">
                          {formatCurrency(row.overdue_amount)}
                          {row.overdue_days > 0 && <span className="ml-1 text-xs text-muted-foreground">({row.overdue_days}d)</span>}
                        </TableCell>
                        <TableCell><StatusBadge status={row.status} /></TableCell>
                      </TableRow>
                    ))}
                    <TableRow>
                      <TableCell colSpan={4} className="text-right font-medium">
                        Subtotal — {customer.customer_name}
                      </TableCell>
                      <TableCell className="text-right font-medium">{formatCurrency(customer.subtotal_unpaid)}</TableCell>
                      <TableCell colSpan={2} />
                      <TableCell className="text-right font-medium">{formatCurrency(customer.subtotal_overdue)}</TableCell>
                    </TableRow>
                  </Fragment>
                ))}
              </TableBody>
            </Table>
          </div>

          <Card>
            <CardContent className="flex flex-wrap items-center justify-end gap-6 py-4 text-base">
              <span className="flex items-center gap-2">
                <span className="text-muted-foreground">Grand Total Unpaid</span>
                <span className="font-semibold">{formatCurrency(detailQuery.data.grand_total_unpaid)}</span>
              </span>
              <span className="flex items-center gap-2">
                <span className="text-muted-foreground">Grand Total Overdue</span>
                <span className="font-semibold">{formatCurrency(detailQuery.data.grand_total_overdue)}</span>
              </span>
            </CardContent>
          </Card>
        </>
      )}

      <CustomerOutstandingArchiveImportDialog
        open={importOpen}
        onClose={() => setImportOpen(false)}
        onImported={(snapshot) => setSelectedSnapshotId(snapshot.id)}
      />
    </div>
  )
}
