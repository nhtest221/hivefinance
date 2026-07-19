import { ApiRequestError } from '@/features/identity/auth-api'

export type TaxCode = { id: string; code: string; name: string; jurisdiction: string; status: string; version: number; applicable_version_id?: string | null }

function uuid() { return crypto.randomUUID() }

async function request<T>(path: string, init: RequestInit = {}) {
  const response = await fetch(path, { ...init, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${sessionStorage.getItem('hivefinance.auth_token') ?? ''}`, 'X-Entity-Id': sessionStorage.getItem('hivefinance.entity_id') ?? '', 'X-Correlation-Id': uuid(), ...init.headers } })
  const data = (await response.json().catch(() => ({}))) as T & { message?: string; error_code?: string }
  if (!response.ok) throw new ApiRequestError(data.message ?? 'Request failed', response.status, data.error_code)
  return data
}

export function listTaxCodes() { return request<{ tax_codes: TaxCode[] }>('/v1/tax/codes?limit=100') }
export function createTaxCode(input: { code: string; name: string; jurisdiction: string }) { return request<{ approval: { id: string; status: string } }>('/v1/tax/codes', { method: 'POST', headers: { 'Idempotency-Key': uuid() }, body: JSON.stringify(input) }) }
