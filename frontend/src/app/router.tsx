import { Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/layouts/AppLayout'
import { LoginPage } from '@/features/auth/pages/LoginPage'
import { DashboardPage } from '@/features/dashboard/pages/DashboardPage'
import { ItemListPage } from '@/features/master/pages/ItemListPage'
import { SupplierListPage } from '@/features/master/pages/SupplierListPage'
import { CustomerListPage } from '@/features/master/pages/CustomerListPage'
import { SalesPersonListPage } from '@/features/master/pages/SalesPersonListPage'
import { TermsOfPaymentListPage } from '@/features/master/pages/TermsOfPaymentListPage'
import { WarehouseListPage } from '@/features/master/pages/WarehouseListPage'
import { ItemGroupListPage } from '@/features/master/pages/ItemGroupListPage'
import { UomListPage } from '@/features/master/pages/UomListPage'
import { ChartOfAccountsPage } from '@/features/master/pages/ChartOfAccountsPage'
import { TaxListPage } from '@/features/master/pages/TaxListPage'
import { MiscellaneousItemListPage } from '@/features/master/pages/MiscellaneousItemListPage'
import { PurchaseOrderListPage } from '@/features/purchase/pages/PurchaseOrderListPage'
import { PurchaseOrderEditorPage } from '@/features/purchase/pages/PurchaseOrderEditorPage'
import { PurchaseOrderDetailPage } from '@/features/purchase/pages/PurchaseOrderDetailPage'
import { GoodsReceiptListPage } from '@/features/purchase/pages/GoodsReceiptListPage'
import { GoodsReceiptEditorPage } from '@/features/purchase/pages/GoodsReceiptEditorPage'
import { GoodsReceiptDetailPage } from '@/features/purchase/pages/GoodsReceiptDetailPage'
import { SalesOrderListPage } from '@/features/sales/pages/SalesOrderListPage'
import { SalesOrderEditorPage } from '@/features/sales/pages/SalesOrderEditorPage'
import { SalesOrderDetailPage } from '@/features/sales/pages/SalesOrderDetailPage'
import { SalesOrderPrintPage } from '@/features/sales/pages/SalesOrderPrintPage'
import { DeliveryListPage } from '@/features/sales/pages/DeliveryListPage'
import { DeliveryEditorPage } from '@/features/sales/pages/DeliveryEditorPage'
import { DeliveryDetailPage } from '@/features/sales/pages/DeliveryDetailPage'
import { DeliveryPrintPage } from '@/features/sales/pages/DeliveryPrintPage'
import { InvoiceListPage } from '@/features/sales/pages/InvoiceListPage'
import { InvoiceEditorPage } from '@/features/sales/pages/InvoiceEditorPage'
import { InvoiceDetailPage } from '@/features/sales/pages/InvoiceDetailPage'
import { InvoicePrintPage } from '@/features/sales/pages/InvoicePrintPage'
import { TandaTerimaInvoicePrintPage } from '@/features/sales/pages/TandaTerimaInvoicePrintPage'
import { LaporanPenagihanHarianPrintPage } from '@/features/sales/pages/LaporanPenagihanHarianPrintPage'
import { CreditNoteListPage } from '@/features/sales/pages/CreditNoteListPage'
import { CreditNoteEditorPage } from '@/features/sales/pages/CreditNoteEditorPage'
import { CreditNoteDetailPage } from '@/features/sales/pages/CreditNoteDetailPage'
import { DebitNoteListPage } from '@/features/sales/pages/DebitNoteListPage'
import { DebitNoteEditorPage } from '@/features/sales/pages/DebitNoteEditorPage'
import { DebitNoteDetailPage } from '@/features/sales/pages/DebitNoteDetailPage'
import { StockBalanceListPage } from '@/features/inventory/pages/StockBalanceListPage'
import { StockLedgerListPage } from '@/features/inventory/pages/StockLedgerListPage'
import { StockAdjustmentListPage } from '@/features/inventory/pages/StockAdjustmentListPage'
import { StockAdjustmentEditorPage } from '@/features/inventory/pages/StockAdjustmentEditorPage'
import { StockAdjustmentDetailPage } from '@/features/inventory/pages/StockAdjustmentDetailPage'
import { StockTransferListPage } from '@/features/inventory/pages/StockTransferListPage'
import { StockTransferEditorPage } from '@/features/inventory/pages/StockTransferEditorPage'
import { StockTransferDetailPage } from '@/features/inventory/pages/StockTransferDetailPage'
import { PurchaseReportPage } from '@/features/reports/pages/PurchaseReportPage'
import { GoodsReceiptReportPage } from '@/features/reports/pages/GoodsReceiptReportPage'
import { SalesReportPage } from '@/features/reports/pages/SalesReportPage'
import { SalesReportPrintPage } from '@/features/reports/pages/SalesReportPrintPage'
import { DeliveryReportPage } from '@/features/reports/pages/DeliveryReportPage'
import { DeliveryReportPrintPage } from '@/features/reports/pages/DeliveryReportPrintPage'
import { InventoryMovementReportPage } from '@/features/reports/pages/InventoryMovementReportPage'
import { InventoryBalanceReportPage } from '@/features/reports/pages/InventoryBalanceReportPage'
import { AccountsReceivableDetailReportPage } from '@/features/reports/pages/AccountsReceivableDetailReportPage'
import { AccountsReceivableDetailReportPrintPage } from '@/features/reports/pages/AccountsReceivableDetailReportPrintPage'
import { IncomingPaymentListPage } from '@/features/payment/pages/IncomingPaymentListPage'
import { IncomingPaymentEditorPage } from '@/features/payment/pages/IncomingPaymentEditorPage'
import { IncomingPaymentDetailPage } from '@/features/payment/pages/IncomingPaymentDetailPage'
import { IncomingPaymentPrintPage } from '@/features/payment/pages/IncomingPaymentPrintPage'
import { OutgoingPaymentListPage } from '@/features/payment/pages/OutgoingPaymentListPage'
import { OutgoingPaymentEditorPage } from '@/features/payment/pages/OutgoingPaymentEditorPage'
import { OutgoingPaymentDetailPage } from '@/features/payment/pages/OutgoingPaymentDetailPage'
import { OutgoingPaymentPrintPage } from '@/features/payment/pages/OutgoingPaymentPrintPage'
import { JournalEntryListPage } from '@/features/accounting/pages/JournalEntryListPage'
import { JournalEntryEditorPage } from '@/features/accounting/pages/JournalEntryEditorPage'
import { JournalEntryDetailPage } from '@/features/accounting/pages/JournalEntryDetailPage'
import { JournalListListPage } from '@/features/accounting/pages/JournalListListPage'
import { JournalListPrintPage } from '@/features/accounting/pages/JournalListPrintPage'
import { GeneralLedgerListPage } from '@/features/accounting/pages/GeneralLedgerListPage'
import { GeneralLedgerDetailPage } from '@/features/accounting/pages/GeneralLedgerDetailPage'
import { TrialBalanceListPage } from '@/features/accounting/pages/TrialBalanceListPage'
import { ProfitLossListPage } from '@/features/accounting/pages/ProfitLossListPage'
import { BalanceSheetListPage } from '@/features/accounting/pages/BalanceSheetListPage'
import { CashFlowListPage } from '@/features/accounting/pages/CashFlowListPage'
import { PeriodManagementPage } from '@/features/accounting/pages/PeriodManagementPage'
import { CompanyPage } from '@/features/administration/pages/CompanyPage'
import { BranchListPage } from '@/features/administration/pages/BranchListPage'
import { UserListPage } from '@/features/administration/pages/UserListPage'
import { RoleListPage } from '@/features/administration/pages/RoleListPage'
import { AuditLogListPage } from '@/features/administration/pages/AuditLogListPage'
import { NamingSeriesListPage } from '@/features/administration/pages/NamingSeriesListPage'
import { ProtectedRoute } from './ProtectedRoute'

export function AppRouter() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      <Route
        element={
          <ProtectedRoute>
            <AppLayout />
          </ProtectedRoute>
        }
      >
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
        <Route path="/dashboard" element={<DashboardPage />} />
        <Route path="/master/items" element={<ProtectedRoute permission="master.items.view"><ItemListPage /></ProtectedRoute>} />
        <Route path="/master/suppliers" element={<ProtectedRoute permission="master.suppliers.view"><SupplierListPage /></ProtectedRoute>} />
        <Route path="/master/customers" element={<ProtectedRoute permission="master.customers.view"><CustomerListPage /></ProtectedRoute>} />
        <Route path="/master/sales-persons" element={<ProtectedRoute permission="master.sales_persons.view"><SalesPersonListPage /></ProtectedRoute>} />
        <Route path="/master/terms-of-payment" element={<ProtectedRoute permission="master.terms_of_payment.view"><TermsOfPaymentListPage /></ProtectedRoute>} />
        <Route path="/master/warehouses" element={<ProtectedRoute permission="master.warehouses.view"><WarehouseListPage /></ProtectedRoute>} />
        <Route path="/master/item-groups" element={<ProtectedRoute permission="master.item_groups.view"><ItemGroupListPage /></ProtectedRoute>} />
        <Route path="/master/uoms" element={<ProtectedRoute permission="master.uoms.view"><UomListPage /></ProtectedRoute>} />
        <Route path="/master/taxes" element={<ProtectedRoute permission="master.taxes.view"><TaxListPage /></ProtectedRoute>} />
        <Route path="/master/miscellaneous" element={<ProtectedRoute permission="master.miscellaneous.view"><MiscellaneousItemListPage /></ProtectedRoute>} />
        <Route path="/master/chart-of-accounts" element={<Navigate to="/finance/chart-of-accounts" replace />} />
        <Route path="/purchase/orders" element={<ProtectedRoute permission="purchase.orders.view"><PurchaseOrderListPage /></ProtectedRoute>} />
        <Route path="/purchase/orders/new" element={<ProtectedRoute permission="purchase.orders.view"><PurchaseOrderEditorPage /></ProtectedRoute>} />
        <Route path="/purchase/orders/:id/edit" element={<ProtectedRoute permission="purchase.orders.view"><PurchaseOrderEditorPage /></ProtectedRoute>} />
        <Route path="/purchase/orders/:id" element={<ProtectedRoute permission="purchase.orders.view"><PurchaseOrderDetailPage /></ProtectedRoute>} />
        <Route path="/purchase/goods-receipts" element={<ProtectedRoute permission="purchase.goods_receipts.view"><GoodsReceiptListPage /></ProtectedRoute>} />
        <Route path="/purchase/goods-receipts/new" element={<ProtectedRoute permission="purchase.goods_receipts.view"><GoodsReceiptEditorPage /></ProtectedRoute>} />
        <Route path="/purchase/goods-receipts/:id/edit" element={<ProtectedRoute permission="purchase.goods_receipts.view"><GoodsReceiptEditorPage /></ProtectedRoute>} />
        <Route path="/purchase/goods-receipts/:id" element={<ProtectedRoute permission="purchase.goods_receipts.view"><GoodsReceiptDetailPage /></ProtectedRoute>} />
        <Route path="/sales/orders" element={<ProtectedRoute permission="sales.orders.view"><SalesOrderListPage /></ProtectedRoute>} />
        <Route path="/sales/orders/outstanding" element={<ProtectedRoute permission="sales.orders.view"><SalesOrderListPage /></ProtectedRoute>} />
        <Route path="/sales/orders/new" element={<ProtectedRoute permission="sales.orders.view"><SalesOrderEditorPage /></ProtectedRoute>} />
        <Route path="/sales/orders/:id/edit" element={<ProtectedRoute permission="sales.orders.view"><SalesOrderEditorPage /></ProtectedRoute>} />
        <Route path="/sales/orders/:id" element={<ProtectedRoute permission="sales.orders.view"><SalesOrderDetailPage /></ProtectedRoute>} />
        <Route path="/sales/orders/:id/print" element={<ProtectedRoute permission="sales.orders.view"><SalesOrderPrintPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries" element={<ProtectedRoute permission="sales.deliveries.view"><DeliveryListPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries/outstanding" element={<ProtectedRoute permission="sales.deliveries.view"><DeliveryListPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries/new" element={<ProtectedRoute permission="sales.deliveries.view"><DeliveryEditorPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries/:id/edit" element={<ProtectedRoute permission="sales.deliveries.view"><DeliveryEditorPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries/:id" element={<ProtectedRoute permission="sales.deliveries.view"><DeliveryDetailPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries/:id/print" element={<ProtectedRoute permission="sales.deliveries.view"><DeliveryPrintPage /></ProtectedRoute>} />
        <Route path="/sales/invoices" element={<ProtectedRoute permission="sales.invoices.view"><InvoiceListPage /></ProtectedRoute>} />
        <Route path="/sales/invoices/new" element={<ProtectedRoute permission="sales.invoices.view"><InvoiceEditorPage /></ProtectedRoute>} />
        <Route path="/sales/invoices/:id/edit" element={<ProtectedRoute permission="sales.invoices.view"><InvoiceEditorPage /></ProtectedRoute>} />
        <Route path="/sales/invoices/:id" element={<ProtectedRoute permission="sales.invoices.view"><InvoiceDetailPage /></ProtectedRoute>} />
        <Route path="/sales/invoices/:id/print" element={<ProtectedRoute permission="sales.invoices.view"><InvoicePrintPage /></ProtectedRoute>} />
        <Route
          path="/sales/invoices/print/tanda-terima-invoice"
          element={
            <ProtectedRoute permission="sales.invoices.view">
              <TandaTerimaInvoicePrintPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/sales/invoices/print/penagihan-harian"
          element={
            <ProtectedRoute permission="sales.invoices.view">
              <LaporanPenagihanHarianPrintPage />
            </ProtectedRoute>
          }
        />
        <Route path="/sales/credit-notes" element={<ProtectedRoute permission="sales.credit_notes.view"><CreditNoteListPage /></ProtectedRoute>} />
        <Route path="/sales/credit-notes/new" element={<ProtectedRoute permission="sales.credit_notes.view"><CreditNoteEditorPage /></ProtectedRoute>} />
        <Route path="/sales/credit-notes/:id/edit" element={<ProtectedRoute permission="sales.credit_notes.view"><CreditNoteEditorPage /></ProtectedRoute>} />
        <Route path="/sales/credit-notes/:id" element={<ProtectedRoute permission="sales.credit_notes.view"><CreditNoteDetailPage /></ProtectedRoute>} />
        <Route path="/sales/debit-notes" element={<ProtectedRoute permission="sales.debit_notes.view"><DebitNoteListPage /></ProtectedRoute>} />
        <Route path="/sales/debit-notes/new" element={<ProtectedRoute permission="sales.debit_notes.view"><DebitNoteEditorPage /></ProtectedRoute>} />
        <Route path="/sales/debit-notes/:id/edit" element={<ProtectedRoute permission="sales.debit_notes.view"><DebitNoteEditorPage /></ProtectedRoute>} />
        <Route path="/sales/debit-notes/:id" element={<ProtectedRoute permission="sales.debit_notes.view"><DebitNoteDetailPage /></ProtectedRoute>} />
        <Route path="/inventory/stock-balance" element={<ProtectedRoute permission="inventory.stock_balance.view"><StockBalanceListPage /></ProtectedRoute>} />
        <Route path="/inventory/stock-ledger" element={<ProtectedRoute permission="inventory.stock_ledger.view"><StockLedgerListPage /></ProtectedRoute>} />
        <Route path="/inventory/adjustments" element={<ProtectedRoute permission="inventory.adjustments.view"><StockAdjustmentListPage /></ProtectedRoute>} />
        <Route path="/inventory/adjustments/new" element={<ProtectedRoute permission="inventory.adjustments.view"><StockAdjustmentEditorPage /></ProtectedRoute>} />
        <Route path="/inventory/adjustments/:id/edit" element={<ProtectedRoute permission="inventory.adjustments.view"><StockAdjustmentEditorPage /></ProtectedRoute>} />
        <Route path="/inventory/adjustments/:id" element={<ProtectedRoute permission="inventory.adjustments.view"><StockAdjustmentDetailPage /></ProtectedRoute>} />
        <Route path="/inventory/transfers" element={<ProtectedRoute permission="inventory.transfers.view"><StockTransferListPage /></ProtectedRoute>} />
        <Route path="/inventory/transfers/new" element={<ProtectedRoute permission="inventory.transfers.view"><StockTransferEditorPage /></ProtectedRoute>} />
        <Route path="/inventory/transfers/:id/edit" element={<ProtectedRoute permission="inventory.transfers.view"><StockTransferEditorPage /></ProtectedRoute>} />
        <Route path="/inventory/transfers/:id" element={<ProtectedRoute permission="inventory.transfers.view"><StockTransferDetailPage /></ProtectedRoute>} />
        <Route path="/reports/purchase" element={<ProtectedRoute permission="reports.purchase.view"><PurchaseReportPage /></ProtectedRoute>} />
        <Route path="/reports/goods-receipts" element={<ProtectedRoute permission="reports.goods_receipts.view"><GoodsReceiptReportPage /></ProtectedRoute>} />
        <Route path="/reports/sales" element={<ProtectedRoute permission="reports.sales.view"><SalesReportPage /></ProtectedRoute>} />
        <Route path="/reports/sales/print" element={<ProtectedRoute permission="reports.sales.view"><SalesReportPrintPage /></ProtectedRoute>} />
        <Route path="/reports/deliveries" element={<ProtectedRoute permission="reports.deliveries.view"><DeliveryReportPage /></ProtectedRoute>} />
        <Route path="/reports/deliveries/print" element={<ProtectedRoute permission="reports.deliveries.view"><DeliveryReportPrintPage /></ProtectedRoute>} />
        <Route path="/reports/inventory-movement" element={<ProtectedRoute permission="reports.inventory_movement.view"><InventoryMovementReportPage /></ProtectedRoute>} />
        <Route path="/reports/inventory-balance" element={<ProtectedRoute permission="reports.inventory_balance.view"><InventoryBalanceReportPage /></ProtectedRoute>} />
        <Route path="/reports/ar-detail" element={<ProtectedRoute permission="reports.ar_detail.view"><AccountsReceivableDetailReportPage /></ProtectedRoute>} />
        <Route path="/reports/ar-detail/print" element={<ProtectedRoute permission="reports.ar_detail.view"><AccountsReceivableDetailReportPrintPage /></ProtectedRoute>} />
        <Route path="/reports/general-ledger" element={<ProtectedRoute permission="accounting.general_ledger.view"><GeneralLedgerListPage /></ProtectedRoute>} />
        <Route path="/reports/general-ledger/:accountId" element={<ProtectedRoute permission="accounting.general_ledger.view"><GeneralLedgerDetailPage /></ProtectedRoute>} />
        <Route path="/reports/general-ledger/journal-list" element={<ProtectedRoute permission="accounting.journal_list.view"><JournalListListPage /></ProtectedRoute>} />
        <Route path="/reports/general-ledger/journal-list/print" element={<ProtectedRoute permission="accounting.journal_list.view"><JournalListPrintPage /></ProtectedRoute>} />
        <Route path="/reports/general-ledger/trial-balance" element={<ProtectedRoute permission="accounting.trial_balance.view"><TrialBalanceListPage /></ProtectedRoute>} />
        <Route path="/reports/general-ledger/profit-loss" element={<ProtectedRoute permission="accounting.profit_loss.view"><ProfitLossListPage /></ProtectedRoute>} />
        <Route path="/reports/general-ledger/balance-sheet" element={<ProtectedRoute permission="accounting.balance_sheet.view"><BalanceSheetListPage /></ProtectedRoute>} />
        <Route path="/reports/general-ledger/cash-flow" element={<ProtectedRoute permission="accounting.cash_flow.view"><CashFlowListPage /></ProtectedRoute>} />
        <Route path="/reports/general-ledger/period-closing" element={<ProtectedRoute permission="accounting.period_closing.view"><PeriodManagementPage /></ProtectedRoute>} />
        <Route path="/finance/incoming" element={<ProtectedRoute permission="finance.incoming_payment.view"><IncomingPaymentListPage /></ProtectedRoute>} />
        <Route path="/finance/incoming/new" element={<ProtectedRoute permission="finance.incoming_payment.view"><IncomingPaymentEditorPage /></ProtectedRoute>} />
        <Route path="/finance/incoming/:id/edit" element={<ProtectedRoute permission="finance.incoming_payment.view"><IncomingPaymentEditorPage /></ProtectedRoute>} />
        <Route path="/finance/incoming/:id" element={<ProtectedRoute permission="finance.incoming_payment.view"><IncomingPaymentDetailPage /></ProtectedRoute>} />
        <Route path="/finance/incoming/:id/print" element={<ProtectedRoute permission="finance.incoming_payment.view"><IncomingPaymentPrintPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing" element={<ProtectedRoute permission="finance.outgoing_payment.view"><OutgoingPaymentListPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing/new" element={<ProtectedRoute permission="finance.outgoing_payment.view"><OutgoingPaymentEditorPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing/:id/edit" element={<ProtectedRoute permission="finance.outgoing_payment.view"><OutgoingPaymentEditorPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing/:id" element={<ProtectedRoute permission="finance.outgoing_payment.view"><OutgoingPaymentDetailPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing/:id/print" element={<ProtectedRoute permission="finance.outgoing_payment.view"><OutgoingPaymentPrintPage /></ProtectedRoute>} />
        <Route path="/finance/chart-of-accounts" element={<ProtectedRoute permission="master.chart_of_accounts.view"><ChartOfAccountsPage /></ProtectedRoute>} />
        <Route path="/finance/general-journal" element={<ProtectedRoute permission="accounting.journal_entries.view"><JournalEntryListPage /></ProtectedRoute>} />
        <Route path="/finance/general-journal/journal-entries" element={<Navigate to="/finance/general-journal" replace />} />
        <Route path="/finance/general-journal/journal-entries/new" element={<ProtectedRoute permission="accounting.journal_entries.view"><JournalEntryEditorPage /></ProtectedRoute>} />
        <Route path="/finance/general-journal/journal-entries/:id/edit" element={<ProtectedRoute permission="accounting.journal_entries.view"><JournalEntryEditorPage /></ProtectedRoute>} />
        <Route path="/finance/general-journal/journal-entries/:id" element={<ProtectedRoute permission="accounting.journal_entries.view"><JournalEntryDetailPage /></ProtectedRoute>} />
        {/* Redirects for the 7 reports that moved to Reports > General Ledger (2026-08-19) — keeps old bookmarks/links working. */}
        <Route path="/finance/general-journal/journal-list" element={<Navigate to="/reports/general-ledger/journal-list" replace />} />
        <Route path="/finance/general-journal/general-ledger" element={<Navigate to="/reports/general-ledger" replace />} />
        <Route path="/finance/general-journal/trial-balance" element={<Navigate to="/reports/general-ledger/trial-balance" replace />} />
        <Route path="/finance/general-journal/profit-loss" element={<Navigate to="/reports/general-ledger/profit-loss" replace />} />
        <Route path="/finance/general-journal/balance-sheet" element={<Navigate to="/reports/general-ledger/balance-sheet" replace />} />
        <Route path="/finance/general-journal/cash-flow" element={<Navigate to="/reports/general-ledger/cash-flow" replace />} />
        <Route path="/finance/general-journal/period-closing" element={<Navigate to="/reports/general-ledger/period-closing" replace />} />
        <Route path="/administration/company" element={<ProtectedRoute permission="administration.company.view"><CompanyPage /></ProtectedRoute>} />
        <Route path="/administration/branches" element={<ProtectedRoute permission="administration.branch.view"><BranchListPage /></ProtectedRoute>} />
        <Route path="/administration/users" element={<ProtectedRoute permission="administration.users.view"><UserListPage /></ProtectedRoute>} />
        <Route path="/administration/roles" element={<ProtectedRoute permission="administration.roles.view"><RoleListPage /></ProtectedRoute>} />
        <Route path="/administration/audit-log" element={<ProtectedRoute permission="administration.audit_log.view"><AuditLogListPage /></ProtectedRoute>} />
        <Route path="/administration/naming-series" element={<ProtectedRoute permission="administration.naming_series.view"><NamingSeriesListPage /></ProtectedRoute>} />
      </Route>

      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}
