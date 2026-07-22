import { Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/layouts/AppLayout'
import { LoginPage } from '@/features/auth/pages/LoginPage'
import { DashboardPage } from '@/features/dashboard/pages/DashboardPage'
import { ItemListPage } from '@/features/master/pages/ItemListPage'
import { SupplierListPage } from '@/features/master/pages/SupplierListPage'
import { CustomerListPage } from '@/features/master/pages/CustomerListPage'
import { WarehouseListPage } from '@/features/master/pages/WarehouseListPage'
import { ItemGroupListPage } from '@/features/master/pages/ItemGroupListPage'
import { UomListPage } from '@/features/master/pages/UomListPage'
import { ChartOfAccountsPage } from '@/features/master/pages/ChartOfAccountsPage'
import { TaxListPage } from '@/features/master/pages/TaxListPage'
import { PurchaseOrderListPage } from '@/features/purchase/pages/PurchaseOrderListPage'
import { PurchaseOrderEditorPage } from '@/features/purchase/pages/PurchaseOrderEditorPage'
import { PurchaseOrderDetailPage } from '@/features/purchase/pages/PurchaseOrderDetailPage'
import { GoodsReceiptListPage } from '@/features/purchase/pages/GoodsReceiptListPage'
import { GoodsReceiptEditorPage } from '@/features/purchase/pages/GoodsReceiptEditorPage'
import { GoodsReceiptDetailPage } from '@/features/purchase/pages/GoodsReceiptDetailPage'
import { SalesOrderListPage } from '@/features/sales/pages/SalesOrderListPage'
import { SalesOrderEditorPage } from '@/features/sales/pages/SalesOrderEditorPage'
import { SalesOrderDetailPage } from '@/features/sales/pages/SalesOrderDetailPage'
import { DeliveryListPage } from '@/features/sales/pages/DeliveryListPage'
import { DeliveryEditorPage } from '@/features/sales/pages/DeliveryEditorPage'
import { DeliveryDetailPage } from '@/features/sales/pages/DeliveryDetailPage'
import { InvoiceListPage } from '@/features/sales/pages/InvoiceListPage'
import { InvoiceEditorPage } from '@/features/sales/pages/InvoiceEditorPage'
import { InvoiceDetailPage } from '@/features/sales/pages/InvoiceDetailPage'
import { InvoicePrintPage } from '@/features/sales/pages/InvoicePrintPage'
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
import { PurchaseReportPage } from '@/features/reports/pages/PurchaseReportPage'
import { GoodsReceiptReportPage } from '@/features/reports/pages/GoodsReceiptReportPage'
import { SalesReportPage } from '@/features/reports/pages/SalesReportPage'
import { DeliveryReportPage } from '@/features/reports/pages/DeliveryReportPage'
import { InventoryMovementReportPage } from '@/features/reports/pages/InventoryMovementReportPage'
import { InventoryBalanceReportPage } from '@/features/reports/pages/InventoryBalanceReportPage'
import { IncomingPaymentListPage } from '@/features/payment/pages/IncomingPaymentListPage'
import { IncomingPaymentEditorPage } from '@/features/payment/pages/IncomingPaymentEditorPage'
import { IncomingPaymentDetailPage } from '@/features/payment/pages/IncomingPaymentDetailPage'
import { OutgoingPaymentListPage } from '@/features/payment/pages/OutgoingPaymentListPage'
import { OutgoingPaymentEditorPage } from '@/features/payment/pages/OutgoingPaymentEditorPage'
import { OutgoingPaymentDetailPage } from '@/features/payment/pages/OutgoingPaymentDetailPage'
import { JournalEntryListPage } from '@/features/accounting/pages/JournalEntryListPage'
import { JournalEntryEditorPage } from '@/features/accounting/pages/JournalEntryEditorPage'
import { JournalEntryDetailPage } from '@/features/accounting/pages/JournalEntryDetailPage'
import { GeneralLedgerListPage } from '@/features/accounting/pages/GeneralLedgerListPage'
import { GeneralLedgerDetailPage } from '@/features/accounting/pages/GeneralLedgerDetailPage'
import { TrialBalanceListPage } from '@/features/accounting/pages/TrialBalanceListPage'
import { ProfitLossListPage } from '@/features/accounting/pages/ProfitLossListPage'
import { BalanceSheetListPage } from '@/features/accounting/pages/BalanceSheetListPage'
import { CashFlowListPage } from '@/features/accounting/pages/CashFlowListPage'
import { PeriodManagementPage } from '@/features/accounting/pages/PeriodManagementPage'
import { CompanyPage } from '@/features/administration/pages/CompanyPage'
import { UserListPage } from '@/features/administration/pages/UserListPage'
import { RoleListPage } from '@/features/administration/pages/RoleListPage'
import { AuditLogListPage } from '@/features/administration/pages/AuditLogListPage'
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
        <Route path="/master/warehouses" element={<ProtectedRoute permission="master.warehouses.view"><WarehouseListPage /></ProtectedRoute>} />
        <Route path="/master/item-groups" element={<ProtectedRoute permission="master.item_groups.view"><ItemGroupListPage /></ProtectedRoute>} />
        <Route path="/master/uoms" element={<ProtectedRoute permission="master.uoms.view"><UomListPage /></ProtectedRoute>} />
        <Route path="/master/chart-of-accounts" element={<ProtectedRoute permission="master.chart_of_accounts.view"><ChartOfAccountsPage /></ProtectedRoute>} />
        <Route path="/master/taxes" element={<ProtectedRoute permission="master.taxes.view"><TaxListPage /></ProtectedRoute>} />
        <Route path="/purchase/orders" element={<ProtectedRoute permission="purchase.orders.view"><PurchaseOrderListPage /></ProtectedRoute>} />
        <Route path="/purchase/orders/new" element={<ProtectedRoute permission="purchase.orders.view"><PurchaseOrderEditorPage /></ProtectedRoute>} />
        <Route path="/purchase/orders/:id/edit" element={<ProtectedRoute permission="purchase.orders.view"><PurchaseOrderEditorPage /></ProtectedRoute>} />
        <Route path="/purchase/orders/:id" element={<ProtectedRoute permission="purchase.orders.view"><PurchaseOrderDetailPage /></ProtectedRoute>} />
        <Route path="/purchase/goods-receipts" element={<ProtectedRoute permission="purchase.goods_receipts.view"><GoodsReceiptListPage /></ProtectedRoute>} />
        <Route path="/purchase/goods-receipts/new" element={<ProtectedRoute permission="purchase.goods_receipts.view"><GoodsReceiptEditorPage /></ProtectedRoute>} />
        <Route path="/purchase/goods-receipts/:id/edit" element={<ProtectedRoute permission="purchase.goods_receipts.view"><GoodsReceiptEditorPage /></ProtectedRoute>} />
        <Route path="/purchase/goods-receipts/:id" element={<ProtectedRoute permission="purchase.goods_receipts.view"><GoodsReceiptDetailPage /></ProtectedRoute>} />
        <Route path="/sales/orders" element={<ProtectedRoute permission="sales.orders.view"><SalesOrderListPage /></ProtectedRoute>} />
        <Route path="/sales/orders/new" element={<ProtectedRoute permission="sales.orders.view"><SalesOrderEditorPage /></ProtectedRoute>} />
        <Route path="/sales/orders/:id/edit" element={<ProtectedRoute permission="sales.orders.view"><SalesOrderEditorPage /></ProtectedRoute>} />
        <Route path="/sales/orders/:id" element={<ProtectedRoute permission="sales.orders.view"><SalesOrderDetailPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries" element={<ProtectedRoute permission="sales.deliveries.view"><DeliveryListPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries/new" element={<ProtectedRoute permission="sales.deliveries.view"><DeliveryEditorPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries/:id/edit" element={<ProtectedRoute permission="sales.deliveries.view"><DeliveryEditorPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries/:id" element={<ProtectedRoute permission="sales.deliveries.view"><DeliveryDetailPage /></ProtectedRoute>} />
        <Route path="/sales/invoices" element={<ProtectedRoute permission="sales.invoices.view"><InvoiceListPage /></ProtectedRoute>} />
        <Route path="/sales/invoices/new" element={<ProtectedRoute permission="sales.invoices.view"><InvoiceEditorPage /></ProtectedRoute>} />
        <Route path="/sales/invoices/:id/edit" element={<ProtectedRoute permission="sales.invoices.view"><InvoiceEditorPage /></ProtectedRoute>} />
        <Route path="/sales/invoices/:id" element={<ProtectedRoute permission="sales.invoices.view"><InvoiceDetailPage /></ProtectedRoute>} />
        <Route path="/sales/invoices/:id/print" element={<ProtectedRoute permission="sales.invoices.view"><InvoicePrintPage /></ProtectedRoute>} />
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
        <Route path="/reports/purchase" element={<ProtectedRoute permission="reports.purchase.view"><PurchaseReportPage /></ProtectedRoute>} />
        <Route path="/reports/goods-receipts" element={<ProtectedRoute permission="reports.goods_receipts.view"><GoodsReceiptReportPage /></ProtectedRoute>} />
        <Route path="/reports/sales" element={<ProtectedRoute permission="reports.sales.view"><SalesReportPage /></ProtectedRoute>} />
        <Route path="/reports/deliveries" element={<ProtectedRoute permission="reports.deliveries.view"><DeliveryReportPage /></ProtectedRoute>} />
        <Route path="/reports/inventory-movement" element={<ProtectedRoute permission="reports.inventory_movement.view"><InventoryMovementReportPage /></ProtectedRoute>} />
        <Route path="/reports/inventory-balance" element={<ProtectedRoute permission="reports.inventory_balance.view"><InventoryBalanceReportPage /></ProtectedRoute>} />
        <Route path="/finance/incoming" element={<ProtectedRoute permission="finance.incoming_payment.view"><IncomingPaymentListPage /></ProtectedRoute>} />
        <Route path="/finance/incoming/new" element={<ProtectedRoute permission="finance.incoming_payment.view"><IncomingPaymentEditorPage /></ProtectedRoute>} />
        <Route path="/finance/incoming/:id/edit" element={<ProtectedRoute permission="finance.incoming_payment.view"><IncomingPaymentEditorPage /></ProtectedRoute>} />
        <Route path="/finance/incoming/:id" element={<ProtectedRoute permission="finance.incoming_payment.view"><IncomingPaymentDetailPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing" element={<ProtectedRoute permission="finance.outgoing_payment.view"><OutgoingPaymentListPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing/new" element={<ProtectedRoute permission="finance.outgoing_payment.view"><OutgoingPaymentEditorPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing/:id/edit" element={<ProtectedRoute permission="finance.outgoing_payment.view"><OutgoingPaymentEditorPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing/:id" element={<ProtectedRoute permission="finance.outgoing_payment.view"><OutgoingPaymentDetailPage /></ProtectedRoute>} />
        <Route path="/accounting/journal-entries" element={<ProtectedRoute permission="accounting.journal_entries.view"><JournalEntryListPage /></ProtectedRoute>} />
        <Route path="/accounting/journal-entries/new" element={<ProtectedRoute permission="accounting.journal_entries.view"><JournalEntryEditorPage /></ProtectedRoute>} />
        <Route path="/accounting/journal-entries/:id/edit" element={<ProtectedRoute permission="accounting.journal_entries.view"><JournalEntryEditorPage /></ProtectedRoute>} />
        <Route path="/accounting/journal-entries/:id" element={<ProtectedRoute permission="accounting.journal_entries.view"><JournalEntryDetailPage /></ProtectedRoute>} />
        <Route path="/accounting/general-ledger" element={<ProtectedRoute permission="accounting.general_ledger.view"><GeneralLedgerListPage /></ProtectedRoute>} />
        <Route path="/accounting/general-ledger/:accountId" element={<ProtectedRoute permission="accounting.general_ledger.view"><GeneralLedgerDetailPage /></ProtectedRoute>} />
        <Route path="/accounting/trial-balance" element={<ProtectedRoute permission="accounting.trial_balance.view"><TrialBalanceListPage /></ProtectedRoute>} />
        <Route path="/accounting/profit-loss" element={<ProtectedRoute permission="accounting.profit_loss.view"><ProfitLossListPage /></ProtectedRoute>} />
        <Route path="/accounting/balance-sheet" element={<ProtectedRoute permission="accounting.balance_sheet.view"><BalanceSheetListPage /></ProtectedRoute>} />
        <Route path="/accounting/cash-flow" element={<ProtectedRoute permission="accounting.cash_flow.view"><CashFlowListPage /></ProtectedRoute>} />
        <Route path="/accounting/period-closing" element={<ProtectedRoute permission="accounting.period_closing.view"><PeriodManagementPage /></ProtectedRoute>} />
        <Route path="/administration/company" element={<ProtectedRoute permission="administration.company.view"><CompanyPage /></ProtectedRoute>} />
        <Route path="/administration/users" element={<ProtectedRoute permission="administration.users.view"><UserListPage /></ProtectedRoute>} />
        <Route path="/administration/roles" element={<ProtectedRoute permission="administration.roles.view"><RoleListPage /></ProtectedRoute>} />
        <Route path="/administration/audit-log" element={<ProtectedRoute permission="administration.audit_log.view"><AuditLogListPage /></ProtectedRoute>} />
      </Route>

      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}
