<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Enums\WarehouseType;
use App\Models\AccountsPayable;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\PaymentEntry;
use App\Models\PurchaseOrder;
use App\Models\ReceiptEntry;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ApprovalService;
use App\Services\BranchService;
use App\Services\CompanyService;
use App\Services\CustomerService;
use App\Services\DeliveryService;
use App\Services\GoodsReceiptService;
use App\Services\InvoiceService;
use App\Services\ItemGroupService;
use App\Services\ItemService;
use App\Services\PaymentAllocationService;
use App\Services\PaymentEntryService;
use App\Services\PurchaseOrderService;
use App\Services\ReceiptEntryService;
use App\Services\RoleService;
use App\Services\SalesOrderService;
use App\Services\SupplierService;
use App\Services\UserService;
use App\Services\WarehouseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * One-time demo data population, entirely through the app's Service layer
 * (never raw DB inserts) — see the "Populate demo data" task. Run manually,
 * NOT wired into DatabaseSeeder::run(), since this is optional showcase
 * data, not baseline app data like DocumentEngineSeeder.
 *
 *   php artisan db:seed --class="Database\Seeders\DemoDataSeeder" --force
 *
 * Idempotent at the master-data level (looked up by business key before
 * creating). Transactional sections (PO/GR/PE, SO/Delivery/Invoice/Receipt)
 * are guarded by "skip the whole section if any such document already
 * exists" — good enough for a one-time demo run, not a full reconciliation
 * engine.
 */
class DemoDataSeeder extends Seeder
{
    protected array $summary = [];

    public function run(): void
    {
        // ApprovalService::decide() checks Auth::user()->can(...); every other
        // create() attributes created_by via HasAuditTrail. Acting as the
        // already-seeded Admin (RolePermissionSeeder) gives both for free.
        $admin = User::where('email', 'admin@example.com')->first();

        if (! $admin) {
            $this->command->error('Admin user (admin@example.com) not found — run RolePermissionSeeder first.');

            return;
        }

        Auth::login($admin);

        $company = $this->seedCompany();
        $this->seedBranches($company);
        [$warehouseUtama, $warehouseSparepart] = $this->seedWarehouses();
        $itemGroup = $this->seedItemGroup();
        $uom = $this->reuseUom();
        $tax = $this->reuseTax();
        $suppliers = $this->seedSuppliers();
        $customers = $this->seedCustomers();
        $items = $this->seedItems($itemGroup, $uom);
        $this->seedEmployees();

        $this->seedPurchaseToPay($suppliers, $items, $warehouseUtama, $tax);
        $this->seedOrderToCash($customers, $items, $warehouseUtama, $tax);

        $this->printSummary();
    }

    protected function seedCompany(): Company
    {
        $company = Company::where('code', 'KAE')->first();

        if ($company) {
            $this->summary['Company'] = 'skipped (already exists)';

            return $company;
        }

        $company = app(CompanyService::class)->create([
            'name' => 'PT Kalindo Etam',
            'code' => 'KAE',
            'address' => 'Jl. Jenderal Sudirman No. 45, Samarinda, Kalimantan Timur',
            'phone' => '0541-741234',
            'email' => 'info@kalindoetam.co.id',
            'npwp' => '01.234.567.8-722.000',
            'currency' => 'IDR',
            'timezone' => 'Asia/Makassar',
            'fiscal_year_start' => now()->startOfYear()->toDateString(),
        ]);

        $this->summary['Company'] = 1;

        return $company;
    }

    protected function seedBranches(Company $company): array
    {
        $branches = [
            ['code' => 'SMD', 'name' => 'Samarinda', 'address' => 'Jl. P. Antasari No. 12, Samarinda', 'is_head_office' => true],
            ['code' => 'BPP', 'name' => 'Balikpapan', 'address' => 'Jl. MT Haryono No. 88, Balikpapan', 'is_head_office' => false],
        ];

        $created = 0;
        $result = [];

        foreach ($branches as $data) {
            $branch = Branch::where('code', $data['code'])->first();

            if (! $branch) {
                $branch = app(BranchService::class)->create([...$data, 'company_id' => $company->id]);
                $created++;
            }

            $result[] = $branch;
        }

        $this->summary['Branch'] = $created > 0 ? $created : 'skipped (already exists)';

        return $result;
    }

    /** Warehouse no longer belongs to a Branch (business decision) — these are just two named warehouses. */
    protected function seedWarehouses(): array
    {
        $warehouses = [
            ['code' => 'WH-UTM', 'name' => 'Gudang Utama'],
            ['code' => 'WH-SPR', 'name' => 'Gudang Sparepart'],
        ];

        $created = 0;
        $result = [];

        foreach ($warehouses as $data) {
            $warehouse = Warehouse::where('code', $data['code'])->first();

            if (! $warehouse) {
                $warehouse = app(WarehouseService::class)->create([...$data, 'warehouse_type' => WarehouseType::MAIN->value]);
                $created++;
            }

            $result[] = $warehouse;
        }

        $this->summary['Warehouse'] = $created > 0 ? $created : 'skipped (already exists)';

        return $result;
    }

    /**
     * The 5 existing ItemGroups (Semen, Besi, Cat, Pipa, Kayu — from
     * MasterDataSeeder) are construction-material categories; none fit IT
     * equipment. Adding "Elektronik" is a missing prerequisite (rule 9),
     * not a duplicate of an already-populated module (rule 8) — those 5
     * rows exist but categorize nothing we're about to create.
     */
    protected function seedItemGroup(): ItemGroup
    {
        $group = ItemGroup::where('name', 'Elektronik')->first();

        if ($group) {
            $this->summary['Item Group'] = 'skipped (already exists)';

            return $group;
        }

        $group = app(ItemGroupService::class)->create([
            'name' => 'Elektronik',
            'description' => 'Perangkat komputer dan elektronik kantor',
        ]);

        $this->summary['Item Group'] = 1;

        return $group;
    }

    /** "Pcs" already exists (MasterDataSeeder) and fits every demo item — reused, not recreated. */
    protected function reuseUom(): UnitOfMeasurement
    {
        return UnitOfMeasurement::where('name', 'Pcs')->firstOrFail();
    }

    /** "PPN 11%" already exists (MasterDataSeeder) and is active — reused, not recreated. */
    protected function reuseTax(): Tax
    {
        return Tax::where('name', 'PPN 11%')->where('is_active', true)->firstOrFail();
    }

    protected function seedSuppliers(): array
    {
        $suppliers = [
            ['supplier_code' => 'SUP-AST', 'supplier_name' => 'PT Astra Otoparts', 'phone' => '021-6519555', 'email' => 'sales@astraotoparts.co.id', 'address' => 'Jl. Raya Pegangsaan Dua Km. 2.2, Jakarta Utara'],
            ['supplier_code' => 'SUP-IND', 'supplier_name' => 'PT Indomarco', 'phone' => '021-8062900', 'email' => 'procurement@indomarco.co.id', 'address' => 'Jl. Sulawesi Blok DD, Jababeka, Cikarang'],
            ['supplier_code' => 'SUP-SBM', 'supplier_name' => 'PT Sumber Makmur', 'phone' => '0541-732211', 'email' => 'info@sumbermakmur.co.id', 'address' => 'Jl. Cendana No. 21, Samarinda'],
        ];

        return $this->seedByCode(Supplier::class, SupplierService::class, $suppliers, 'supplier_code', 'Supplier');
    }

    protected function seedCustomers(): array
    {
        $customers = [
            ['customer_code' => 'CUST-MJB', 'customer_name' => 'CV Maju Bersama', 'phone' => '0541-556677', 'email' => 'purchasing@majubersama.co.id', 'address' => 'Jl. Ahmad Yani No. 10, Samarinda'],
            ['customer_code' => 'CUST-KTM', 'customer_name' => 'PT Kaltim Mining', 'phone' => '0542-441122', 'email' => 'procurement@kaltimmining.co.id', 'address' => 'Jl. Jenderal Sudirman No. 5, Balikpapan'],
            ['customer_code' => 'CUST-BRK', 'customer_name' => 'UD Berkah', 'phone' => '0541-990011', 'email' => 'udberkah@gmail.com', 'address' => 'Jl. Pasar Pagi No. 3, Samarinda'],
        ];

        return $this->seedByCode(Customer::class, CustomerService::class, $customers, 'customer_code', 'Customer');
    }

    protected function seedItems(ItemGroup $itemGroup, UnitOfMeasurement $uom): array
    {
        $items = [
            ['item_code' => 'ITM-LAPTOP01', 'item_name' => 'Laptop Lenovo ThinkPad', 'standard_rate' => 15000000],
            ['item_code' => 'ITM-PRINTER01', 'item_name' => 'Printer Epson L3250', 'standard_rate' => 3200000],
            ['item_code' => 'ITM-MOUSE01', 'item_name' => 'Mouse Logitech', 'standard_rate' => 200000],
            ['item_code' => 'ITM-KEYBOARD01', 'item_name' => 'Keyboard Mechanical', 'standard_rate' => 550000],
            ['item_code' => 'ITM-SSD01', 'item_name' => 'SSD Samsung 1TB', 'standard_rate' => 1500000],
        ];

        $created = 0;
        $result = [];

        foreach ($items as $data) {
            $item = Item::where('item_code', $data['item_code'])->first();

            if (! $item) {
                $item = app(ItemService::class)->create([
                    ...$data,
                    'item_group_id' => $itemGroup->id,
                    'uom_id' => $uom->id,
                ]);
                $created++;
            }

            $result[$data['item_code']] = $item;
        }

        $this->summary['Item'] = $created > 0 ? $created : 'skipped (already exists)';

        return $result;
    }

    /** Employees = Users + Spatie roles in this codebase (no separate Employee model). */
    protected function seedEmployees(): void
    {
        $roleNames = ['Manager', 'Purchasing Staff', 'Warehouse Staff'];
        $rolesCreated = 0;

        foreach ($roleNames as $name) {
            if (! Role::where('name', $name)->exists()) {
                app(RoleService::class)->create(['name' => $name, 'guard_name' => 'web']);
                $rolesCreated++;
            }
        }

        $employees = [
            ['name' => 'Budi Santoso', 'email' => 'budi.santoso@kalindoetam.co.id', 'role' => 'Manager'],
            ['name' => 'Siti Rahmawati', 'email' => 'siti.rahmawati@kalindoetam.co.id', 'role' => 'Purchasing Staff'],
            ['name' => 'Andi Wijaya', 'email' => 'andi.wijaya@kalindoetam.co.id', 'role' => 'Warehouse Staff'],
        ];

        $usersCreated = 0;

        foreach ($employees as $data) {
            if (! User::where('email', $data['email'])->exists()) {
                app(UserService::class)->create([...$data, 'password' => 'password']);
                $usersCreated++;
            }
        }

        $this->summary['Employee (User)'] = $usersCreated > 0 ? $usersCreated : 'skipped (already exists)';
    }

    /**
     * Purchase Order -> Goods Receipt -> Payment Entry.
     *
     * No "Purchase Invoice" step exists in this codebase — confirmed by
     * code search (zero references anywhere in app/). AccountsPayable is
     * created automatically inside GoodsReceiptService::submit(), and
     * PaymentEntryService pays that directly. This is the real flow, not
     * a shortcut.
     */
    protected function seedPurchaseToPay(array $suppliers, array $items, Warehouse $warehouse, Tax $tax): void
    {
        if (PurchaseOrder::count() > 0) {
            $this->summary['Purchase Order'] = 'skipped (already exists)';
            $this->summary['Goods Receipt'] = 'skipped (already exists)';
            $this->summary['Payment Entry'] = 'skipped (already exists)';

            return;
        }

        $poService = app(PurchaseOrderService::class);
        $grService = app(GoodsReceiptService::class);
        $peService = app(PaymentEntryService::class);
        $approvalService = app(ApprovalService::class);

        $today = now()->toDateString();

        // PO1: PT Astra Otoparts — Laptop, Mouse, SSD
        $po1 = $poService->create([
            'supplier_id' => $suppliers['SUP-AST']->id,
            'order_date' => $today,
            'expected_delivery_date' => now()->addDays(7)->toDateString(),
            'remarks' => 'Demo seed data',
            'tax_id' => $tax->id,
            'items' => [
                ['item_id' => $items['ITM-LAPTOP01']->id, 'qty' => 5, 'rate' => 12500000],
                ['item_id' => $items['ITM-MOUSE01']->id, 'qty' => 20, 'rate' => 150000],
                ['item_id' => $items['ITM-SSD01']->id, 'qty' => 10, 'rate' => 1200000],
            ],
        ]);

        // PO2: PT Sumber Makmur — Printer, Keyboard
        $po2 = $poService->create([
            'supplier_id' => $suppliers['SUP-SBM']->id,
            'order_date' => $today,
            'expected_delivery_date' => now()->addDays(7)->toDateString(),
            'remarks' => 'Demo seed data',
            'tax_id' => $tax->id,
            'items' => [
                ['item_id' => $items['ITM-PRINTER01']->id, 'qty' => 5, 'rate' => 2800000],
                ['item_id' => $items['ITM-KEYBOARD01']->id, 'qty' => 15, 'rate' => 450000],
            ],
        ]);

        foreach ([$po1, $po2] as $po) {
            $flow = $approvalService->requestApproval($po);
            $approvalService->approve($flow);
            $poService->submit($po);
        }

        $this->summary['Purchase Order'] = 2;

        // GR1 against PO1, GR2 against PO2 — full quantities, same warehouse
        $gr1 = $grService->create([
            'purchase_order_id' => $po1->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => $today,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => $po1->items->map(fn ($line) => [
                'purchase_order_item_id' => $line->id,
                'qty' => $line->qty,
            ])->all(),
        ]);
        $gr1 = $grService->submit($gr1);

        $gr2 = $grService->create([
            'purchase_order_id' => $po2->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => $today,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => $po2->items->map(fn ($line) => [
                'purchase_order_item_id' => $line->id,
                'qty' => $line->qty,
            ])->all(),
        ]);
        $gr2 = $grService->submit($gr2);

        $this->summary['Goods Receipt'] = 2;

        // AccountsPayable was created as a side effect of GR submit() above.
        $ap1 = AccountsPayable::where('goods_receipt_id', $gr1->id)->firstOrFail();
        $ap2 = AccountsPayable::where('goods_receipt_id', $gr2->id)->firstOrFail();

        // PE1: pay AP1 in full
        $pe1 = $peService->create([
            'supplier_id' => $po1->supplier_id,
            'payment_date' => $today,
            'payment_method' => PaymentMethod::BANK_TRANSFER->value,
            'reference_number' => 'TRF-DEMO-001',
            'items' => [['accounts_payable_id' => $ap1->id, 'paid_amount' => (float) $ap1->amount]],
        ]);
        $peService->submit($pe1);

        // PE2: pay AP2 partially — demonstrates PartiallyPaid status
        $pe2 = $peService->create([
            'supplier_id' => $po2->supplier_id,
            'payment_date' => $today,
            'payment_method' => PaymentMethod::CASH->value,
            'reference_number' => 'CASH-DEMO-002',
            'items' => [['accounts_payable_id' => $ap2->id, 'paid_amount' => round((float) $ap2->amount / 2, 2)]],
        ]);
        $peService->submit($pe2);

        $this->summary['Payment Entry'] = 2;
    }

    /** Sales Order -> Delivery -> Invoice (creates AccountsReceivable on submit) -> Receipt Entry + allocation. */
    protected function seedOrderToCash(array $customers, array $items, Warehouse $warehouse, Tax $tax): void
    {
        if (SalesOrder::count() > 0) {
            $this->summary['Sales Order'] = 'skipped (already exists)';
            $this->summary['Delivery'] = 'skipped (already exists)';
            $this->summary['Sales Invoice'] = 'skipped (already exists)';
            $this->summary['Receipt Entry'] = 'skipped (already exists)';

            return;
        }

        $soService = app(SalesOrderService::class);
        $deliveryService = app(DeliveryService::class);
        $invoiceService = app(InvoiceService::class);
        $receiptService = app(ReceiptEntryService::class);
        $approvalService = app(ApprovalService::class);
        $allocationService = app(PaymentAllocationService::class);

        $today = now()->toDateString();

        // SO1: CV Maju Bersama — Laptop, Mouse (within the stock GR1 just received)
        $so1 = $soService->create([
            'customer_id' => $customers['CUST-MJB']->id,
            'order_date' => $today,
            'expected_delivery_date' => now()->addDays(5)->toDateString(),
            'remarks' => 'Demo seed data',
            'items' => [
                ['item_id' => $items['ITM-LAPTOP01']->id, 'qty' => 2, 'rate' => 15000000],
                ['item_id' => $items['ITM-MOUSE01']->id, 'qty' => 5, 'rate' => 200000],
            ],
        ]);

        // SO2: PT Kaltim Mining — SSD, Printer
        $so2 = $soService->create([
            'customer_id' => $customers['CUST-KTM']->id,
            'order_date' => $today,
            'expected_delivery_date' => now()->addDays(5)->toDateString(),
            'remarks' => 'Demo seed data',
            'items' => [
                ['item_id' => $items['ITM-SSD01']->id, 'qty' => 3, 'rate' => 1500000],
                ['item_id' => $items['ITM-PRINTER01']->id, 'qty' => 1, 'rate' => 3200000],
            ],
        ]);

        foreach ([$so1, $so2] as $so) {
            $flow = $approvalService->requestApproval($so);
            $approvalService->approve($flow);
            $soService->submit($so);
        }

        $this->summary['Sales Order'] = 2;

        $delivery1 = $deliveryService->create([
            'sales_order_id' => $so1->id,
            'warehouse_id' => $warehouse->id,
            'delivery_date' => $today,
            'due_date' => now()->addDays(3)->toDateString(),
            'items' => $so1->items->map(fn ($line) => [
                'sales_order_item_id' => $line->id,
                'qty' => $line->qty,
            ])->all(),
        ]);
        $delivery1 = $deliveryService->submit($delivery1);

        $delivery2 = $deliveryService->create([
            'sales_order_id' => $so2->id,
            'warehouse_id' => $warehouse->id,
            'delivery_date' => $today,
            'due_date' => now()->addDays(3)->toDateString(),
            'items' => $so2->items->map(fn ($line) => [
                'sales_order_item_id' => $line->id,
                'qty' => $line->qty,
            ])->all(),
        ]);
        $delivery2 = $deliveryService->submit($delivery2);

        $this->summary['Delivery'] = 2;

        $invoice1 = $invoiceService->create([
            'delivery_id' => $delivery1->id,
            'invoice_date' => $today,
            'due_date' => now()->addDays(14)->toDateString(),
            'tax_id' => $tax->id,
        ]);
        $invoice1 = $invoiceService->submit($invoice1);

        $invoice2 = $invoiceService->create([
            'delivery_id' => $delivery2->id,
            'invoice_date' => $today,
            'due_date' => now()->addDays(14)->toDateString(),
            'tax_id' => $tax->id,
        ]);
        $invoice2 = $invoiceService->submit($invoice2);

        $this->summary['Sales Invoice'] = 2;

        $ar1 = $invoice1->fresh(['accountsReceivable'])->accountsReceivable;
        $ar2 = $invoice2->fresh(['accountsReceivable'])->accountsReceivable;

        // Receipt 1: full payment, fully allocated
        $receipt1 = $receiptService->create([
            'customer_id' => $customers['CUST-MJB']->id,
            'receipt_date' => $today,
            'payment_method' => PaymentMethod::QRIS->value,
            'reference_number' => 'QRIS-DEMO-001',
            'total_amount' => (float) $invoice1->grand_total,
        ]);
        $receipt1 = $receiptService->submit($receipt1);
        $allocationService->allocateBatch($receipt1, [
            ['accounts_receivable_id' => $ar1->id, 'amount' => (float) $invoice1->grand_total],
        ]);

        // Receipt 2: partial payment, partially allocated — demonstrates PartiallyPaid status
        $partialAmount = round((float) $invoice2->grand_total / 2, 2);
        $receipt2 = $receiptService->create([
            'customer_id' => $customers['CUST-KTM']->id,
            'receipt_date' => $today,
            'payment_method' => PaymentMethod::BANK_TRANSFER->value,
            'reference_number' => 'TRF-DEMO-002',
            'total_amount' => $partialAmount,
        ]);
        $receipt2 = $receiptService->submit($receipt2);
        $allocationService->allocateBatch($receipt2, [
            ['accounts_receivable_id' => $ar2->id, 'amount' => $partialAmount],
        ]);

        $this->summary['Receipt Entry'] = 2;
    }

    protected function seedByCode(string $modelClass, string $serviceClass, array $rows, string $keyField, string $label): array
    {
        $created = 0;
        $result = [];

        foreach ($rows as $data) {
            $model = $modelClass::where($keyField, $data[$keyField])->first();

            if (! $model) {
                $model = app($serviceClass)->create([...$data, 'is_active' => true]);
                $created++;
            }

            $result[$data[$keyField]] = $model;
        }

        $this->summary[$label] = $created > 0 ? $created : 'skipped (already exists)';

        return $result;
    }

    protected function printSummary(): void
    {
        $order = [
            'Company', 'Branch', 'Warehouse', 'Supplier', 'Customer', 'Item Group', 'Item',
            'Employee (User)', 'Purchase Order', 'Goods Receipt', 'Payment Entry',
            'Sales Order', 'Delivery', 'Sales Invoice', 'Receipt Entry',
        ];

        $this->command->newLine();
        $this->command->info('Demo data seed summary:');

        foreach ($order as $label) {
            $value = $this->summary[$label] ?? 'not run';
            $mark = is_int($value) ? '✔' : '⏭';
            $this->command->line("{$mark} {$label}: {$value}");
        }

        $this->command->newLine();
    }
}
