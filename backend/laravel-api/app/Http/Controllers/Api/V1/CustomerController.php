<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\CustomerListingExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerCreditService;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CustomerService $customerService,
        protected CustomerCreditService $customerCreditService,
    ) {}

    /** Preview of the code the New Customer form will get on save — see CustomerService::peekNextCode(). */
    public function nextCode(): JsonResponse
    {
        return $this->success(['customer_code' => $this->customerService->peekNextCode()]);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->success(CustomerResource::collection($this->customerService->list(
            (int) ($request->query('per_page') ?? 200),
            $request->query('search'),
        )));
    }

    /** Maintenance > Customers "Export" button — see CustomerListingExport's docblock for the layout. */
    public function export(Request $request): BinaryFileResponse
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        // Not the `boolean` validation rule: axios serializes JS true/false as the literal
        // strings "true"/"false", which that rule rejects (422'd the Outstanding view toggle before).
        $isActive = $request->has('is_active') ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN) : null;
        $search = $validated['search'] ?? null;

        $count = $this->customerService->exportCount($search, $isActive);
        if ($count > 10000) {
            throw ValidationException::withMessages([
                'search' => ["Data yang cocok ({$count} baris) melebihi batas 10.000. Persempit pencarian atau filter status terlebih dahulu."],
            ]);
        }

        $export = new CustomerListingExport($this->customerService->exportRows($search, $isActive));

        return Excel::download($export, 'xlsCustomerListing_' . now()->format('Ymd_Hi') . '.xlsx');
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customerService->create($request->validated());

        return $this->success(new CustomerResource($customer), 'Customer created.', 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        return $this->success(new CustomerResource($customer->load('salesPerson')));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->customerService->update($customer, $request->validated());

        return $this->success(new CustomerResource($customer), 'Customer updated.');
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->customerService->delete($customer);

        return $this->success(null, 'Customer deleted.');
    }

    /** Powers the New Sales Order credit/overdue block's customer-select-time check — see CustomerCreditService. */
    public function creditStatus(Customer $customer): JsonResponse
    {
        return $this->success($this->customerCreditService->evaluate($customer->id));
    }
}
