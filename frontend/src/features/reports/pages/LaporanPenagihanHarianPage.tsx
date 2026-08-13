import { Fragment, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer, RotateCw } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { SectionNav } from '@/components/shared/SectionNav'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchBranches, fetchSalesPersonsLookup } from '@/features/master/api/lookupsApi'
import { fetchAccountsReceivablesGroupedByCustomer } from '../api/accountsReceivableCustomerReportsApi'

const ALL = '__all__'

/** F2 (UAT review 2026-08-12) — daily collection list, grouped by Customer, with an optional Branch/Sales filter and a date ceiling ("as of"). */
export function LaporanPenagihanHarianPage() {
  const navigate = useNavigate()
  const [dateTo, setDateTo] = useState('')
  const [branchId, setBranchId] = useState('')
  const [salesPersonId, setSalesPersonId] = useState('')

  const branches = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches })
  const salesPersons = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup })

  const filters = {
    ...(dateTo ? { date_to: dateTo } : {}),
    ...(branchId ? { branch_id: branchId } : {}),
    ...(salesPersonId ? { sales_person_id: salesPersonId } : {}),
  }

  const groupedQuery = useQuery({
    queryKey: ['laporan-penagihan-harian', dateTo, branchId, salesPersonId],
    queryFn: () => fetchAccountsReceivablesGroupedByCustomer(filters),
    placeholderData: (previous) => previous,
  })

  const printParams = new URLSearchParams(filters).toString()

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="Laporan Penagihan Harian"
        description="Outstanding invoices grouped by customer, for daily collection follow-up."
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => groupedQuery.refetch(), disabled: groupedQuery.isFetching },
              { label: 'Print', icon: Printer, onClick: () => navigate(`/reports/penagihan-harian/print${printParams ? `?${printParams}` : ''}`) },
            ]}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">As of Due Date</span>
          <Input type="date" className="w-40" value={dateTo} onChange={(event) => setDateTo(event.target.value)} />
        </div>
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Branch</span>
          <Select value={branchId || ALL} onValueChange={(next) => setBranchId(next === ALL ? '' : next)}>
            <SelectTrigger className="w-44">
              <SelectValue placeholder={branches.isLoading ? 'Loading…' : 'All branches'} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All branches</SelectItem>
              {branches.data?.map((branch) => (
                <SelectItem key={branch.id} value={branch.id}>
                  {branch.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Salesman</span>
          <Select value={salesPersonId || ALL} onValueChange={(next) => setSalesPersonId(next === ALL ? '' : next)}>
            <SelectTrigger className="w-44">
              <SelectValue placeholder={salesPersons.isLoading ? 'Loading…' : 'All sales persons'} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All sales persons</SelectItem>
              {salesPersons.data?.map((salesPerson) => (
                <SelectItem key={salesPerson.id} value={salesPerson.id}>
                  {salesPerson.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      {!groupedQuery.data || groupedQuery.data.groups.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            {groupedQuery.isLoading ? 'Loading…' : 'No outstanding invoices match your filters.'}
          </CardContent>
        </Card>
      ) : (
        <>
          <div className="overflow-x-auto rounded-md border">
            <Table>
              <TableBody>
                {groupedQuery.data.groups.map((group) => (
                  <Fragment key={group.customer_name}>
                    <TableRow className="bg-muted/30">
                      <TableCell colSpan={5} className="font-semibold">
                        {group.customer_code ? `${group.customer_code} — ` : ''}
                        {group.customer_name}
                      </TableCell>
                    </TableRow>
                    <TableRow>
                      <TableCell>Inv Date</TableCell>
                      <TableCell>Document #</TableCell>
                      <TableCell>Reference 1 #</TableCell>
                      <TableCell>Due Date</TableCell>
                      <TableCell className="text-right">Outstanding Amount</TableCell>
                    </TableRow>
                    {group.rows.map((row, index) => (
                      <TableRow key={index}>
                        <TableCell>{row.date ? formatDate(row.date) : '—'}</TableCell>
                        <TableCell>{row.document_no ?? '—'}</TableCell>
                        <TableCell>{row.reference_1 ?? '—'}</TableCell>
                        <TableCell>{row.due_date ? formatDate(row.due_date) : '—'}</TableCell>
                        <TableCell className="text-right">{formatCurrency(row.outstanding_amount)}</TableCell>
                      </TableRow>
                    ))}
                    <TableRow>
                      <TableCell colSpan={4} className="text-right font-medium">
                        TOTAL — {group.customer_name}
                      </TableCell>
                      <TableCell className="text-right font-medium">{formatCurrency(group.customer_subtotal)}</TableCell>
                    </TableRow>
                  </Fragment>
                ))}
              </TableBody>
            </Table>
          </div>

          <Card>
            <CardContent className="flex items-center justify-end gap-2 py-4 text-base">
              <span className="text-muted-foreground">Grand Total</span>
              <span className="font-semibold">{formatCurrency(groupedQuery.data.grand_total)}</span>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  )
}
