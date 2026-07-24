import { ApiRequestError } from '@/features/identity/auth-api'

export function commandId() { return crypto.randomUUID() }

function authHeaders(extra: Record<string, string> = {}) {
  return { Accept: 'application/json', Authorization: `Bearer ${sessionStorage.getItem('hivefinance.auth_token') ?? ''}`, 'X-Entity-Id': sessionStorage.getItem('hivefinance.entity_id') ?? '', 'X-Correlation-Id': commandId(), ...extra }
}

async function request<T>(path: string, init: RequestInit = {}) {
  const response = await fetch(path, { ...init, headers: { ...authHeaders({ 'Content-Type': 'application/json' }), ...init.headers } })
  const data = (await response.json().catch(() => ({}))) as Record<string, unknown> & { message?: string; error_code?: string }
  if (!response.ok) throw new ApiRequestError(data.message ?? 'The approval request failed.', response.status, data.error_code)
  return data as Record<string, unknown> & T
}

export type ApprovalCommandResult = { status: number; body: Record<string, unknown> }
export type ApprovalOutcome = {
  approval: { id: string; status: 'approved'; version: number }
  command_result: ApprovalCommandResult
}

/**
 * There is no frozen list/read endpoint for pending ApprovalRequest records (API
 * Contracts defines only `POST /v1/approvals/{id}/approve`) — a checker must already
 * have the approval id and expected version, normally shared by the maker from the
 * pending-approval response their own submission produced. This client wraps the one
 * endpoint that exists; it does not simulate a list that the backend cannot provide.
 */
export const approvalsApi = {
  approve: (approvalId: string, expectedVersion: number) =>
    request<ApprovalOutcome>(`/v1/approvals/${approvalId}/approve`, {
      method: 'POST',
      headers: { 'Idempotency-Key': commandId(), 'If-Match': String(expectedVersion) },
      body: '{}',
    }),
}
