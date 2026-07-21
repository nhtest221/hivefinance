import { ApiRequestError } from '@/features/identity/auth-api'

export type Money = { amount: string; currency: string }
export type Approval = { id: string; status: 'pending'; version: number }
export type Allocation = {
  id: string
  allocation_number: string | null
  operation: 'receipt' | 'payment' | 'credit_application' | 'credit_refund' | 'reversal'
  party_type: 'customer' | 'vendor'
  party_id: string
  settlement_date: string
  gross_amount: Money
  bank_amount: Money
  withholding_amount: Money
  allocated_amount: Money
  unapplied_amount: Money
  state: 'posted' | 'reversed'
  version: number
  posted_at: string
}
export type CreditTranche = {
  credit_tranche_id: string
  currency: string
  remaining_amount: Money
  remaining_functional_amount: Money
  source_reference: string | null
  version: number
}
export type CommandResult = { receipt?: Allocation; payment?: Allocation; allocation?: Allocation; approval?: Approval }

function commandId() { return crypto.randomUUID() }

async function request<T>(path: string, init: RequestInit = {}) {
  const response = await fetch(path, { ...init, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${sessionStorage.getItem('hivefinance.auth_token') ?? ''}`, 'X-Entity-Id': sessionStorage.getItem('hivefinance.entity_id') ?? '', 'X-Correlation-Id': commandId(), ...init.headers } })
  const data = (await response.json().catch(() => ({}))) as T & { message?: string; error_code?: string }
  if (!response.ok) throw new ApiRequestError(data.message ?? 'The settlement request failed.', response.status, data.error_code)
  return data
}

export const settlementApi = {
  allocations: () => request<{ allocations: Allocation[]; page: { next_cursor: string | null } }>('/v1/allocations?limit=100'),
  credits: (partyId: string, partyType: 'customer' | 'vendor', currency?: string) => request<{ party_credit: { balances: Array<{ available_balance: Money; functional_carrying_balance: Money }>; projection_version: number }; credit_tranches: CreditTranche[] }>(`/v1/credits/${partyId}?party_type=${partyType}${currency ? `&currency=${currency}` : ''}&limit=100`),
  receipt: (body: unknown) => request<CommandResult>('/v1/receipts', { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(body) }),
  payment: (body: unknown) => request<CommandResult>('/v1/payments', { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(body) }),
  applyCredit: (partyId: string, body: unknown) => request<CommandResult>(`/v1/credits/${partyId}/apply`, { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(body) }),
  refundCredit: (partyId: string, body: unknown) => request<CommandResult>(`/v1/credits/${partyId}/refund`, { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(body) }),
  reverse: (allocation: Allocation) => request<{ reversal_allocation?: Allocation; approval?: Approval }>(`/v1/allocations/${allocation.id}/reverse`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(allocation.version) }, body: '{}' }),
}
