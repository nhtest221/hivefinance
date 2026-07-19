import { ApiRequestError } from '@/features/identity/auth-api'

export type Money = { amount: string; currency: string }
export type Account = { id: string; code: string; name: string; type: string; normal_balance: string; status: string; version: number }
export type Journal = { id: string; period_ref: string; entry_date: string; state: string; version: number }
export type GeneralLedger = {
  account: { id: string; code: string; name: string; normal_balance: string }
  opening_balance: Money
  closing_balance: Money
  entries: Array<{ journal_entry_id: string; entry_date: string; description: string | null; debit: Money | null; credit: Money | null; running_balance: Money }>
}

function uuid() {
  return crypto.randomUUID()
}

async function request<T>(path: string, init: RequestInit = {}): Promise<{ data: T; correlationId: string | null }> {
  const token = sessionStorage.getItem('hivefinance.auth_token')
  const entityId = sessionStorage.getItem('hivefinance.entity_id')
  const response = await fetch(path, {
    ...init,
    headers: {
      Accept: 'application/json', 'Content-Type': 'application/json',
      Authorization: `Bearer ${token ?? ''}`, 'X-Entity-Id': entityId ?? '', 'X-Correlation-Id': uuid(), ...init.headers,
    },
  })
  const data = (await response.json().catch(() => ({}))) as T & { message?: string; error_code?: string }
  if (!response.ok) throw new ApiRequestError(data.message ?? 'Request failed', response.status, data.error_code)
  return { data, correlationId: response.headers.get('X-Correlation-Id') }
}

export async function listAccounts() {
  return request<{ accounts: Account[] }>('/v1/accounts?status=active&limit=100')
}

export async function createAccount(input: { code: string; name: string; description: string | null; type: string }) {
  return request<{ account: Account }>('/v1/accounts', { method: 'POST', headers: { 'Idempotency-Key': uuid() }, body: JSON.stringify(input) })
}

export async function createJournal(input: { entry_date: string; narration: string; lines: Array<{ account_id: string; description: string; debit: Money | null; credit: Money | null }> }) {
  return request<{ journal: Journal }>('/v1/journals', { method: 'POST', headers: { 'Idempotency-Key': uuid() }, body: JSON.stringify({ ...input, entry_type: 'manual' }) })
}

export async function postJournal(journal: Journal) {
  return request<{ journal: Journal }>(`/v1/journals/${journal.id}/post`, { method: 'POST', headers: { 'Idempotency-Key': uuid(), 'If-Match': String(journal.version) }, body: '{}' })
}

export async function getGeneralLedger(accountId: string, from: string, to: string) {
  return request<GeneralLedger>(`/v1/reports/general-ledger?account=${encodeURIComponent(accountId)}&range=${from}..${to}&limit=100`)
}
