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
        <Route path="/master/items" element={<ProtectedRoute permission="item.view"><ItemListPage /></ProtectedRoute>} />
        <Route path="/master/suppliers" element={<ProtectedRoute permission="supplier.view"><SupplierListPage /></ProtectedRoute>} />
        <Route path="/master/customers" element={<ProtectedRoute permission="customer.view"><CustomerListPage /></ProtectedRoute>} />
        <Route path="/master/warehouses" element={<ProtectedRoute permission="warehouse.view"><WarehouseListPage /></ProtectedRoute>} />
        <Route path="/master/item-groups" element={<ProtectedRoute permission="item_group.view"><ItemGroupListPage /></ProtectedRoute>} />
        <Route path="/master/uoms" element={<ProtectedRoute permission="uom.view"><UomListPage /></ProtectedRoute>} />
        <Route path="/master/chart-of-accounts" element={<ProtectedRoute permission="chart_of_account.view"><ChartOfAccountsPage /></ProtectedRoute>} />
        <Route path="/master/taxes" element={<ProtectedRoute permission="tax.view"><TaxListPage /></ProtectedRoute>} />
        <Route path="/purchase/orders" element={<ProtectedRoute permission="purchase_order.view"><PurchaseOrderListPage /></ProtectedRoute>} />
        <Route path="/purchase/orders/new" element={<ProtectedRoute permission="purchase_order.view"><PurchaseOrderEditorPage /></ProtectedRoute>} />
        <Route path="/purchase/orders/:id/edit" element={<ProtectedRoute permission="purchase_order.view"><PurchaseOrderEditorPage /></ProtectedRoute>} />
        <Route path="/purchase/orders/:id" element={<ProtectedRoute permission="purchase_order.view"><PurchaseOrderDetailPage /></ProtectedRoute>} />
        <Route path="/purchase/goods-receipts" element={<ProtectedRoute permission="goods_receipt.view"><GoodsReceiptListPage /></ProtectedRoute>} />
        <Route path="/purchase/goods-receipts/new" element={<ProtectedRoute permission="goods_receipt.view"><GoodsReceiptEditorPage /></ProtectedRoute>} />
        <Route path="/purchase/goods-receipts/:id/edit" element={<ProtectedRoute permission="goods_receipt.view"><GoodsReceiptEditorPage /></ProtectedRoute>} />
        <Route path="/purchase/goods-receipts/:id" element={<ProtectedRoute permission="goods_receipt.view"><GoodsReceiptDetailPage /></ProtectedRoute>} />
        <Route path="/sales/orders" element={<ProtectedRoute permission="sales_order.view"><SalesOrderListPage /></ProtectedRoute>} />
        <Route path="/sales/orders/new" element={<ProtectedRoute permission="sales_order.view"><SalesOrderEditorPage /></ProtectedRoute>} />
        <Route path="/sales/orders/:id/edit" element={<ProtectedRoute permission="sales_order.view"><SalesOrderEditorPage /></ProtectedRoute>} />
        <Route path="/sales/orders/:id" element={<ProtectedRoute permission="sales_order.view"><SalesOrderDetailPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries" element={<ProtectedRoute permission="delivery.view"><DeliveryListPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries/new" element={<ProtectedRoute permission="delivery.view"><DeliveryEditorPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries/:id/edit" element={<ProtectedRoute permission="delivery.view"><DeliveryEditorPage /></ProtectedRoute>} />
        <Route path="/sales/deliveries/:id" element={<ProtectedRoute permission="delivery.view"><DeliveryDetailPage /></ProtectedRoute>} />
        <Route path="/sales/invoices" element={<ProtectedRoute permission="invoice.view"><InvoiceListPage /></ProtectedRoute>} />
        <Route path="/sales/invoices/new" element={<ProtectedRoute permission="invoice.view"><InvoiceEditorPage /></ProtectedRoute>} />
        <Route path="/sales/invoices/:id/edit" element={<ProtectedRoute permission="invoice.view"><InvoiceEditorPage /></ProtectedRoute>} />
        <Route path="/sales/invoices/:id" element={<ProtectedRoute permission="invoice.view"><InvoiceDetailPage /></ProtectedRoute>} />
        <Route path="/sales/invoices/:id/print" element={<ProtectedRoute permission="invoice.view"><InvoicePrintPage /></ProtectedRoute>} />
        <Route path="/sales/credit-notes" element={<ProtectedRoute permission="credit_note.view"><CreditNoteListPage /></ProtectedRoute>} />
        <Route path="/sales/credit-notes/new" element={<ProtectedRoute permission="credit_note.view"><CreditNoteEditorPage /></ProtectedRoute>} />
        <Route path="/sales/credit-notes/:id/edit" element={<ProtectedRoute permission="credit_note.view"><CreditNoteEditorPage /></ProtectedRoute>} />
        <Route path="/sales/credit-notes/:id" element={<ProtectedRoute permission="credit_note.view"><CreditNoteDetailPage /></ProtectedRoute>} />
        <Route path="/sales/debit-notes" element={<ProtectedRoute permission="debit_note.view"><DebitNoteListPage /></ProtectedRoute>} />
        <Route path="/sales/debit-notes/new" element={<ProtectedRoute permission="debit_note.view"><DebitNoteEditorPage /></ProtectedRoute>} />
        <Route path="/sales/debit-notes/:id/edit" element={<ProtectedRoute permission="debit_note.view"><DebitNoteEditorPage /></ProtectedRoute>} />
        <Route path="/sales/debit-notes/:id" element={<ProtectedRoute permission="debit_note.view"><DebitNoteDetailPage /></ProtectedRoute>} />
        <Route path="/inventory/stock-balance" element={<ProtectedRoute permission="stock.view"><StockBalanceListPage /></ProtectedRoute>} />
        <Route path="/inventory/stock-ledger" element={<ProtectedRoute permission="stock.view"><StockLedgerListPage /></ProtectedRoute>} />
        <Route path="/inventory/adjustments" element={<ProtectedRoute permission="stock.view"><StockAdjustmentListPage /></ProtectedRoute>} />
        <Route path="/inventory/adjustments/new" element={<ProtectedRoute permission="stock.view"><StockAdjustmentEditorPage /></ProtectedRoute>} />
        <Route path="/inventory/adjustments/:id/edit" element={<ProtectedRoute permission="stock.view"><StockAdjustmentEditorPage /></ProtectedRoute>} />
        <Route path="/inventory/adjustments/:id" element={<ProtectedRoute permission="stock.view"><StockAdjustmentDetailPage /></ProtectedRoute>} />
        <Route path="/reports/purchase" element={<ProtectedRoute permission="purchase_order.view"><PurchaseReportPage /></ProtectedRoute>} />
        <Route path="/reports/goods-receipts" element={<ProtectedRoute permission="goods_receipt.view"><GoodsReceiptReportPage /></ProtectedRoute>} />
        <Route path="/reports/sales" element={<ProtectedRoute permission="sales_order.view"><SalesReportPage /></ProtectedRoute>} />
        <Route path="/reports/deliveries" element={<ProtectedRoute permission="delivery.view"><DeliveryReportPage /></ProtectedRoute>} />
        <Route path="/reports/inventory-movement" element={<ProtectedRoute permission="stock.view"><InventoryMovementReportPage /></ProtectedRoute>} />
        <Route path="/reports/inventory-balance" element={<ProtectedRoute permission="stock.view"><InventoryBalanceReportPage /></ProtectedRoute>} />
        <Route path="/finance/incoming" element={<ProtectedRoute permission="receipt_entry.view"><IncomingPaymentListPage /></ProtectedRoute>} />
        <Route path="/finance/incoming/new" element={<ProtectedRoute permission="receipt_entry.view"><IncomingPaymentEditorPage /></ProtectedRoute>} />
        <Route path="/finance/incoming/:id/edit" element={<ProtectedRoute permission="receipt_entry.view"><IncomingPaymentEditorPage /></ProtectedRoute>} />
        <Route path="/finance/incoming/:id" element={<ProtectedRoute permission="receipt_entry.view"><IncomingPaymentDetailPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing" element={<ProtectedRoute permission="payment_entry.view"><OutgoingPaymentListPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing/new" element={<ProtectedRoute permission="payment_entry.view"><OutgoingPaymentEditorPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing/:id/edit" element={<ProtectedRoute permission="payment_entry.view"><OutgoingPaymentEditorPage /></ProtectedRoute>} />
        <Route path="/finance/outgoing/:id" element={<ProtectedRoute permission="payment_entry.view"><OutgoingPaymentDetailPage /></ProtectedRoute>} />
        <Route path="/accounting/journal-entries" element={<ProtectedRoute permission="journal_entry.view"><JournalEntryListPage /></ProtectedRoute>} />
        <Route path="/accounting/journal-entries/new" element={<ProtectedRoute permission="journal_entry.view"><JournalEntryEditorPage /></ProtectedRoute>} />
        <Route path="/accounting/journal-entries/:id/edit" element={<ProtectedRoute permission="journal_entry.view"><JournalEntryEditorPage /></ProtectedRoute>} />
        <Route path="/accounting/journal-entries/:id" element={<ProtectedRoute permission="journal_entry.view"><JournalEntryDetailPage /></ProtectedRoute>} />
        <Route path="/accounting/general-ledger" element={<ProtectedRoute permission="journal_entry.view"><GeneralLedgerListPage /></ProtectedRoute>} />
        <Route path="/accounting/general-ledger/:accountId" element={<ProtectedRoute permission="journal_entry.view"><GeneralLedgerDetailPage /></ProtectedRoute>} />
        <Route path="/accounting/trial-balance" element={<ProtectedRoute permission="journal_entry.view"><TrialBalanceListPage /></ProtectedRoute>} />
        <Route path="/accounting/profit-loss" element={<ProtectedRoute permission="journal_entry.view"><ProfitLossListPage /></ProtectedRoute>} />
        <Route path="/accounting/balance-sheet" element={<ProtectedRoute permission="journal_entry.view"><BalanceSheetListPage /></ProtectedRoute>} />
        <Route path="/accounting/cash-flow" element={<ProtectedRoute permission="journal_entry.view"><CashFlowListPage /></ProtectedRoute>} />
        <Route path="/accounting/period-closing" element={<ProtectedRoute permission="journal_entry.view"><PeriodManagementPage /></ProtectedRoute>} />
        <Route path="/administration/company" element={<ProtectedRoute permission="company.view"><CompanyPage /></ProtectedRoute>} />
        <Route path="/administration/users" element={<ProtectedRoute permission="user.view"><UserListPage /></ProtectedRoute>} />
        <Route path="/administration/roles" element={<ProtectedRoute permission="role.view"><RoleListPage /></ProtectedRoute>} />
        <Route path="/administration/audit-log" element={<ProtectedRoute permission="audit_log.view"><AuditLogListPage /></ProtectedRoute>} />
      </Route>

      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}
