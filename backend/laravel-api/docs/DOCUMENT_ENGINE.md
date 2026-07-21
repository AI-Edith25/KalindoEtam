# Document Engine (Sprint 3)

Every ERP document from here on (Purchase Order, Sales Invoice, Journal Entry, ...) must use this engine instead of rolling its own numbering, status, timeline, attachment, or approval mechanics. It shipped ahead of its first real consumer in Sprint 3 — verified then with a throwaway test model (see `docs/DECISIONS.md#d-r16`). Sprint 4 (`PurchaseOrder`, `GoodsReceipt`) is the first real usage.

## How a future module consumes it

A transactional model (e.g. `PurchaseOrder`) must:

1. `use Documentable, HasUuids, SoftDeletes, HasAuditTrail;` — Document Engine only owns numbering/status/timeline/attachment/approval; identity, soft delete, and audit columns are separate, already-established traits, combined the same way every other entity in this project already does.
2. Implement `public static function documentType(): string` returning the key that matches a `NamingSeries.document_type` row (e.g. `'purchase'`). If no active default `NamingSeries` exists for that key, document creation throws — numbers are never silently invented.
3. Add these columns to its migration: `document_number` (string, nullable — filled by the engine), `status` (string, cast to `App\Enums\DocumentStatus`), `revision` (unsigned integer, default 1), `submitted_at` (nullable datetime), `cancelled_at` (nullable datetime).
4. **Add `'status' => \App\Enums\DocumentStatus::class` to the model's `$casts`.** The trait cannot inject this for you — casts are declared per-model. Forgetting it is a real, easy-to-hit bug: `status` silently stays a raw string, `submit()`/`cancel()`'s `$this->status !== DocumentStatus::DRAFT` guard then always evaluates true, and every submit/cancel call fails with "Only draft documents can be submitted" even on a genuinely-Draft document. This exact bug shipped in Sprint 4's first draft of `PurchaseOrder`/`GoodsReceipt` and was only caught by the smoke test — see `docs/DECISIONS.md#d-r22`.
5. Never call `NamingSeries`/`DocumentNumberGeneratorService` directly to mint a number, and never write its own `status` transition logic — always go through `$document->submit()` / `$document->cancel()`.

That's it. In return, the model gets:

- **Automatic numbering** on `creating`, via `DocumentNumberGeneratorInterface` (bound to `DocumentNumberGeneratorService` in `AppServiceProvider`). Interface exists so a future alternate numbering strategy (e.g. per-branch counters) can be swapped in without touching every consumer.
- **Status lifecycle**: `Draft` on create, `submit()` → `Submitted` (+ `submitted_at`), `cancel()` → `Cancelled` (+ `cancelled_at`). Both guard the current state (`submit()` only from Draft, `cancel()` only from Submitted) and abort with HTTP 422 otherwise.
- **Timeline**: a `DocumentTimeline` row is recorded automatically for `created`, `submitted`, `cancelled`. Read via `GET /api/v1/document-timeline?subject_type=&subject_id=`.
- **Attachments**: `$document->attachments()` (morphMany `DocumentAttachment`). Upload/list/delete via the generic `POST /api/v1/attachments`, `GET /api/v1/attachments?attachable_type=&attachable_id=`, `DELETE /api/v1/attachments/{id}` — no per-module attachment endpoints needed.
- **Approval slot**: `$document->approvalFlows()` (morphMany `ApprovalFlow`) — schema only, no workflow logic runs against it yet (see below).
- **Extension hooks**: `afterCreate()`, `afterSubmit()`, `afterCancel()` — no-op `protected` methods in the trait. A consuming model overrides the ones it needs (e.g. `PurchaseOrder::afterSubmit()` could trigger `StockLedgerService` for a receipt) — PHP resolves the class's own method over the trait's, so no trait modification is required.

## Naming Series

`NamingSeries` is a normal configurable master (full CRUD at `/api/v1/naming-series`): `module` (grouping, e.g. "purchase"), `document_type` (the actual lookup key), `prefix`, `suffix`, `digit_length` (default 5), `current_number`, `is_default`, `is_active`. Multiple series can exist for the same `document_type`; the generator always uses the one with `is_default=true, is_active=true` — enforced as an application invariant, not a DB constraint, so switching the default series is just an update, not a migration.

`DocumentNumberGeneratorService::generate()` locks the target row (`lockForUpdate`, same pattern as `StockLedgerService`) inside a `DB::transaction()`, increments `current_number`, and formats `prefix + zero-padded(number, digit_length) + suffix`.

Seeded defaults (`DocumentEngineSeeder`): `purchase` (PO-), `sales` (SO-), `invoice` (INV-), `journal` (JE-) — placeholders for the four modules named in this sprint. Sub-doctypes (Purchase Order vs Purchase Receipt vs Purchase Invoice, per `03_ERP_BLUEPRINT.md`) get their own `NamingSeries` row when that module is actually scoped, not guessed now.

## Approval Flow — structure only

`ApprovalFlow` (table `approval_flows`) exists purely as schema: `approvable_type/id`, `approver_id`, `status` (`ApprovalStatus`: Pending/Approved/Rejected), `step`, `remarks`, `decided_at`. No Repository, Service, Controller, or route — there is no workflow to serve yet, and shipping a CRUD API for a feature with no behavior behind it would misrepresent it as working. Implement the actual approval logic (who can approve, how `step` gates a multi-level chain, what triggers a request) in the sprint that needs it.
