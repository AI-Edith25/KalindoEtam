<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Repositories\AuditLogRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * The single place that writes an audit entry — every module that wants to
 * be audited calls record() instead of touching the `audit_logs` table
 * directly, the same "one calculation/one write path, many callers" shape
 * TaxService/DocumentTimelineService already use elsewhere in this app.
 */
class AuditLogService
{
    public function __construct(protected AuditLogRepository $auditLogRepository) {}

    public function record(string $action, string $module, ?string $description = null, array $properties = [], ?string $userId = null): AuditLog
    {
        return $this->auditLogRepository->create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'ip_address' => RequestFacade::ip(),
            'properties' => $properties,
            'created_at' => now(),
        ]);
    }

    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->auditLogRepository->search($filters, $perPage);
    }
}
