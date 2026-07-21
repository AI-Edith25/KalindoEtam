<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Repositories\CompanyRepository;
use App\Repositories\DocumentAttachmentRepository;
use App\Services\CompanyService;
use App\Services\DocumentAttachmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CompanyService $companyService,
        protected CompanyRepository $companyRepository,
        protected DocumentAttachmentRepository $documentAttachmentRepository,
        protected DocumentAttachmentService $documentAttachmentService,
    ) {}

    public function index(): JsonResponse
    {
        return $this->success(CompanyResource::collection($this->companyService->list()));
    }

    /**
     * Unguarded (auth:sanctum only, no company.view) — application-shell branding for
     * every logged-in user, not just those who can manage Company records. Hand-built
     * payload, never CompanyResource, so no address/tax/banking field can leak here
     * even if CompanyResource grows fields later.
     */
    public function branding(): JsonResponse
    {
        $company = $this->companyRepository->defaultOrById(null);
        $logo = $company
            ? $this->documentAttachmentRepository->forAttachable(Company::class, $company->id, 1)->first()
            : null;

        return $this->success([
            'name' => $company?->name,
            'logo_url' => $logo ? '/company/branding/logo' : null,
        ]);
    }

    /** Streams the current company's logo, same mechanism as the gated attachment download, reachable without document_attachment.view. */
    public function brandingLogo(): StreamedResponse
    {
        $company = $this->companyRepository->defaultOrById(null);
        $logo = $company
            ? $this->documentAttachmentRepository->forAttachable(Company::class, $company->id, 1)->first()
            : null;

        abort_if(! $logo, 404);

        return $this->documentAttachmentService->download($logo);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = $this->companyService->create($request->validated());

        return $this->success(new CompanyResource($company), 'Company created.', 201);
    }

    public function show(Company $company): JsonResponse
    {
        return $this->success(new CompanyResource($company));
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $company = $this->companyService->update($company, $request->validated());

        return $this->success(new CompanyResource($company), 'Company updated.');
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->companyService->delete($company);

        return $this->success(null, 'Company deleted.');
    }
}
