import { type FormEvent, useCallback, useEffect, useState } from 'react'

import { settlementApi, type Allocation, type CreditTranche } from './settlement-api'
import { Alert, Button, Card, CardContent, Input, Table, TableCell, TableHead, TableHeader, TableRow, Tabs, TabsContent, TabsList, TabsTrigger, Textarea } from '@/design-system'
import { hasPermission } from '@/features/identity/permissions'

function parseArray(value: string, label: string): unknown[] {
  const parsed = JSON.parse(value) as unknown
  if (!Array.isArray(parsed)) throw new Error(`${label} must be a JSON array.`)
  return parsed
}

function outcome(result: { approval?: { id: string; status: string }; receipt?: Allocation; payment?: Allocation; allocation?: Allocation }) {
  if (result.approval) return `Approval ${result.approval.id} is ${result.approval.status}; no settlement has posted yet.`
  const allocation = result.receipt ?? result.payment ?? result.allocation
  return allocation ? `Settlement ${allocation.allocation_number ?? allocation.id} posted.` : 'Settlement command completed.'
}

export function SettlementPage() {
  const [allocations, setAllocations] = useState<Allocation[]>([])
  const [tranches, setTranches] = useState<CreditTranche[]>([])
  const [message, setMessage] = useState<string | null>(null)
  const canRead = hasPermission('settlement.allocations.read')
  const canReceipt = hasPermission('settlement.receipts.create')
  const canPayment = hasPermission('settlement.payments.create')
  const canCredits = hasPermission('settlement.credits.read')
  const canApplyCredit = hasPermission('settlement.credits.apply')
  const canRefundCredit = hasPermission('settlement.credits.refund')
  const canReverse = hasPermission('settlement.allocations.reverse')

  const load = useCallback(async () => {
    if (!canRead) return
    try { setAllocations((await settlementApi.allocations()).allocations) } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to load allocations.') }
  }, [canRead])
  useEffect(() => { void load() }, [load])

  if (!canRead) return <main className="p-6"><Alert>You do not have permission to view Settlement.</Alert></main>

  async function reverse(allocation: Allocation) {
    try {
      const result = await settlementApi.reverse(allocation)
      setMessage(result.approval ? `Approval ${result.approval.id} is pending; the allocation remains posted.` : 'Allocation reversed.')
      await load()
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Reversal failed.') }
  }

  return <main className="p-6"><div className="mx-auto max-w-6xl space-y-5">
    <div><h1 className="text-2xl font-semibold">Settlement</h1><p className="text-sm text-[var(--color-text-muted)]">Receipts, payments, explicit party-credit tranches, and linked reversals.</p></div>
    {message ? <Alert>{message}</Alert> : null}
    <Tabs defaultValue="allocations"><TabsList><TabsTrigger value="allocations">Allocations</TabsTrigger><TabsTrigger value="cash">Receipts &amp; payments</TabsTrigger><TabsTrigger value="credits">Party credit</TabsTrigger></TabsList>
      <TabsContent value="allocations"><Card><CardContent><Table><TableHeader><TableRow><TableHead>Number</TableHead><TableHead>Operation</TableHead><TableHead>Party</TableHead><TableHead>Gross</TableHead><TableHead>Applied</TableHead><TableHead>Status</TableHead><TableHead>Action</TableHead></TableRow></TableHeader><tbody>{allocations.map((item) => <TableRow key={item.id}><TableCell>{item.allocation_number ?? item.id.slice(0, 8)}</TableCell><TableCell>{item.operation}</TableCell><TableCell>{item.party_type} · {item.party_id.slice(0, 8)}</TableCell><TableCell>{item.gross_amount.currency} {item.gross_amount.amount}</TableCell><TableCell>{item.allocated_amount.amount}</TableCell><TableCell>{item.state}</TableCell><TableCell>{canReverse && item.state === 'posted' && item.operation !== 'reversal' ? <Button variant="secondary" onClick={() => void reverse(item)}>Reverse</Button> : null}</TableCell></TableRow>)}</tbody></Table></CardContent></Card></TabsContent>
      <TabsContent value="cash"><div className="grid gap-4 lg:grid-cols-2">{canReceipt ? <CashForm kind="receipt" onDone={async (text) => { setMessage(text); await load() }} /> : <Alert>Receipt creation is not permitted.</Alert>}{canPayment ? <CashForm kind="payment" onDone={async (text) => { setMessage(text); await load() }} /> : <Alert>Payment creation is not permitted.</Alert>}</div></TabsContent>
      <TabsContent value="credits">{canCredits ? <CreditPanel tranches={tranches} setTranches={setTranches} canApply={canApplyCredit} canRefund={canRefundCredit} onMessage={setMessage} onDone={load} /> : <Alert>Party-credit access is not permitted.</Alert>}</TabsContent>
    </Tabs>
  </div></main>
}

function CashForm({ kind, onDone }: { kind: 'receipt' | 'payment'; onDone: (message: string) => Promise<void> }) {
  const [partyId, setPartyId] = useState(''); const [date, setDate] = useState(''); const [bankId, setBankId] = useState(''); const [currency, setCurrency] = useState('BDT'); const [gross, setGross] = useState(''); const [bank, setBank] = useState(''); const [withholding, setWithholding] = useState('0.0000'); const [unapplied, setUnapplied] = useState('0.0000'); const [rateId, setRateId] = useState(''); const [creditVersion, setCreditVersion] = useState('0'); const [links, setLinks] = useState('[]'); const [withholdingLines, setWithholdingLines] = useState('[]'); const [busy, setBusy] = useState(false)
  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true)
    try {
      const body: Record<string, unknown> = { [kind === 'receipt' ? 'customer_id' : 'vendor_id']: partyId, settlement_date: date, bank_account_id: bankId, gross_amount: { amount: gross, currency }, bank_amount: { amount: bank, currency }, withholding_amount: { amount: withholding, currency }, unapplied_amount: { amount: unapplied, currency }, rate_record_id: rateId || null, withholding_lines: parseArray(withholdingLines, 'Withholding lines'), allocations: parseArray(links, 'Allocations') }
      if (unapplied !== '0.0000' && unapplied !== '0') body.party_credit_expected_version = Number(creditVersion)
      const result = kind === 'receipt' ? await settlementApi.receipt(body) : await settlementApi.payment(body)
      await onDone(outcome(result))
    } catch (error) { await onDone(error instanceof Error ? error.message : 'Settlement failed.') } finally { setBusy(false) }
  }
  return <Card><CardContent><form className="space-y-3" onSubmit={(event) => void submit(event)}><h2 className="font-semibold">{kind === 'receipt' ? 'Record receipt' : 'Record payment'}</h2><Input aria-label="Party UUID" placeholder={`${kind === 'receipt' ? 'Customer' : 'Vendor'} UUID`} required value={partyId} onChange={(event) => setPartyId(event.target.value)} /><div className="grid grid-cols-2 gap-2"><Input aria-label="Settlement date" type="date" required value={date} onChange={(event) => setDate(event.target.value)} /><Input aria-label="Bank account UUID" placeholder="Bank account UUID" required value={bankId} onChange={(event) => setBankId(event.target.value)} /></div><div className="grid grid-cols-2 gap-2"><Input aria-label="Currency" placeholder="Currency" required value={currency} onChange={(event) => setCurrency(event.target.value.toUpperCase())} /><Input aria-label="RateRecord UUID" placeholder="RateRecord UUID (foreign only)" value={rateId} onChange={(event) => setRateId(event.target.value)} /></div><div className="grid grid-cols-2 gap-2"><Input aria-label="Gross amount" placeholder="Gross amount" required value={gross} onChange={(event) => setGross(event.target.value)} /><Input aria-label="Bank amount" placeholder="Bank amount" required value={bank} onChange={(event) => setBank(event.target.value)} /><Input aria-label="Withholding amount" placeholder="Withholding amount" required value={withholding} onChange={(event) => setWithholding(event.target.value)} /><Input aria-label="Unapplied amount" placeholder="Unapplied amount" required value={unapplied} onChange={(event) => setUnapplied(event.target.value)} /></div><Input aria-label="Party credit version" placeholder="Party-credit projection version" value={creditVersion} onChange={(event) => setCreditVersion(event.target.value)} /><label className="block text-sm">Document allocations JSON<Textarea value={links} onChange={(event) => setLinks(event.target.value)} /></label><label className="block text-sm">Withholding lines JSON<Textarea value={withholdingLines} onChange={(event) => setWithholdingLines(event.target.value)} /></label><Button disabled={busy} type="submit">{busy ? 'Submitting…' : 'Submit'}</Button></form></CardContent></Card>
}

function CreditPanel({ tranches, setTranches, canApply, canRefund, onMessage, onDone }: { tranches: CreditTranche[]; setTranches: (value: CreditTranche[]) => void; canApply: boolean; canRefund: boolean; onMessage: (value: string) => void; onDone: () => Promise<void> }) {
  const [partyId, setPartyId] = useState(''); const [partyType, setPartyType] = useState<'customer' | 'vendor'>('customer'); const [currency, setCurrency] = useState('BDT'); const [mode, setMode] = useState<'apply' | 'refund'>(canApply ? 'apply' : 'refund'); const [date, setDate] = useState(''); const [bankId, setBankId] = useState(''); const [rateId, setRateId] = useState(''); const [refundAmount, setRefundAmount] = useState(''); const [expectedBalance, setExpectedBalance] = useState(''); const [sources, setSources] = useState('[]'); const [links, setLinks] = useState('[]')
  async function inspect() { try { setTranches((await settlementApi.credits(partyId, partyType, currency)).credit_tranches) } catch (error) { onMessage(error instanceof Error ? error.message : 'Credit lookup failed.') } }
  async function submit(event: FormEvent) { event.preventDefault(); try { const creditSources = parseArray(sources, 'Credit sources'); const result = mode === 'apply' ? await settlementApi.applyCredit(partyId, { party_type: partyType, currency, application_date: date, credit_sources: creditSources, allocations: parseArray(links, 'Allocations') }) : await settlementApi.refundCredit(partyId, { party_type: partyType, refund_date: date, bank_account_id: bankId, refund_amount: { amount: refundAmount, currency }, expected_available_balance: { amount: expectedBalance, currency }, rate_record_id: rateId || null, credit_sources: creditSources }); onMessage(outcome(result)); await inspect(); await onDone() } catch (error) { onMessage(error instanceof Error ? error.message : 'Credit command failed.') } }
  return <div className="grid gap-4 lg:grid-cols-2"><Card><CardContent className="space-y-3"><h2 className="font-semibold">Available credit tranches</h2><div className="grid grid-cols-3 gap-2"><Input placeholder="Party UUID" value={partyId} onChange={(event) => setPartyId(event.target.value)} /><select className="rounded-md border px-2" value={partyType} onChange={(event) => setPartyType(event.target.value as 'customer' | 'vendor')}><option value="customer">Customer</option><option value="vendor">Vendor</option></select><Input placeholder="Currency" value={currency} onChange={(event) => setCurrency(event.target.value.toUpperCase())} /></div><Button variant="secondary" onClick={() => void inspect()}>Load named sources</Button><Table><TableHeader><TableRow><TableHead>Tranche</TableHead><TableHead>Source</TableHead><TableHead>Remaining</TableHead><TableHead>Version</TableHead></TableRow></TableHeader><tbody>{tranches.map((tranche) => <TableRow key={tranche.credit_tranche_id}><TableCell>{tranche.credit_tranche_id}</TableCell><TableCell>{tranche.source_reference ?? '—'}</TableCell><TableCell>{tranche.remaining_amount.amount} {tranche.currency}</TableCell><TableCell>{tranche.version}</TableCell></TableRow>)}</tbody></Table></CardContent></Card>{canApply || canRefund ? <Card><CardContent><form className="space-y-3" onSubmit={(event) => void submit(event)}><h2 className="font-semibold">Explicit credit {mode}</h2><select className="w-full rounded-md border px-2 py-2" value={mode} onChange={(event) => setMode(event.target.value as 'apply' | 'refund')}>{canApply ? <option value="apply">Apply to documents</option> : null}{canRefund ? <option value="refund">Refund to bank</option> : null}</select><Input type="date" required value={date} onChange={(event) => setDate(event.target.value)} /><label className="block text-sm">Credit sources JSON<Textarea value={sources} onChange={(event) => setSources(event.target.value)} /></label>{mode === 'apply' ? <label className="block text-sm">Document allocations JSON<Textarea value={links} onChange={(event) => setLinks(event.target.value)} /></label> : <><Input placeholder="Bank account UUID" value={bankId} onChange={(event) => setBankId(event.target.value)} /><Input placeholder="Refund amount" value={refundAmount} onChange={(event) => setRefundAmount(event.target.value)} /><Input placeholder="Expected available balance" value={expectedBalance} onChange={(event) => setExpectedBalance(event.target.value)} /><Input placeholder="Refund RateRecord UUID (foreign only)" value={rateId} onChange={(event) => setRateId(event.target.value)} /></>}<Button type="submit">Submit</Button></form></CardContent></Card> : <Alert>Credit application and refund commands are not permitted.</Alert>}</div>
}
