import { useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchChartOfAccountsLookup } from '@/features/master/api/lookupsApi'
import { confirmBankStatement, uploadBankStatement, type UploadBankStatementResult } from '../api/bankReconciliationApi'
import type { BankStatementFormatTemplate } from '../types'

const FORMAT_OPTIONS: { value: BankStatementFormatTemplate | 'auto'; label: string }[] = [
  { value: 'auto', label: 'Auto-detect' },
  { value: 'bca', label: 'BCA' },
  { value: 'mandiri', label: 'Mandiri' },
]

/** Upload -> preview (parsed, not yet saved) -> Confirm to persist + auto-match against Payment Voucher/Official Receipt. */
export function BankStatementUploadPage() {
  const navigate = useNavigate()
  const fileInputRef = useRef<HTMLInputElement>(null)

  const [bankAccountId, setBankAccountId] = useState('')
  const [formatTemplate, setFormatTemplate] = useState<BankStatementFormatTemplate | 'auto'>('auto')
  const [file, setFile] = useState<File | null>(null)
  const [result, setResult] = useState<UploadBankStatementResult | null>(null)

  const chartOfAccounts = useQuery({ queryKey: ['chart-of-accounts-lookup'], queryFn: fetchChartOfAccountsLookup })
  const bankAccountOptions = chartOfAccounts.data?.filter((account) => account.is_cash_bank).map((account) => ({ value: account.id, label: account.name })) ?? []

  const uploadMutation = useMutation({
    mutationFn: () => uploadBankStatement(bankAccountId, file!, formatTemplate === 'auto' ? undefined : formatTemplate),
    onSuccess: (data) => setResult(data),
    onError: (error) => toastApiError(error),
  })

  const confirmMutation = useMutation({
    mutationFn: () => confirmBankStatement(result!.batch.id),
    onSuccess: () => {
      toast.success('Bank statement saved.')
      navigate(`/finance/bank-reconciliation?bank_account_id=${bankAccountId}`)
    },
    onError: (error) => toastApiError(error),
  })

  const canUpload = bankAccountId !== '' && file !== null && !uploadMutation.isPending

  return (
    <div className="space-y-4">
      <PageHeader title="Upload Bank Statement" description="Upload mutasi rekening koran, preview the parsed rows, then confirm to save." />

      <Card>
        <CardHeader>
          <CardTitle className="text-base">1. Select file</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="space-y-1.5">
              <Label>Bank Account</Label>
              <Select value={bankAccountId} onValueChange={setBankAccountId} disabled={result !== null}>
                <SelectTrigger>
                  <SelectValue placeholder="Select bank account" />
                </SelectTrigger>
                <SelectContent>
                  {bankAccountOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label>Bank / Format Template</Label>
              <Select value={formatTemplate} onValueChange={(value) => setFormatTemplate(value as typeof formatTemplate)} disabled={result !== null}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {FORMAT_OPTIONS.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label>File (CSV)</Label>
              <Input
                ref={fileInputRef}
                type="file"
                accept=".csv,.txt"
                disabled={result !== null}
                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              />
            </div>
          </div>

          {result === null && (
            <Button onClick={() => uploadMutation.mutate()} disabled={!canUpload}>
              {uploadMutation.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Upload className="mr-2 h-4 w-4" />}
              Upload &amp; Preview
            </Button>
          )}
        </CardContent>
      </Card>

      {result && (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="text-base">
              2. Preview ({result.preview_rows.length} rows, detected as {result.batch.format_template.toUpperCase()})
            </CardTitle>
            <div className="flex gap-2">
              <Button variant="outline" onClick={() => { setResult(null); setFile(null); if (fileInputRef.current) fileInputRef.current.value = '' }}>
                Cancel
              </Button>
              <Button onClick={() => confirmMutation.mutate()} disabled={confirmMutation.isPending}>
                {confirmMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Confirm &amp; Save
              </Button>
            </div>
          </CardHeader>
          <CardContent>
            <div className="max-h-[500px] overflow-auto rounded-md border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Date</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead className="text-right">Debit</TableHead>
                    <TableHead className="text-right">Credit</TableHead>
                    <TableHead className="text-right">Balance</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {result.preview_rows.map((row, index) => (
                    <TableRow key={index}>
                      <TableCell>{formatDate(row.transaction_date)}</TableCell>
                      <TableCell className="max-w-xs truncate">{row.description}</TableCell>
                      <TableCell className="text-right">{row.debit_amount > 0 ? formatCurrency(row.debit_amount) : '-'}</TableCell>
                      <TableCell className="text-right">{row.credit_amount > 0 ? formatCurrency(row.credit_amount) : '-'}</TableCell>
                      <TableCell className="text-right">{row.running_balance !== null ? formatCurrency(row.running_balance) : '-'}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
