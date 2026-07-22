import { ApiRequestError } from '@/features/identity/auth-api'

export type Money = { amount: string; currency: string }
export type Page = { limit: number; next_cursor: string | null }
export type Approval = { id: string; status: 'pending'; version: number }
export type NoteLine = { id: string; source_line_id: string; description: string | null; net_amount: Money; tax_snapshot: unknown; tax_amount: Money; total_amount: Money }
export type Note = {
  id: string
  party_type: string
  document_type: string
  party_id: string
  source_document_id: string
  document_number: string | null
  note_date: string
  currency: string
  reason_code: string
  posted_amount: Money
  applied_amount: Money
  refunded_amount: Money
  held_remaining_amount: Money
  undisposed_amount: Money
  state: 'draft' | 'posted' | 'reversed'
  version: number
  provisional_token?: string | null
  narrative: string | null
  lines: NoteLine[]
  applications?: Array<{ document_id: string; amount: Money }>
  held_credit_sources?: unknown[]
  journal_entry_ids?: string[]
  reversal?: { id: string; original_note_id: string; reversal_date: string } | null
}
export type NoteLineInput = { source_line_id: string; description?: string | null; net_amount: Money }
export type NoteCreateInput = { party_type: string; document_type: string; party_id: string; source_document_id: string; source_document_expected_version: number; note_date: string; reason_code: string; narrative?: string | null; lines: NoteLineInput[] }
export type NoteUpdateInput = Partial<NoteCreateInput>
export type NoteApplyInput = { application_date: string; source: 'undisposed' | 'held'; allocations: Array<{ document_id: string; amount: Money; expected_version: number }>; credit_sources?: Array<{ credit_tranche_id: string; amount: Money; expected_version: number }> }
export type NoteHoldInput = { hold_date: string; amount: Money }
export type NoteRefundInput = { refund_date: string; bank_account_id: string; refund_amount: Money; expected_available_balance: Money; rate_record_id?: string | null; credit_sources: Array<{ credit_tranche_id: string; amount: Money; expected_version: number }> }
export type NoteReverseInput = { reversal_date: string; reason_code: string; narrative: string; document_versions: Array<{ document_id: string; expected_version: number }>; credit_source_versions: Array<{ credit_tranche_id: string; expected_version: number }> }
/** Client-side normalized shape: the wire response keys its note as `credit_note`/`debit_note`, folded here to `note` so both note kinds share one handler. */
export type NoteCommandResult = { note?: Note; approval?: Approval; [extra: string]: unknown }

function commandId() { return crypto.randomUUID() }

async function request<T>(path: string, init: RequestInit = {}) {
  const response = await fetch(path, { ...init, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${sessionStorage.getItem('hivefinance.auth_token') ?? ''}`, 'X-Entity-Id': sessionStorage.getItem('hivefinance.entity_id') ?? '', 'X-Correlation-Id': commandId(), ...init.headers } })
  const data = (await response.json().catch(() => ({}))) as Record<string, unknown> & { message?: string; error_code?: string }
  if (!response.ok) throw new ApiRequestError(data.message ?? 'The note request failed.', response.status, data.error_code)
  return data as Record<string, unknown> & T
}

function fold(resourceKey: 'credit_note' | 'debit_note', data: Record<string, unknown>): NoteCommandResult {
  const { [resourceKey]: note, ...rest } = data
  return { ...rest, note: note as Note | undefined }
}

export type NoteApi = ReturnType<typeof noteEndpoints>

function noteEndpoints(kind: 'credit-notes' | 'debit-notes', resourceKey: 'credit_note' | 'debit_note', listKey: 'credit_notes' | 'debit_notes') {
  return {
    list: async (params: Record<string, string> = {}) => {
      const data = await request<{ page: Page }>(`/v1/${kind}?limit=100${Object.entries(params).map(([k, v]) => `&${k}=${encodeURIComponent(v)}`).join('')}`)
      return { notes: (data[listKey] as Note[] | undefined) ?? [], page: data.page }
    },
    show: async (id: string) => fold(resourceKey, await request(`/v1/${kind}/${id}`)),
    create: async (input: NoteCreateInput) => fold(resourceKey, await request(`/v1/${kind}`, { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(input) })),
    update: async (note: Note, input: NoteUpdateInput) => fold(resourceKey, await request(`/v1/${kind}/${note.id}`, { method: 'PATCH', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(note.version) }, body: JSON.stringify(input) })),
    post: async (note: Note) => fold(resourceKey, await request(`/v1/${kind}/${note.id}/post`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(note.version) }, body: '{}' })),
    hold: async (note: Note, input: NoteHoldInput) => fold(resourceKey, await request(`/v1/${kind}/${note.id}/hold`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(note.version) }, body: JSON.stringify(input) })),
    apply: async (note: Note, input: NoteApplyInput) => fold(resourceKey, await request(`/v1/${kind}/${note.id}/apply`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(note.version) }, body: JSON.stringify(input) })),
    refund: async (note: Note, input: NoteRefundInput) => fold(resourceKey, await request(`/v1/${kind}/${note.id}/refund`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(note.version) }, body: JSON.stringify(input) })),
    reverse: async (note: Note, input: NoteReverseInput) => fold(resourceKey, await request(`/v1/${kind}/${note.id}/reverse`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(note.version) }, body: JSON.stringify(input) })),
  }
}

export const notesApi = {
  creditNotes: noteEndpoints('credit-notes', 'credit_note', 'credit_notes'),
  debitNotes: noteEndpoints('debit-notes', 'debit_note', 'debit_notes'),
}
