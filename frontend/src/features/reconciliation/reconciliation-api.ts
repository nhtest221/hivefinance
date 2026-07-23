import { ApiRequestError } from '@/features/identity/auth-api'

export type Money = { amount: string; currency: string }
export type Page = { limit: number; next_cursor: string | null }
export type Approval = { id: string; status: 'pending'; version: number }
export type CommandResult<T> = { approval?: Approval } & Partial<T>

export type ReconciliationAccount = {
  id: string
  entity_id: string
  ledger_account_id: string
  currency: string
  display_name: string
  masked_bank_identifier: string | null
  reconciliation_enabled: boolean
  column_mapping: Record<string, string> | null
  version: number
}
export type ReconciliationAccountInput = { ledger_account_id: string; currency: string; display_name: string; masked_bank_identifier?: string; reconciliation_enabled?: boolean }

export type StatementLineStatus = 'Unreconciled' | 'Suggested' | 'Matched' | 'Reconciled' | 'Unexplained'
export type MatchSuggestion = { suggestion_id?: string; allocation_ids: string[]; total_amount: Money; rank: number; reference_match: boolean }
export type StatementLine = {
  id: string
  reconciliation_id: string
  source_line_identity: string
  transaction_date: string
  narration: string
  amount: Money
  external_bank_reference: string | null
  status: StatementLineStatus
  matched_allocation_ids: string[] | null
  resolved_by_journal_entry_id: string | null
  version: number
}
export type SuggestionLine = { line_id: string; status: StatementLineStatus; version: number; suggestions: MatchSuggestion[] }

export type BankReconciliationState = 'Draft' | 'InProgress' | 'PendingCompletionApproval' | 'Completed' | 'Reopened'
export type BankReconciliation = {
  id: string
  entity_id: string
  reconciliation_account_id: string
  period_ref: string
  opening_balance: string
  closing_balance: string
  state: BankReconciliationState
  source_data_watermark: string | null
  content_hash: string | null
  opened_by: string
  completed_by: string | null
  completed_at: string | null
  reopened_by: string | null
  reopened_at: string | null
  version: number
  lines?: StatementLine[]
}
export type OpenReconciliationInput = { reconciliation_account_id: string; period_ref: string; opening_balance: string; closing_balance: string }
export type ImportLineInput = { source_line_identity: string; transaction_date: string; narration: string; amount: Money; external_bank_reference?: string }
export type ImportStatementInput = { file_hash: string; column_mapping?: Record<string, string>; lines: ImportLineInput[] }
export type ImportResult = { reconciliation: BankReconciliation; imported: number; conflicts: { source_line_identity: string; reason: string }[] }
export type MatchSuggestionsResult = { suggested: number; unexplained: number; lines: SuggestionLine[] }

function commandId() { return crypto.randomUUID() }

function authHeaders(extra: Record<string, string> = {}) {
  return { Accept: 'application/json', Authorization: `Bearer ${sessionStorage.getItem('hivefinance.auth_token') ?? ''}`, 'X-Entity-Id': sessionStorage.getItem('hivefinance.entity_id') ?? '', 'X-Correlation-Id': commandId(), ...extra }
}

async function request<T>(path: string, init: RequestInit = {}) {
  const response = await fetch(path, { ...init, headers: { ...authHeaders({ 'Content-Type': 'application/json' }), ...init.headers } })
  const data = (await response.json().catch(() => ({}))) as Record<string, unknown> & { message?: string; error_code?: string }
  if (!response.ok) throw new ApiRequestError(data.message ?? 'The reconciliation request failed.', response.status, data.error_code)
  return data as Record<string, unknown> & T
}

function query(params: Record<string, string | undefined | null>) {
  const entries = Object.entries(params).filter((entry): entry is [string, string] => entry[1] !== undefined && entry[1] !== null && entry[1] !== '')

  return entries.length === 0 ? '' : '?'+entries.map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&')
}

export const reconciliationAccountsApi = {
  configure: (input: ReconciliationAccountInput) => request<{ reconciliation_account: ReconciliationAccount }>('/v1/reconciliation-accounts', { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(input) }),
  update: (account: ReconciliationAccount, patch: Partial<ReconciliationAccountInput> & { column_mapping?: Record<string, string> | null }) => request<{ reconciliation_account: ReconciliationAccount }>(`/v1/reconciliation-accounts/${account.id}`, { method: 'PATCH', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(account.version) }, body: JSON.stringify(patch) }),
  show: (id: string) => request<{ reconciliation_account: ReconciliationAccount }>(`/v1/reconciliation-accounts/${id}`),
  list: (params: { limit?: string; cursor?: string } = {}) => request<{ reconciliation_accounts: ReconciliationAccount[]; page: Page }>(`/v1/reconciliation-accounts${query(params)}`),
}

export const reconciliationsApi = {
  open: (input: OpenReconciliationInput) => request<{ reconciliation: BankReconciliation }>('/v1/reconciliations', { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(input) }),
  show: (id: string) => request<{ reconciliation: BankReconciliation }>(`/v1/reconciliations/${id}`),
  list: (params: { bank_account_id?: string; state?: string } = {}) => request<{ reconciliations: BankReconciliation[]; page: Page }>(`/v1/reconciliations${query(params)}`),
  import: (id: string, input: ImportStatementInput) => request<ImportResult>(`/v1/reconciliations/${id}/import`, { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(input) }),
  generateMatchSuggestions: (id: string) => request<MatchSuggestionsResult>(`/v1/reconciliations/${id}/match-suggestions`, { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: '{}' }),
  unmatched: (id: string) => request<{ lines: StatementLine[] }>(`/v1/reconciliations/${id}/unmatched`),
  matchLine: (reconciliationId: string, line: StatementLine, allocationIds: string[], lineIds?: string[]) => request<{ line: StatementLine }>(`/v1/reconciliations/${reconciliationId}/lines/${line.id}/match`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(line.version) }, body: JSON.stringify({ allocation_ids: allocationIds, ...(lineIds ? { line_ids: lineIds } : {}) }) }),
  confirmMatch: (reconciliationId: string, line: StatementLine) => request<{ line: StatementLine }>(`/v1/reconciliations/${reconciliationId}/lines/${line.id}/confirm`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(line.version) }, body: '{}' }),
  createBankEntry: (reconciliationId: string, line: StatementLine, offsetAccountId: string, narration?: string) => request<CommandResult<{ line: StatementLine }>>(`/v1/reconciliations/${reconciliationId}/lines/${line.id}/bank-entry`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(line.version) }, body: JSON.stringify({ offset_account_id: offsetAccountId, narration }) }),
  complete: (reconciliation: BankReconciliation) => request<CommandResult<{ reconciliation: BankReconciliation }>>(`/v1/reconciliations/${reconciliation.id}/complete`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(reconciliation.version) }, body: '{}' }),
  reopen: (reconciliation: BankReconciliation) => request<CommandResult<{ reconciliation: BankReconciliation }>>(`/v1/reconciliations/${reconciliation.id}/reopen`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(reconciliation.version) }, body: '{}' }),
  export: async (reconciliation: BankReconciliation, format: 'pdf' | 'csv') => {
    const response = await fetch(`/v1/reconciliations/${reconciliation.id}/statement?format=${format}`, { headers: authHeaders() })
    if (!response.ok) { const error = (await response.json().catch(() => ({}))) as { message?: string; error_code?: string }; throw new ApiRequestError(error.message ?? 'Export failed.', response.status, error.error_code) }
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `reconciliation_${reconciliation.period_ref}_${reconciliation.id}.${format}`
    link.click()
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
  },
}

/** Generic durable-approval commit (§3) — required here because M6's three mandatory
 * commands always return 202 pending_approval; a different checker must complete this step. */
export const approvalsApi = {
  approve: (approvalId: string, expectedVersion: number) => request<{ approval: Approval; command_result: { status: number; body: Record<string, unknown> } }>(`/v1/approvals/${approvalId}/approve`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(expectedVersion) }, body: '{}' }),
}
