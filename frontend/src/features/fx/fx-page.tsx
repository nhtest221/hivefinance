import { type FormEvent, useEffect, useState } from 'react'
import { Alert, Badge, Button, Card, CardContent, CardHeader, EmptyState, Input, LoadingState, PageHeader, Table, TableCell, TableHead, TableHeader, TableRow } from '@/design-system'
import { AppLayout } from '@/layouts/app-layout'
import { addRate, listRates, type RateRecord } from './fx-api'
import { hasPermission } from '@/features/identity/permissions'

export function FxPage() {
  const canRead = hasPermission('fx.rates.read'); const canManage = hasPermission('fx.rates.manage')
  const [rates, setRates] = useState<RateRecord[]>([]); const [loading, setLoading] = useState(true); const [base, setBase] = useState(''); const [quote, setQuote] = useState(''); const [rate, setRate] = useState(''); const [date, setDate] = useState(new Date().toISOString().slice(0, 10)); const [source, setSource] = useState(''); const [message, setMessage] = useState<string | null>(null)
  const load = () => { setLoading(true); return listRates().then((data) => { setRates(data.rate_records) }).catch(() => { setMessage('Unable to load FX rate records.') }).finally(() => { setLoading(false) }) }
  useEffect(() => { if (canRead) void load() }, [canRead])
  async function submit(event: FormEvent) { event.preventDefault(); setMessage(null); try { const result = await addRate({ base_currency: base.toUpperCase(), quote_currency: quote.toUpperCase(), rate, effective_date: date, source, is_override: false, override_reason: null }); if ('approval' in result) { setMessage(`Approval ${result.approval.id} is ${result.approval.status}. Share this id with an approver on the Approvals page.`) } else { setMessage('RateRecord added.'); await load() } } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to add rate.') } }

  if (!canRead) {
    return (
      <AppLayout>
        <PageHeader title="Currency & FX" description="Append-only effective-dated rates and immutable reference status." />
        <div className="p-4 lg:p-6"><Alert>You do not have permission to view FX rates.</Alert></div>
      </AppLayout>
    )
  }

  return (
    <AppLayout>
      <PageHeader title="Currency & FX" description="Append-only effective-dated rates and immutable reference status." />
      <div className="space-y-4 p-4 lg:p-6">
        {canManage ? <Card><CardHeader><h2 className="font-semibold">Add configured rate</h2></CardHeader><CardContent><form className="grid gap-3 md:grid-cols-6" onSubmit={submit}><Input aria-label="Base currency" placeholder="Base" maxLength={3} value={base} onChange={(e) => setBase(e.target.value)} required /><Input aria-label="Quote currency" placeholder="Quote" maxLength={3} value={quote} onChange={(e) => setQuote(e.target.value)} required /><Input aria-label="Rate" placeholder="Exact rate" value={rate} onChange={(e) => setRate(e.target.value)} required /><Input aria-label="Effective date" type="date" value={date} onChange={(e) => setDate(e.target.value)} required /><Input aria-label="Source" placeholder="Configured source" value={source} onChange={(e) => setSource(e.target.value)} required /><Button type="submit">Add rate</Button></form></CardContent></Card> : null}
        {message ? <Alert>{message}</Alert> : null}
        <Card className="overflow-hidden"><CardContent className="p-0">
          {loading ? <div className="p-6"><LoadingState label="Loading FX rates" /></div>
            : rates.length === 0 ? <div className="p-6"><EmptyState title="No rate records yet" description="Add your first configured rate using the form above." /></div>
            : <Table><TableHeader><TableRow><TableHead>Pair</TableHead><TableHead>Rate</TableHead><TableHead>Effective</TableHead><TableHead>Source</TableHead><TableHead>Referenced</TableHead></TableRow></TableHeader><tbody>{rates.map((item) => <TableRow key={item.id}><TableCell className="font-medium">{item.base_currency}/{item.quote_currency}</TableCell><TableCell className="tabular-nums">{item.rate}</TableCell><TableCell className="text-[var(--color-text-muted)]">{item.effective_date}</TableCell><TableCell>{item.source}</TableCell><TableCell><Badge variant={item.referenced ? 'success' : 'neutral'}>{item.referenced ? 'Referenced' : 'Unreferenced'}</Badge></TableCell></TableRow>)}</tbody></Table>}
        </CardContent></Card>
      </div>
    </AppLayout>
  )
}
