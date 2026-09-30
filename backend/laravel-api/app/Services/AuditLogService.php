<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Repositories\AuditLogRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Field-level before/after diff — every other record() call in this codebase logs only a
     * plain sentence; this is the one place a structured diff is built, for document-edit flows
     * (DeliveryService::updateComplete(), InvoiceService::updateSubmitted()) that need a real
     * "who changed what, from what, to what" trail rather than just "Updated X". Only fields that
     * actually differ are kept — an edit that touches 10 keys but only changes 2 shows 2.
     */
    public function recordChanges(string $action, string $module, Model $model, array $before, array $after, string $description): AuditLog
    {
        $changes = [];

        foreach ($after as $field => $newValue) {
            $oldValue = $before[$field] ?? null;

            if ($oldValue instanceof \BackedEnum) {
                $oldValue = $oldValue->value;
            }

            if ($newValue instanceof \BackedEnum) {
                $newValue = $newValue->value;
            }

            if ((string) $oldValue !== (string) $newValue) {
                $changes[$field] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        return $this->record($action, $module, $description, [
            'subject_type' => $model->getMorphClass(),
            'subject_id' => $model->getKey(),
            'changes' => $changes,
        ]);
    }
}
