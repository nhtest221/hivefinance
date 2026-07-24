import { type FormEvent, useCallback, useEffect, useState } from 'react'

import { notesApi, type Note, type NoteApi, type NoteCommandResult } from './notes-api'
import { Alert, Badge, Button, Card, CardContent, CardHeader, EmptyState, Input, LoadingState, PageHeader, RepeatableRows, Table, TableCell, TableHead, TableHeader, TableRow, Tabs, TabsContent, TabsList, TabsTrigger } from '@/design-system'
import { AppLayout } from '@/layouts/app-layout'
import { hasPermission } from '@/features/identity/permissions'

const noteStateVariant: Record<Note['state'], 'neutral' | 'success' | 'danger'> = { draft: 'neutral', posted: 'success', reversed: 'danger' }

function outcome(result: NoteCommandResult, verb: string): string {
  if (result.approval) return `Approval ${result.approval.id} is ${result.approval.status}; the note has not changed yet.`
  return result.note ? `Note ${result.note.document_number ?? result.note.id.slice(0, 8)} ${verb}.` : 'Command completed.'
}

export function NotesPage() {
  return (
    <AppLayout>
      <PageHeader title="Notes" description="Credit and debit notes: correction drafting, posting, and disposition (apply, hold, refund, reverse)." />
      <div className="space-y-4 p-4 lg:p-6">
        <Tabs defaultValue="credit">
          <TabsList>
            <TabsTrigger value="credit">Credit notes</TabsTrigger>
            <TabsTrigger value="debit">Debit notes</TabsTrigger>
          </TabsList>
          <TabsContent value="credit"><NotePanel api={notesApi.creditNotes} partyLabel="Customer" sourceLabel="Invoice" prefix="receivables.credit_notes" /></TabsContent>
          <TabsContent value="debit"><NotePanel api={notesApi.debitNotes} partyLabel="Vendor" sourceLabel="Bill" prefix="payables.debit_notes" /></TabsContent>
        </Tabs>
      </div>
    </AppLayout>
  )
}

function NotePanel({ api, partyLabel, sourceLabel, prefix }: { api: NoteApi; partyLabel: string; sourceLabel: string; prefix: string }) {
  const canRead = hasPermission(`${prefix}.read`)
  const canCreate = hasPermission(`${prefix}.create`)
  const canPost = hasPermission(`${prefix}.post`)
  const canHold = hasPermission(`${prefix}.hold`)
  const canApply = hasPermission(`${prefix}.apply`)
  const canRefund = hasPermission(`${prefix}.refund`)
  const canReverse = hasPermission(`${prefix}.reverse`)
  const [notes, setNotes] = useState<Note[]>([])
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState<string | null>(null)
  const [selected, setSelected] = useState<Note | null>(null)

  const load = useCallback(async () => {
    if (!canRead) return
    setLoading(true)
    try { setNotes((await api.list()).notes) } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to load notes.') } finally { setLoading(false) }
  }, [canRead, api])
  useEffect(() => { void load() }, [load])

  if (!canRead) return <Alert>You do not have permission to view {partyLabel === 'Customer' ? 'credit' : 'debit'} notes.</Alert>

  async function post(note: Note) {
    try { const result = await api.post(note); setMessage(outcome(result, 'posted')); await load() } catch (error) { setMessage(error instanceof Error ? error.message : 'Post failed.') }
  }

  return <div className="space-y-4">
    {message ? <Alert>{message}</Alert> : null}
    {canCreate ? <NoteCreateForm api={api} partyLabel={partyLabel} sourceLabel={sourceLabel} onDone={async (text) => { setMessage(text); await load() }} /> : null}
    <Card className="overflow-hidden"><CardContent className="p-0">
      {loading ? <div className="p-6"><LoadingState label="Loading notes" /></div>
        : notes.length === 0 ? <div className="p-6"><EmptyState title="No notes yet" description="Draft your first note using the form above." /></div>
        : <Table><TableHeader><TableRow><TableHead>Number</TableHead><TableHead>Date</TableHead><TableHead>State</TableHead><TableHead className="text-right">Posted</TableHead><TableHead className="text-right">Applied</TableHead><TableHead className="text-right">Refunded</TableHead><TableHead className="text-right">Held</TableHead><TableHead className="text-right">Undisposed</TableHead><TableHead className="text-right">Action</TableHead></TableRow></TableHeader><tbody>{notes.map((n) => <TableRow key={n.id}><TableCell className="font-medium">{n.document_number ?? n.id.slice(0, 8)}</TableCell><TableCell className="text-[var(--color-text-muted)]">{n.note_date}</TableCell><TableCell><Badge variant={noteStateVariant[n.state]}>{n.state}</Badge></TableCell><TableCell className="text-right tabular-nums">{n.posted_amount.currency} {n.posted_amount.amount}</TableCell><TableCell className="text-right tabular-nums">{n.applied_amount.amount}</TableCell><TableCell className="text-right tabular-nums">{n.refunded_amount.amount}</TableCell><TableCell className="text-right tabular-nums">{n.held_remaining_amount.amount}</TableCell><TableCell className="text-right tabular-nums">{n.undisposed_amount.amount}</TableCell><TableCell className="text-right space-x-2">{n.state === 'draft' && canPost ? <Button variant="secondary" size="sm" onClick={() => void post(n)}>Post</Button> : null}{n.state === 'posted' && (canHold || canApply || canRefund || canReverse) ? <Button variant="secondary" size="sm" onClick={() => setSelected(n)}>Disposition</Button> : null}</TableCell></TableRow>)}</tbody></Table>}
    </CardContent></Card>
    {selected ? <NoteDispositionForm api={api} note={selected} canHold={canHold} canApply={canApply} canRefund={canRefund} canReverse={canReverse} onClose={() => setSelected(null)} onDone={async (text) => { setMessage(text); setSelected(null); await load() }} /> : null}
  </div>
}

type LineRow = { source_line_id: string; description: string; amount: string }

function NoteCreateForm({ api, partyLabel, sourceLabel, onDone }: { api: NoteApi; partyLabel: string; sourceLabel: string; onDone: (message: string) => Promise<void> }) {
  const [partyType, setPartyType] = useState(partyLabel === 'Customer' ? 'customer' : 'vendor')
  const [documentType, setDocumentType] = useState(sourceLabel.toLowerCase())
  const [partyId, setPartyId] = useState('')
  const [sourceDocumentId, setSourceDocumentId] = useState('')
  const [sourceVersion, setSourceVersion] = useState('1')
  const [noteDate, setNoteDate] = useState('')
  const [reasonCode, setReasonCode] = useState('')
  const [narrative, setNarrative] = useState('')
  const [lineCurrency, setLineCurrency] = useState('')
  const [lines, setLines] = useState<LineRow[]>([{ source_line_id: '', description: '', amount: '' }])
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true)
    try {
      const parsedLines = lines.map((line) => ({
        source_line_id: line.source_line_id,
        description: line.description || null,
        net_amount: { amount: line.amount, currency: lineCurrency },
      }))
      const result = await api.create({ party_type: partyType, document_type: documentType, party_id: partyId, source_document_id: sourceDocumentId, source_document_expected_version: Number(sourceVersion), note_date: noteDate, reason_code: reasonCode, narrative: narrative || null, lines: parsedLines })
      await onDone(outcome(result, 'created as a draft'))
    } catch (error) { await onDone(error instanceof Error ? error.message : 'Note creation failed.') } finally { setBusy(false) }
  }

  return <Card><CardHeader><h2 className="font-semibold">Draft a {partyLabel === 'Customer' ? 'credit' : 'debit'} note</h2></CardHeader><CardContent><form className="space-y-3" onSubmit={(event) => void submit(event)}>
    <div className="grid grid-cols-2 gap-2"><Input placeholder="Configured party_type" value={partyType} onChange={(event) => setPartyType(event.target.value)} required /><Input placeholder="Configured document_type" value={documentType} onChange={(event) => setDocumentType(event.target.value)} required /></div>
    <div className="grid grid-cols-2 gap-2"><Input placeholder={`${partyLabel} UUID`} value={partyId} onChange={(event) => setPartyId(event.target.value)} required /><Input placeholder={`${sourceLabel} UUID`} value={sourceDocumentId} onChange={(event) => setSourceDocumentId(event.target.value)} required /></div>
    <div className="grid grid-cols-3 gap-2"><Input type="date" value={noteDate} onChange={(event) => setNoteDate(event.target.value)} required /><Input placeholder="Source document expected version" value={sourceVersion} onChange={(event) => setSourceVersion(event.target.value)} required /><Input placeholder="Configured reason code" value={reasonCode} onChange={(event) => setReasonCode(event.target.value)} required /></div>
    <Input placeholder="Narrative (optional)" value={narrative} onChange={(event) => setNarrative(event.target.value)} />
    <Input placeholder={`Line currency (matches the ${sourceLabel.toLowerCase()}, e.g. USD)`} value={lineCurrency} onChange={(event) => setLineCurrency(event.target.value)} required className="w-64" />
    <RepeatableRows
      label="Lines"
      hint={`Each row corrects one line on the source ${sourceLabel.toLowerCase()}.`}
      value={lines}
      onChange={setLines}
      fields={[
        { key: 'source_line_id', label: 'Source line UUID', width: 'w-72' },
        { key: 'description', label: 'Description (optional)', width: 'w-56' },
        { key: 'amount', label: `Net amount (${lineCurrency || 'currency'})`, width: 'w-40' },
      ]}
      makeEmpty={() => ({ source_line_id: '', description: '', amount: '' })}
    />
    <Button disabled={busy} type="submit">{busy ? 'Submitting…' : 'Save draft'}</Button>
  </form></CardContent></Card>
}

type AllocationRow = { document_id: string; amount: string; expected_version: string }
type CreditSourceRow = { credit_tranche_id: string; amount: string; expected_version: string }
type DocumentVersionRow = { document_id: string; expected_version: string }
type CreditSourceVersionRow = { credit_tranche_id: string; expected_version: string }

function NoteDispositionForm({ api, note, canHold, canApply, canRefund, canReverse, onClose, onDone }: { api: NoteApi; note: Note; canHold: boolean; canApply: boolean; canRefund: boolean; canReverse: boolean; onClose: () => void; onDone: (message: string) => Promise<void> }) {
  const firstMode = canHold ? 'hold' : canApply ? 'apply' : canRefund ? 'refund' : 'reverse'
  const [mode, setMode] = useState<'hold' | 'apply' | 'refund' | 'reverse'>(firstMode)
  const [date, setDate] = useState('')
  const [amount, setAmount] = useState('')
  const [source, setSource] = useState<'undisposed' | 'held'>('undisposed')
  const [allocations, setAllocations] = useState<AllocationRow[]>([])
  const [creditSources, setCreditSources] = useState<CreditSourceRow[]>([])
  const [bankAccountId, setBankAccountId] = useState('')
  const [refundAmount, setRefundAmount] = useState('')
  const [expectedBalance, setExpectedBalance] = useState('')
  const [rateRecordId, setRateRecordId] = useState('')
  const [reasonCode, setReasonCode] = useState('')
  const [narrative, setNarrative] = useState('')
  const [documentVersions, setDocumentVersions] = useState<DocumentVersionRow[]>([])
  const [creditSourceVersions, setCreditSourceVersions] = useState<CreditSourceVersionRow[]>([])
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true)
    try {
      const result = mode === 'hold'
        ? await api.hold(note, { hold_date: date, amount: { amount, currency: note.currency } })
        : mode === 'apply'
          ? await api.apply(note, {
              application_date: date,
              source,
              allocations: allocations.map((row) => ({ document_id: row.document_id, amount: { amount: row.amount, currency: note.currency }, expected_version: Number(row.expected_version) })),
              credit_sources: creditSources.map((row) => ({ credit_tranche_id: row.credit_tranche_id, amount: { amount: row.amount, currency: note.currency }, expected_version: Number(row.expected_version) })),
            })
          : mode === 'refund'
            ? await api.refund(note, {
                refund_date: date,
                bank_account_id: bankAccountId,
                refund_amount: { amount: refundAmount, currency: note.currency },
                expected_available_balance: { amount: expectedBalance, currency: note.currency },
                rate_record_id: rateRecordId || null,
                credit_sources: creditSources.map((row) => ({ credit_tranche_id: row.credit_tranche_id, amount: { amount: row.amount, currency: note.currency }, expected_version: Number(row.expected_version) })),
              })
            : await api.reverse(note, {
                reversal_date: date,
                reason_code: reasonCode,
                narrative,
                document_versions: documentVersions.map((row) => ({ document_id: row.document_id, expected_version: Number(row.expected_version) })),
                credit_source_versions: creditSourceVersions.map((row) => ({ credit_tranche_id: row.credit_tranche_id, expected_version: Number(row.expected_version) })),
              })
      await onDone(outcome(result, mode === 'hold' ? 'held' : mode === 'apply' ? 'applied' : mode === 'refund' ? 'refunded' : 'reversed'))
    } catch (error) { await onDone(error instanceof Error ? error.message : 'Disposition command failed.') } finally { setBusy(false) }
  }

  return <Card><CardHeader><h2 className="font-semibold">Disposition — {note.document_number ?? note.id.slice(0, 8)}</h2></CardHeader><CardContent><form className="space-y-3" onSubmit={(event) => void submit(event)}>
    <select className="w-full rounded-md border px-2 py-2" value={mode} onChange={(event) => setMode(event.target.value as typeof mode)}>
      {canHold ? <option value="hold">Hold</option> : null}{canApply ? <option value="apply">Apply</option> : null}{canRefund ? <option value="refund">Refund</option> : null}{canReverse ? <option value="reverse">Reverse</option> : null}
    </select>
    <Input type="date" value={date} onChange={(event) => setDate(event.target.value)} required />
    {mode === 'hold' ? <Input placeholder={`Amount (${note.currency})`} value={amount} onChange={(event) => setAmount(event.target.value)} required /> : null}
    {mode === 'apply' ? <>
      <select className="w-full rounded-md border px-2 py-2" value={source} onChange={(event) => setSource(event.target.value as 'undisposed' | 'held')}><option value="undisposed">From undisposed balance</option><option value="held">From held credit sources</option></select>
      <RepeatableRows
        label="Allocations"
        hint="One row per target document this note is applied against."
        value={allocations}
        onChange={setAllocations}
        fields={[
          { key: 'document_id', label: 'Document UUID', width: 'w-72' },
          { key: 'amount', label: `Amount (${note.currency})`, width: 'w-36' },
          { key: 'expected_version', label: 'Expected version', width: 'w-32' },
        ]}
        makeEmpty={() => ({ document_id: '', amount: '', expected_version: '1' })}
      />
      <RepeatableRows
        label="Credit sources (optional)"
        hint="Only needed when applying from held credit sources."
        value={creditSources}
        onChange={setCreditSources}
        fields={[
          { key: 'credit_tranche_id', label: 'Credit tranche UUID', width: 'w-72' },
          { key: 'amount', label: `Amount (${note.currency})`, width: 'w-36' },
          { key: 'expected_version', label: 'Expected version', width: 'w-32' },
        ]}
        makeEmpty={() => ({ credit_tranche_id: '', amount: '', expected_version: '1' })}
      />
    </> : null}
    {mode === 'refund' ? <>
      <Input placeholder="Bank account UUID" value={bankAccountId} onChange={(event) => setBankAccountId(event.target.value)} required />
      <div className="grid grid-cols-2 gap-2"><Input placeholder={`Refund amount (${note.currency})`} value={refundAmount} onChange={(event) => setRefundAmount(event.target.value)} required /><Input placeholder="Expected available balance" value={expectedBalance} onChange={(event) => setExpectedBalance(event.target.value)} required /></div>
      <Input placeholder="Refund RateRecord UUID (foreign only)" value={rateRecordId} onChange={(event) => setRateRecordId(event.target.value)} />
      <RepeatableRows
        label="Credit sources"
        hint="Held credit tranches this refund draws from."
        value={creditSources}
        onChange={setCreditSources}
        fields={[
          { key: 'credit_tranche_id', label: 'Credit tranche UUID', width: 'w-72' },
          { key: 'amount', label: `Amount (${note.currency})`, width: 'w-36' },
          { key: 'expected_version', label: 'Expected version', width: 'w-32' },
        ]}
        makeEmpty={() => ({ credit_tranche_id: '', amount: '', expected_version: '1' })}
      />
    </> : null}
    {mode === 'reverse' ? <>
      <Input placeholder="Configured reason code" value={reasonCode} onChange={(event) => setReasonCode(event.target.value)} required />
      <Input placeholder="Narrative" value={narrative} onChange={(event) => setNarrative(event.target.value)} required />
      <RepeatableRows
        label="Document versions"
        hint="Leave empty if no applications need to be reversed alongside the note."
        value={documentVersions}
        onChange={setDocumentVersions}
        fields={[
          { key: 'document_id', label: 'Document UUID', width: 'w-72' },
          { key: 'expected_version', label: 'Expected version', width: 'w-32' },
        ]}
        makeEmpty={() => ({ document_id: '', expected_version: '1' })}
      />
      <RepeatableRows
        label="Credit source versions"
        hint="Leave empty if no held credit tranches need to be reversed alongside the note."
        value={creditSourceVersions}
        onChange={setCreditSourceVersions}
        fields={[
          { key: 'credit_tranche_id', label: 'Credit tranche UUID', width: 'w-72' },
          { key: 'expected_version', label: 'Expected version', width: 'w-32' },
        ]}
        makeEmpty={() => ({ credit_tranche_id: '', expected_version: '1' })}
      />
    </> : null}
    {mode === 'reverse' ? <p className="text-xs text-[var(--color-danger)]">Reversing a posted note cannot be undone. It creates a linked reversal and restores any consumed value.</p> : null}
    <div className="flex gap-2"><Button disabled={busy} type="submit" variant={mode === 'reverse' ? 'danger' : 'primary'}>{busy ? 'Submitting…' : mode === 'reverse' ? 'Reverse note' : 'Submit'}</Button><Button type="button" variant="secondary" onClick={onClose}>Cancel</Button></div>
  </form></CardContent></Card>
}
