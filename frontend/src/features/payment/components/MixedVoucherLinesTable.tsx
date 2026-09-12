import { useQuery } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatCurrency } from '@/lib/utils'
import { fetchAccountsPayables } from '../api/accountsPayableApi'
import type { MixedVoucherLineDraft } from '../lib/paymentEntryFormSchema'

interface Option {
  value: string
  label: string
}

interface MixedVoucherLinesTableProps {
  lines: MixedVoucherLineDraft[]
  onChange: (lines: MixedVoucherLineDraft[]) => void
  supplierOptions: Option[]
  suppliersLoading: boolean
  expenseAccountOptions: Option[]
  expenseAccountsLoading: boolean
  branchOptions: Option[]
  branchesLoading: boolean
}

function emptyLine(): MixedVoucherLineDraft {
  return {
    client_id: crypto.randomUUID(),
    type: 'supplier',
    accounts_payable_supplier_id: '',
    accounts_payable_id: '',
    accounts_payable_reference: null,
    accounts_payable_outstanding: null,
    expense_account_id: '',
    description: '',
    branch_id: '',
    amount: '',
    notes: '',
  }
}

/**
 * Manual line-by-line editor for a payment_type=mixed voucher's "Payment Allocation Lines" —
 * each row picks its own Purpose Type and (for Supplier Bill rows) its own Supplier, so a
 * voucher can span several different suppliers in one go. This is the per-row fallback for
 * ad-hoc lines; the more common path for adding several of one supplier's bills at once is the
 * "quick add" panel on OutgoingPaymentEditorPage, which reuses OutstandingPayablesTable and
 * appends rows into this same array — both write into the identical MixedVoucherLineDraft shape.
 */
export function MixedVoucherLinesTable({
  lines,
  onChange,
  supplierOptions,
  suppliersLoading,
  expenseAccountOptions,
  expenseAccountsLoading,
  branchOptions,
  branchesLoading,
}: MixedVoucherLinesTableProps) {
  function updateLine(clientId: string, patch: Partial<MixedVoucherLineDraft>) {
    onChange(lines.map((line) => (line.client_id === clientId ? { ...line, ...patch } : line)))
  }

  function removeLine(clientId: string) {
    onChange(lines.filter((line) => line.client_id !== clientId))
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-40">Purpose Type</TableHead>
              <TableHead className="min-w-64">Supplier / Bill or Category / Description</TableHead>
              <TableHead className="w-40">Branch</TableHead>
              <TableHead className="w-40 text-right">Amount</TableHead>
              <TableHead className="w-40">Notes</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {lines.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="py-6 text-center text-sm text-muted-foreground">
                  No allocation lines yet. Add one below, or check bills from Outstanding Payables.
                </TableCell>
              </TableRow>
            )}
            {lines.map((line) => (
              <MixedVoucherLineRow
                key={line.client_id}
                line={line}
                onChange={(patch) => updateLine(line.client_id, patch)}
                onRemove={() => removeLine(line.client_id)}
                supplierOptions={supplierOptions}
                suppliersLoading={suppliersLoading}
                expenseAccountOptions={expenseAccountOptions}
                expenseAccountsLoading={expenseAccountsLoading}
                branchOptions={branchOptions}
                branchesLoading={branchesLoading}
              />
            ))}
          </TableBody>
        </Table>
      </div>

      <Button type="button" variant="outline" size="sm" className="self-start" onClick={() => onChange([...lines, emptyLine()])}>
        <Plus className="size-4" />
        Add Line
      </Button>
    </div>
  )
}

interface MixedVoucherLineRowProps {
  line: MixedVoucherLineDraft
  onChange: (patch: Partial<MixedVoucherLineDraft>) => void
  onRemove: () => void
  supplierOptions: Option[]
  suppliersLoading: boolean
  expenseAccountOptions: Option[]
  expenseAccountsLoading: boolean
  branchOptions: Option[]
  branchesLoading: boolean
}

function MixedVoucherLineRow({
  line,
  onChange,
  onRemove,
  supplierOptions,
  suppliersLoading,
  expenseAccountOptions,
  expenseAccountsLoading,
  branchOptions,
  branchesLoading,
}: MixedVoucherLineRowProps) {
  const isSupplier = line.type === 'supplier'

  return (
    <TableRow>
      <TableCell className="align-top">
        <Select
          value={line.type}
          onValueChange={(next) =>
            onChange({
              type: next as 'supplier' | 'expense',
              accounts_payable_id: '',
              accounts_payable_reference: null,
              accounts_payable_outstanding: null,
              expense_account_id: '',
              description: '',
              amount: '',
            })
          }
        >
          <SelectTrigger className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="supplier">Supplier Bill</SelectItem>
            <SelectItem value="expense">General Expense</SelectItem>
          </SelectContent>
        </Select>
      </TableCell>

      <TableCell className="align-top">
        {isSupplier ? (
          <SupplierBillPicker
            line={line}
            onChange={onChange}
            supplierOptions={supplierOptions}
            suppliersLoading={suppliersLoading}
          />
        ) : (
          <div className="flex flex-col gap-2">
            <SearchableSelect
              options={expenseAccountOptions}
              value={line.expense_account_id}
              onChange={(value) => onChange({ expense_account_id: value ?? '' })}
              loading={expenseAccountsLoading}
              placeholder="Select category"
              aria-label="Category"
            />
            <Input
              placeholder="Description"
              value={line.description}
              onChange={(e) => onChange({ description: e.target.value })}
            />
          </div>
        )}
      </TableCell>

      <TableCell className="align-top">
        <SearchableSelect
          options={branchOptions}
          value={line.branch_id}
          onChange={(value) => onChange({ branch_id: value ?? '' })}
          loading={branchesLoading}
          placeholder="Optional"
          aria-label="Branch"
        />
      </TableCell>

      <TableCell className="align-top">
        <RupiahInput value={line.amount} onChange={(v) => onChange({ amount: v })} className="text-right" aria-label="Amount" />
        {isSupplier && line.accounts_payable_outstanding != null && (
          <p className="mt-1 text-right text-xs text-muted-foreground">Outstanding: {formatCurrency(line.accounts_payable_outstanding)}</p>
        )}
      </TableCell>

      <TableCell className="align-top">
        <Input placeholder="Optional" value={line.notes} onChange={(e) => onChange({ notes: e.target.value })} />
      </TableCell>

      <TableCell className="align-top">
        <Button type="button" variant="ghost" size="icon" onClick={onRemove} aria-label="Remove line">
          <Trash2 className="size-4 text-destructive" />
        </Button>
      </TableCell>
    </TableRow>
  )
}

/** Its own row-scoped Supplier -> Purchase Invoice cascade, independent of every other row's — a
    plain useState here (not part of MixedVoucherLineDraft) since which supplier is "currently
    being browsed" for this row is transient UI state, not something the voucher itself needs
    once a specific bill is picked. */
function SupplierBillPicker({
  line,
  onChange,
  supplierOptions,
  suppliersLoading,
}: {
  line: MixedVoucherLineDraft
  onChange: (patch: Partial<MixedVoucherLineDraft>) => void
  supplierOptions: Option[]
  suppliersLoading: boolean
}) {
  const supplierId = line.accounts_payable_supplier_id ?? ''

  const outstandingQuery = useQuery({
    queryKey: ['accounts-payables', supplierId],
    queryFn: () => fetchAccountsPayables({ supplier_id: supplierId, per_page: 100 }),
    enabled: !!supplierId,
  })

  const billOptions: Option[] =
    outstandingQuery.data?.data
      .filter((ap) => ap.status !== 'paid')
      .map((ap) => ({
        value: ap.id,
        label: `${ap.reference_number} — Outstanding ${formatCurrency(ap.outstanding_amount)}`,
      })) ?? []

  return (
    <div className="flex flex-col gap-2">
      <SearchableSelect
        options={supplierOptions}
        value={supplierId}
        onChange={(nextSupplierId) =>
          onChange({
            accounts_payable_supplier_id: nextSupplierId ?? '',
            accounts_payable_id: '',
            accounts_payable_reference: null,
            accounts_payable_outstanding: null,
          })
        }
        loading={suppliersLoading}
        placeholder="Select supplier"
        aria-label="Supplier"
      />
      <SearchableSelect
        options={billOptions}
        value={line.accounts_payable_id}
        onChange={(nextApId) => {
          const ap = outstandingQuery.data?.data.find((row) => row.id === nextApId)
          onChange({
            accounts_payable_id: nextApId ?? '',
            accounts_payable_reference: ap?.reference_number ?? null,
            accounts_payable_outstanding: ap ? Number(ap.outstanding_amount) : null,
            amount: ap && !line.amount ? String(ap.outstanding_amount) : line.amount,
          })
        }}
        loading={outstandingQuery.isLoading}
        disabled={!supplierId}
        placeholder={supplierId ? 'Select bill' : 'Pick a supplier first'}
        aria-label="Purchase Invoice"
      />
    </div>
  )
}
