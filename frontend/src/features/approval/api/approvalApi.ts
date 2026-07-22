import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { ApprovableModule, ApprovalFlow } from '../types'

/** One request-approval endpoint per document type (each gated by that document's own existing `.update` permission — see docs/APPROVAL_WORKFLOW_DESIGN.md §4) — the approve()/reject() endpoints below are shared by all three. */
const REQUEST_APPROVAL_PATHS: Record<ApprovableModule, (id: string) => string> = {
  'sales.orders': (id) => `/sales-orders/${id}/request-approval`,
  'purchase.orders': (id) => `/purchase-orders/${id}/request-approval`,
  'accounting.journal_entries': (id) => `/journal-entries/${id}/request-approval`,
}

export async function requestApproval(module: ApprovableModule, documentId: string): Promise<ApprovalFlow> {
  const { data } = await apiClient.post<ApiResponse<ApprovalFlow>>(REQUEST_APPROVAL_PATHS[module](documentId))
  return data.data
}

export async function fetchApprovalHistory(approvableType: string, approvableId: string): Promise<ApprovalFlow[]> {
  const { data } = await apiClient.get<ApiListResponse<ApprovalFlow>>('/approval-flows', {
    params: { approvable_type: approvableType, approvable_id: approvableId },
  })
  return data.data
}

export async function approveFlow(approvalFlowId: string, remarks?: string): Promise<ApprovalFlow> {
  const { data } = await apiClient.post<ApiResponse<ApprovalFlow>>(`/approval-flows/${approvalFlowId}/approve`, { remarks })
  return data.data
}

export async function rejectFlow(approvalFlowId: string, remarks: string): Promise<ApprovalFlow> {
  const { data } = await apiClient.post<ApiResponse<ApprovalFlow>>(`/approval-flows/${approvalFlowId}/reject`, { remarks })
  return data.data
}
