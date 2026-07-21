<?php

use App\Http\Controllers\Api\V1\AccountsPayableController;
use App\Http\Controllers\Api\V1\AccountsReceivableController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\ChartOfAccountController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CreditNoteController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DebitNoteController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Controllers\Api\V1\DocumentAttachmentController;
use App\Http\Controllers\Api\V1\DocumentTimelineController;
use App\Http\Controllers\Api\V1\GeneralLedgerController;
use App\Http\Controllers\Api\V1\TrialBalanceController;
use App\Http\Controllers\Api\V1\ProfitLossController;
use App\Http\Controllers\Api\V1\BalanceSheetController;
use App\Http\Controllers\Api\V1\CashFlowController;
use App\Http\Controllers\Api\V1\PeriodController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemGroupController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\NamingSeriesController;
use App\Http\Controllers\Api\V1\PaymentAllocationController;
use App\Http\Controllers\Api\V1\PaymentEntryController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\ReceiptEntryController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockInController;
use App\Http\Controllers\Api\V1\StockLedgerController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TaxController;
use App\Http\Controllers\Api\V1\UomController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Support\Facades\Route;

/**
 * Gates a resource's standard actions behind the already-seeded
 * {module}.{action} permissions (view/create/update/delete) — see
 * docs/ADMINISTRATION_DESIGN.md §5. One mechanical helper instead of
 * repeating four middlewareFor() calls per resource; does not duplicate
 * Spatie's own permission-checking logic, only wires it in.
 *
 * A local closure, not a top-level `function` — this file is re-included
 * on every fresh Application boot within the same PHP process (every
 * Feature test does this), and a global function declaration would fatal
 * with "Cannot redeclare" on the second boot.
 */
$withModulePermissions = function (PendingResourceRegistration $resource, string $module): PendingResourceRegistration {
    return $resource
        ->middlewareFor(['index', 'show'], "permission:{$module}.view")
        ->middlewareFor('store', "permission:{$module}.create")
        ->middlewareFor('update', "permission:{$module}.update")
        ->middlewareFor('destroy', "permission:{$module}.delete");
};

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () use ($withModulePermissions) {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    $withModulePermissions(Route::apiResource('companies', CompanyController::class), 'company');
    // App-shell branding (name/logo only) — every authenticated user needs this regardless of
    // company.view, see docs/ADMINISTRATION_DESIGN.md. Deliberately outside $withModulePermissions.
    Route::get('company/branding', [CompanyController::class, 'branding']);
    Route::get('company/branding/logo', [CompanyController::class, 'brandingLogo']);
    $withModulePermissions(Route::apiResource('branches', BranchController::class), 'branch');
    $withModulePermissions(Route::apiResource('warehouses', WarehouseController::class), 'warehouse');

    $withModulePermissions(Route::apiResource('roles', RoleController::class), 'role');
    Route::post('roles/{role}/permissions', [RoleController::class, 'assignPermissions'])->middleware('permission:role.update');
    Route::get('permissions', [PermissionController::class, 'index']);

    // Administration — User Management (docs/ADMINISTRATION_DESIGN.md §4): the backend
    // this sprint adds, since only auth/login|logout|me existed before.
    // No destroy — deactivate (below) is the intended lifecycle action, same
    // "prefer deactivation over deletion" convention Tax already established.
    $withModulePermissions(Route::apiResource('users', UserController::class)->except('destroy'), 'user');
    Route::post('users/{user}/activate', [UserController::class, 'activate'])->middleware('permission:user.update');
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware('permission:user.update');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:user.update');
    Route::post('users/{user}/role', [UserController::class, 'assignRole'])->middleware('permission:user.update');

    // Administration — Audit Log (docs/ADMINISTRATION_DESIGN.md §6): read-only, system-wide,
    // deliberately not DocumentTimeline. Gated on the same 'audit_log' module every other
    // resource here uses, kept view-only since AuditLogController has no write actions.
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit_log.view');

    $withModulePermissions(Route::apiResource('item-groups', ItemGroupController::class), 'item_group');
    $withModulePermissions(Route::apiResource('uoms', UomController::class), 'uom');
    $withModulePermissions(Route::apiResource('currencies', CurrencyController::class), 'currency');
    $withModulePermissions(Route::apiResource('taxes', TaxController::class), 'tax');
    $withModulePermissions(Route::apiResource('customers', CustomerController::class), 'customer');
    $withModulePermissions(Route::apiResource('suppliers', SupplierController::class), 'supplier');

    $withModulePermissions(Route::apiResource('items', ItemController::class), 'item');
    Route::get('items/{item}/stock-ledger', [StockLedgerController::class, 'index'])->middleware('permission:stock.view');
    // Bulk, warehouse-scoped current balance — exposes the existing StockLedgerService::getCurrentBalance()
    // (already used internally by DeliveryService::assertSufficientStock()) for editors that need to
    // preview available stock before submitting. Additive only, no new business logic.
    Route::get('stock-ledger/balances', [StockLedgerController::class, 'balances'])->middleware('permission:stock.view');
    // Inventory module (Phase 2G): full ledger listing + a current-balance-per-item/warehouse report.
    // Both read-only, both additive, both reuse StockLedgerService — no new business logic.
    Route::get('stock-ledger', [StockLedgerController::class, 'list'])->middleware('permission:stock.view');
    Route::get('stock-ledger/balances/report', [StockLedgerController::class, 'balancesReport'])->middleware('permission:stock.view');
    Route::post('stock-in', [StockInController::class, 'store'])->middleware('permission:stock.create');

    // Document Engine — shared by every future transactional module.
    $withModulePermissions(Route::apiResource('naming-series', NamingSeriesController::class), 'naming_series');
    Route::get('attachments', [DocumentAttachmentController::class, 'index'])->middleware('permission:document_attachment.view');
    Route::post('attachments', [DocumentAttachmentController::class, 'store'])->middleware('permission:document_attachment.create');
    Route::delete('attachments/{documentAttachment}', [DocumentAttachmentController::class, 'destroy'])->middleware('permission:document_attachment.delete');
    Route::get('attachments/{documentAttachment}/download', [DocumentAttachmentController::class, 'download'])->middleware('permission:document_attachment.view');
    Route::get('document-timeline', [DocumentTimelineController::class, 'index'])->middleware('permission:document_timeline.view');

    // Purchase Workflow (Sprint 4): Supplier -> PO -> Goods Receipt -> Stock Ledger(+) -> Accounts Payable.
    $withModulePermissions(Route::apiResource('purchase-orders', PurchaseOrderController::class), 'purchase_order');
    Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchase_order.update');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase_order.update');
    // Approval Workflow (Sprint 24B) — requesting approval is an action on the document itself,
    // gated by the document's own existing update permission, not a new one. See docs/APPROVAL_WORKFLOW_DESIGN.md §4.
    Route::post('purchase-orders/{purchaseOrder}/request-approval', [PurchaseOrderController::class, 'requestApproval'])->middleware('permission:purchase_order.update');

    $withModulePermissions(Route::apiResource('goods-receipts', GoodsReceiptController::class), 'goods_receipt');
    Route::post('goods-receipts/{goodsReceipt}/submit', [GoodsReceiptController::class, 'submit'])->middleware('permission:goods_receipt.update');

    Route::get('accounts-payables', [AccountsPayableController::class, 'index'])->middleware('permission:accounts_payable.view');
    Route::get('accounts-payables/{accountsPayable}', [AccountsPayableController::class, 'show'])->middleware('permission:accounts_payable.view');

    // Sales Workflow (Sprint 5): Customer -> SO -> Delivery -> Stock Ledger(-).
    $withModulePermissions(Route::apiResource('sales-orders', SalesOrderController::class), 'sales_order');
    Route::post('sales-orders/{salesOrder}/submit', [SalesOrderController::class, 'submit'])->middleware('permission:sales_order.update');
    Route::post('sales-orders/{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])->middleware('permission:sales_order.update');
    Route::post('sales-orders/{salesOrder}/request-approval', [SalesOrderController::class, 'requestApproval'])->middleware('permission:sales_order.update');

    $withModulePermissions(Route::apiResource('deliveries', DeliveryController::class), 'delivery');
    Route::post('deliveries/{delivery}/submit', [DeliveryController::class, 'submit'])->middleware('permission:delivery.update');

    // Invoice Workflow (Sprint 10): Delivery -> Invoice -> Accounts Receivable -> Receipt Entry.
    $withModulePermissions(Route::apiResource('invoices', InvoiceController::class), 'invoice');
    Route::post('invoices/{invoice}/submit', [InvoiceController::class, 'submit'])->middleware('permission:invoice.update');
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('permission:invoice.update');

    // Credit Note (Sprint 13B): the only accounting-correction path for a posted Invoice —
    // Invoice::cancel() deliberately never touches the ledger, see InvoiceService::cancel().
    $withModulePermissions(Route::apiResource('credit-notes', CreditNoteController::class), 'credit_note');
    Route::post('credit-notes/{creditNote}/submit', [CreditNoteController::class, 'submit'])->middleware('permission:credit_note.update');
    Route::post('credit-notes/{creditNote}/reverse', [CreditNoteController::class, 'reverse'])->middleware('permission:credit_note.update');

    // Debit Note (Sprint 14B): the counterpart to Credit Note — increases a
    // customer's receivable after a posted Invoice. See docs/DEBIT_NOTE_DESIGN.md.
    $withModulePermissions(Route::apiResource('debit-notes', DebitNoteController::class), 'debit_note');
    Route::post('debit-notes/{debitNote}/submit', [DebitNoteController::class, 'submit'])->middleware('permission:debit_note.update');
    Route::post('debit-notes/{debitNote}/reverse', [DebitNoteController::class, 'reverse'])->middleware('permission:debit_note.update');

    Route::get('accounts-receivables', [AccountsReceivableController::class, 'index'])->middleware('permission:accounts_receivable.view');
    Route::get('accounts-receivables/{accountsReceivable}', [AccountsReceivableController::class, 'show'])->middleware('permission:accounts_receivable.view');

    // Inventory Module (Phase 2G): physical count reconciliation -> Stock Ledger(+/-). No cancel route,
    // deliberately — see StockAdjustment::cancel().
    $withModulePermissions(Route::apiResource('stock-adjustments', StockAdjustmentController::class), 'stock');
    Route::post('stock-adjustments/{stockAdjustment}/submit', [StockAdjustmentController::class, 'submit'])->middleware('permission:stock.update');

    // Financial Settlement (Sprint 6): AP -> Payment Entry -> paid_amount/status; AR -> Receipt Entry -> paid_amount/status.
    $withModulePermissions(Route::apiResource('payment-entries', PaymentEntryController::class), 'payment_entry');
    Route::post('payment-entries/{paymentEntry}/submit', [PaymentEntryController::class, 'submit'])->middleware('permission:payment_entry.update');

    $withModulePermissions(Route::apiResource('receipt-entries', ReceiptEntryController::class), 'receipt_entry');
    Route::post('receipt-entries/{receiptEntry}/submit', [ReceiptEntryController::class, 'submit'])->middleware('permission:receipt_entry.update');

    // Payment Allocation (Sprint 12): applies an already-received Receipt Entry to one or more
    // outstanding Invoices' receivables — a separate step from receiving the money itself.
    Route::post('receipt-entries/{receiptEntry}/allocate', [PaymentAllocationController::class, 'store'])->middleware('permission:payment_allocation.create');
    Route::post('payment-allocations/{paymentAllocation}/reverse', [PaymentAllocationController::class, 'reverse'])->middleware('permission:payment_allocation.update');

    // Accounting Engine (Sprint 11): Invoice/Receipt Entry -> Accounting Service -> Journal Entry -> General Ledger.
    $withModulePermissions(Route::apiResource('chart-of-accounts', ChartOfAccountController::class), 'chart_of_account');
    $withModulePermissions(Route::apiResource('journal-entries', JournalEntryController::class), 'journal_entry');
    Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])->middleware('permission:journal_entry.update');
    Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->middleware('permission:journal_entry.update');
    Route::post('journal-entries/{journalEntry}/request-approval', [JournalEntryController::class, 'requestApproval'])->middleware('permission:journal_entry.update');

    // Approval Workflow (Sprint 24B) — one shared engine for Sales Order, Purchase Order, and
    // manual Journal Entry (docs/APPROVAL_WORKFLOW_DESIGN.md §1). approve()/reject() serve all
    // three through this single route; the {module}.approve permission check happens inside
    // ApprovalService (module-dependent, not expressible as one static route middleware).
    Route::get('approval-flows', [ApprovalController::class, 'index']);
    Route::post('approval-flows/{approvalFlow}/approve', [ApprovalController::class, 'approve']);
    Route::post('approval-flows/{approvalFlow}/reject', [ApprovalController::class, 'reject']);

    // General Ledger (Sprint 15B): a read model derived entirely from journal_entries/
    // journal_entry_lines above — no new accounting table, never writes. See docs/GENERAL_LEDGER_DESIGN.md.
    Route::get('general-ledger/accounts', [GeneralLedgerController::class, 'accounts'])->middleware('permission:journal_entry.view');
    Route::get('general-ledger/accounts/{chartOfAccount}', [GeneralLedgerController::class, 'ledger'])->middleware('permission:journal_entry.view');

    // Trial Balance (Sprint 16A): a presentation layer over GeneralLedgerService::listAccounts()
    // — no new balance calculation, no new accounting table. See docs/TRIAL_BALANCE_DESIGN.md.
    Route::get('trial-balance', [TrialBalanceController::class, 'summary'])->middleware('permission:journal_entry.view');

    // Profit & Loss (Sprint 17B): a presentation layer over GeneralLedgerService::listAccounts(),
    // classified via the separate report_account_mappings table — chart_of_accounts gains no new
    // columns. See docs/PROFIT_LOSS_DESIGN.md.
    Route::get('profit-loss', [ProfitLossController::class, 'summary'])->middleware('permission:journal_entry.view');

    // Balance Sheet (Sprint 18B): a presentation layer over GeneralLedgerService::listAccounts()
    // (cumulative ending_balance, not period movement) and ProfitLossService::summarize() (Current
    // Year Profit / Retained Earnings) — no new balance calculation. See docs/BALANCE_SHEET_DESIGN.md.
    Route::get('balance-sheet', [BalanceSheetController::class, 'summary'])->middleware('permission:journal_entry.view');

    // Cash Flow (Sprint 19B): Indirect Method — reuses ProfitLossService::summarize() (Net Profit
    // for the Period) and GeneralLedgerService::listAccounts() (opening/ending balance per account
    // in one call) — no new balance calculation. See docs/CASH_FLOW_DESIGN.md.
    Route::get('cash-flow', [CashFlowController::class, 'summary'])->middleware('permission:journal_entry.view');

    // Period Closing (Sprint 20B): a locking mechanism only — never posts a journal entry, never
    // modifies any report's calculation. Enforced inside JournalEntryService via PeriodLockService,
    // the single check point for the whole app. See docs/PERIOD_CLOSING_DESIGN.md.
    Route::get('fiscal-years', [PeriodController::class, 'fiscalYears'])->middleware('permission:journal_entry.view');
    Route::post('fiscal-years', [PeriodController::class, 'storeFiscalYear'])->middleware('permission:journal_entry.update');
    Route::get('accounting-periods', [PeriodController::class, 'periods'])->middleware('permission:journal_entry.view');
    Route::get('accounting-periods/{accountingPeriod}/validate', [PeriodController::class, 'validatePeriod'])->middleware('permission:journal_entry.view');
    Route::post('accounting-periods/{accountingPeriod}/close', [PeriodController::class, 'close'])->middleware('permission:journal_entry.update');
    // reopen() additionally requires hasRole('Admin') inside PeriodManagementService — this
    // middleware is an added first gate, the existing Admin-only check remains untouched.
    Route::post('accounting-periods/{accountingPeriod}/reopen', [PeriodController::class, 'reopen'])->middleware('permission:journal_entry.update');

    // Dashboard (Sprint 7) — read-only aggregates over existing entities, no new tables.
    Route::prefix('dashboard')->group(function () {
        Route::get('stock-summary', [DashboardController::class, 'stockSummary'])->middleware('permission:item.view');
        Route::get('purchases-today', [DashboardController::class, 'purchasesToday'])->middleware('permission:purchase_order.view');
        Route::get('sales-today', [DashboardController::class, 'salesToday'])->middleware('permission:sales_order.view');
        Route::get('accounts-payable-outstanding', [DashboardController::class, 'accountsPayableOutstanding'])->middleware('permission:accounts_payable.view');
        Route::get('accounts-receivable-outstanding', [DashboardController::class, 'accountsReceivableOutstanding'])->middleware('permission:accounts_receivable.view');
        Route::get('low-stock-items', [DashboardController::class, 'lowStockItems'])->middleware('permission:item.view');
        Route::get('recent-transactions', [DashboardController::class, 'recentTransactions'])->middleware('permission:dashboard.view');

        // Sprint 23B (docs/DASHBOARD_DESIGN.md §4) — each new widget endpoint gated
        // behind the same existing module permission that already gates that
        // module's own section, not a new generic "dashboard" permission.
        Route::get('financial-summary', [DashboardController::class, 'financialSummary'])->middleware('permission:journal_entry.view');
        Route::get('sales-trend', [DashboardController::class, 'salesTrend'])->middleware('permission:sales_order.view');
        Route::get('purchase-trend', [DashboardController::class, 'purchaseTrend'])->middleware('permission:purchase_order.view');
        Route::get('inventory-movement', [DashboardController::class, 'inventoryMovement'])->middleware('permission:item.view');
        Route::get('pending-tasks', [DashboardController::class, 'pendingTasks'])->middleware('permission:dashboard.view');
    });
});
