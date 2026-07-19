import { ApiRequestError } from '@/features/identity/auth-api'

export type RateRecord = { id: string; base_currency: string; quote_currency: string; rate: string; effective_date: string; source: string; referenced: boolean }
function uuid() { return crypto.randomUUID() }
async function request<T>(path: string, init: RequestInit = {}) { const response = await fetch(path, { ...init, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${sessionStorage.getItem('hivefinance.auth_token') ?? ''}`, 'X-Entity-Id': sessionStorage.getItem('hivefinance.entity_id') ?? '', 'X-Correlation-Id': uuid(), ...init.headers } }); const data = (await response.json().catch(() => ({}))) as T & { message?: string; error_code?: string }; if (!response.ok) throw new ApiRequestError(data.message ?? 'Request failed', response.status, data.error_code); return data }
export function listRates() { return request<{ rate_records: RateRecord[] }>('/v1/fx/rates?limit=100') }
export function addRate(input: { base_currency: string; quote_currency: string; rate: string; effective_date: string; source: string; is_override: boolean; override_reason: null }) { return request<{ rate_record: RateRecord } | { approval: { id: string; status: string } }>('/v1/fx/rates', { method: 'POST', headers: { 'Idempotency-Key': uuid() }, body: JSON.stringify(input) }) }
