import { ApiRequestError } from '@/features/identity/auth-api'

export type Money = { amount: string; currency: string }
export type Page = { limit: number; next_cursor: string | null }
export type ReportType = 'trial_balance' | 'general_ledger' | 'profit_and_loss' | 'balance_sheet' | 'ar_ageing' | 'ap_ageing' | 'tax_summary' | 'fx_revaluation' | 'cash_view'
export type ReportRunState = 'Generated' | 'PendingApproval' | 'Approved' | 'Rejected' | 'Superseded'
export type Approval = { id: string; status: 'pending'; version: number }

export type ReportRun = {
  id: string
  report_type: ReportType
  period_ref: string | null
  as_of: string | null
  basis: 'accrual' | 'cash'
  state: ReportRunState
  version: number
  content_hash: string
  source_data_watermark: string
  generated_by: string
  generated_at: string
  reviewed_by: string | null
  reviewed_at: string | null
  approved_by: string | null
  approved_at: string | null
  superseded_by_report_run_id: string | null
  entity_id?: string
  functional_currency?: string
  filters?: Record<string, unknown>
  layout_version?: number | null
  classification_version?: number | null
  policy_version?: number | null
  content?: Record<string, unknown>
}
export type ReportRunGenerateInput = { report_type: ReportType; period_ref?: string; as_of?: string; filters?: Record<string, unknown> }
export type ReportRunCommandResult = { report_run?: ReportRun; approval?: Approval }

/** API Contracts §12.7: the evidence a Close Gate consumes — surfaced read-only here. */
export type CloseGate = { gate_type: string; status: 'satisfied' | 'unmet'; source_context: string | null; source_reference: string | null; produced_at: string | null; reviewed_by: string | null; reviewed_at: string | null; evidence_version: number | null; evidence_hash: string | null }

function commandId() { return crypto.randomUUID() }

function authHeaders(extra: Record<string, string> = {}) {
  return { Accept: 'application/json', Authorization: `Bearer ${sessionStorage.getItem('hivefinance.auth_token') ?? ''}`, 'X-Entity-Id': sessionStorage.getItem('hivefinance.entity_id') ?? '', 'X-Correlation-Id': commandId(), ...extra }
}

async function request<T>(path: string, init: RequestInit = {}) {
  const response = await fetch(path, { ...init, headers: { ...authHeaders({ 'Content-Type': 'application/json' }), ...init.headers } })
  const data = (await response.json().catch(() => ({}))) as Record<string, unknown> & { message?: string; error_code?: string }
  if (!response.ok) throw new ApiRequestError(data.message ?? 'The reporting request failed.', response.status, data.error_code)
  return data as Record<string, unknown> & T
}

function query(params: Record<string, string | undefined | null>) {
  const entries = Object.entries(params).filter((entry): entry is [string, string] => entry[1] !== undefined && entry[1] !== null && entry[1] !== '')

  return entries.length === 0 ? '' : '?'+entries.map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&')
}

export const reportsApi = {
  trialBalance: (params: { asOf: string; period_ref?: string; sbu?: string }) => request<Record<string, unknown>>(`/v1/reports/trial-balance${query({ asOf: params.asOf, period_ref: params.period_ref, sbu: params.sbu })}`),
  generalLedger: (params: { account: string; range: string; sbu?: string; limit?: string; cursor?: string }) => request<Record<string, unknown>>(`/v1/reports/general-ledger${query(params)}`),
  profitAndLoss: (params: { period: string; sbu?: string; basis?: string; compare_to?: string }) => request<Record<string, unknown>>(`/v1/reports/profit-loss${query(params)}`),
  balanceSheet: (params: { asOf: string; sbu?: string; compare_to?: string }) => request<Record<string, unknown>>(`/v1/reports/balance-sheet${query({ asOf: params.asOf, sbu: params.sbu, compare_to: params.compare_to })}`),
  arAgeing: (params: { asOf: string; customer?: string }) => request<Record<string, unknown>>(`/v1/reports/ar-ageing${query({ asOf: params.asOf, customer: params.customer })}`),
  apAgeing: (params: { asOf: string; vendor?: string }) => request<Record<string, unknown>>(`/v1/reports/ap-ageing${query({ asOf: params.asOf, vendor: params.vendor })}`),
  taxSummary: (params: { period: string }) => request<Record<string, unknown>>(`/v1/reports/tax-summary${query(params)}`),
  fxRevaluation: (params: { period: string }) => request<Record<string, unknown>>(`/v1/reports/fx-revaluation${query(params)}`),
  cashView: (params: { period: string; sbu?: string }) => request<Record<string, unknown>>(`/v1/reports/cash-view${query(params)}`),
}

export type PeriodSummary = { id: string; period_ref: string; state: string; close_gate_summary: { satisfied: number; required: number } }
export type PeriodDetail = { id: string; period_ref: string; state: string; close_gates: CloseGate[] }

/** Read-only reuse of the already-frozen M4 Period API (§12.6) to surface Close-Gate status here — no new backend endpoint. */
export const periodsApi = {
  list: () => request<{ periods: PeriodSummary[] }>('/v1/periods?limit=100'),
  show: (id: string) => request<{ period: PeriodDetail }>(`/v1/periods/${id}`),
}

export const reportRunsApi = {
  list: (params: { report_type?: string; period?: string; state?: string } = {}) => request<{ report_runs: ReportRun[]; page: Page }>(`/v1/report-runs${query(params)}`),
  show: (id: string) => request<{ report_run: ReportRun }>(`/v1/report-runs/${id}`),
  generate: (input: ReportRunGenerateInput) => request<ReportRunCommandResult>('/v1/report-runs', { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(input) }),
  approve: (run: ReportRun) => request<ReportRunCommandResult>(`/v1/report-runs/${run.id}/approve`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(run.version) }, body: '{}' }),
  export: async (run: ReportRun, format: 'pdf' | 'csv') => {
    const response = await fetch(`/v1/report-runs/${run.id}/export?format=${format}`, { headers: authHeaders() })
    if (!response.ok) { const error = (await response.json().catch(() => ({}))) as { message?: string; error_code?: string }; throw new ApiRequestError(error.message ?? 'Export failed.', response.status, error.error_code) }
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${run.report_type}_${run.as_of ?? run.period_ref ?? run.id}.${format}`
    link.click()
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
  },
}
