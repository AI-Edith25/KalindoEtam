export type ApprovalStatus = 'pending' | 'approved' | 'rejected'

/** The three document types Sprint 24B activated approval for — matches ApprovalService::MODULES on the backend exactly. */
export type ApprovableModule = 'sales.orders' | 'purchase.orders' | 'accounting.journal_entries'

export interface ApprovalFlow {
  id: string
  approvable_type: string
  approvable_id: string
  approver: { id: string; name: string; email: string } | null
  status: ApprovalStatus
  step: number
  remarks: string | null
  decided_at: string | null
  created_at: string
}

/** Present on any document type that opted into approval (docs/APPROVAL_WORKFLOW_DESIGN.md §1) — always available even when null (no request made yet). */
export interface Approvable {
  requires_approval: boolean
  latest_approval: ApprovalFlow | null
}
