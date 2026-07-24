import { ApiRequestError } from '@/features/identity/auth-api'

export type Page = { limit: number; next_cursor: string | null }
export type PeriodState = 'Open' | 'SoftClosed' | 'HardClosed' | 'Reopened'
export type CloseGateStatus = 'satisfied' | 'unmet' | 'stale'
export type CloseGate = {
  gate_type: string
  status: CloseGateStatus
  source_context: string | null
  source_reference: string | null
  produced_at: string | null
  reviewed_by: string | null
  reviewed_at: string | null
  evidence_version: number | null
  evidence_hash: string | null
}
export type PeriodTransition = {
  from_state: PeriodState
  to_state: PeriodState
  reason_code: string | null
  narrative: string | null
  vat_status_before: string
  vat_status_after: string
  maker_id: string
  approver_id: string | null
  approval_id: string | null
  transitioned_at: string
}
export type Period = {
  id: string
  period_ref: string
  starts_on: string
  ends_on: string
  state: PeriodState
  vat_lock_status: string
  version: number
  close_evidence_set_hash: string | null
  hard_closed_at: string | null
  hard_closed_by: string | null
  close_gate_summary?: { satisfied: number; required: number }
}
export type PeriodDetail = Period & { transitions: PeriodTransition[]; close_gates: CloseGate[] }
export type Approval = { id: string; status: 'pending'; version: number }
export type CommandResult<T> = { approval?: Approval } & Partial<T>

function commandId() { return crypto.randomUUID() }

function authHeaders(extra: Record<string, string> = {}) {
  return { Accept: 'application/json', Authorization: `Bearer ${sessionStorage.getItem('hivefinance.auth_token') ?? ''}`, 'X-Entity-Id': sessionStorage.getItem('hivefinance.entity_id') ?? '', 'X-Correlation-Id': commandId(), ...extra }
}

export class PeriodRequestError extends ApiRequestError {
  constructor(message: string, status: number, errorCode: string | undefined, readonly details?: Record<string, unknown>) {
    super(message, status, errorCode)
  }
}

async function request<T>(path: string, init: RequestInit = {}) {
  const response = await fetch(path, { ...init, headers: { ...authHeaders({ 'Content-Type': 'application/json' }), ...init.headers } })
  const data = (await response.json().catch(() => ({}))) as Record<string, unknown> & { message?: string; error_code?: string; details?: Record<string, unknown> }
  if (!response.ok) throw new PeriodRequestError(data.message ?? 'The period request failed.', response.status, data.error_code, data.details)
  return data as Record<string, unknown> & T
}

export const periodsApi = {
  list: (params: { state?: string; fiscal_year?: string; limit?: string; cursor?: string } = {}) => {
    const query = Object.entries(params).filter((entry): entry is [string, string] => Boolean(entry[1]))
    const suffix = query.length === 0 ? '' : `?${query.map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&')}`

    return request<{ periods: Period[]; page: Page }>(`/v1/periods${suffix}`)
  },
  show: (id: string) => request<{ period: PeriodDetail }>(`/v1/periods/${id}`),
  softClose: (period: Period) => request<CommandResult<{ period: Period }>>(`/v1/periods/${period.id}/soft-close`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(period.version) }, body: '{}' }),
  hardClose: (period: Period) => request<CommandResult<{ period: Period }>>(`/v1/periods/${period.id}/hard-close`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(period.version) }, body: '{}' }),
  reopen: (period: Period, input: { reason_code: string; narrative: string; vat_unlock_requested: boolean }) => request<CommandResult<{ period: Period }>>(`/v1/periods/${period.id}/reopen`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(period.version) }, body: JSON.stringify(input) }),
}
