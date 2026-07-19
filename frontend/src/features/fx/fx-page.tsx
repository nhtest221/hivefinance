import { type FormEvent, useEffect, useState } from 'react'
import { Alert, Button, Card, CardContent, CardHeader, Input, Table, TableCell, TableHead, TableHeader, TableRow } from '@/design-system'
import { addRate, listRates, type RateRecord } from './fx-api'
import { hasPermission } from '@/features/identity/permissions'

export function FxPage() {
  const canRead = hasPermission('fx.rates.read'); const canManage = hasPermission('fx.rates.manage')
  const [rates, setRates] = useState<RateRecord[]>([]); const [base, setBase] = useState(''); const [quote, setQuote] = useState(''); const [rate, setRate] = useState(''); const [date, setDate] = useState(new Date().toISOString().slice(0, 10)); const [source, setSource] = useState(''); const [message, setMessage] = useState<string | null>(null)
  const load = () => listRates().then((data) => setRates(data.rate_records)).catch(() => setMessage('Unable to load FX rate records.'))
  useEffect(() => { if (canRead) void load() }, [canRead])
  async function submit(event: FormEvent) { event.preventDefault(); setMessage(null); try { const result = await addRate({ base_currency: base.toUpperCase(), quote_currency: quote.toUpperCase(), rate, effective_date: date, source, is_override: false, override_reason: null }); if ('approval' in result) { setMessage(`Approval ${result.approval.id} is ${result.approval.status}.`) } else { setMessage('RateRecord added.'); await load() } } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to add rate.') } }
  if (!canRead) return <main className="p-6"><Alert>You do not have permission to view FX rates.</Alert></main>
  return <main className="p-6"><div className="mx-auto max-w-5xl space-y-5"><div><h1 className="text-2xl font-semibold">Currency &amp; FX</h1><p className="text-sm text-[var(--color-text-muted)]">Append-only effective-dated rates and immutable reference status.</p></div>
    {canManage ? <Card><CardHeader><h2 className="font-semibold">Add configured rate</h2></CardHeader><CardContent><form className="grid gap-3 md:grid-cols-6" onSubmit={submit}><Input aria-label="Base currency" placeholder="Base" maxLength={3} value={base} onChange={(e) => setBase(e.target.value)} required /><Input aria-label="Quote currency" placeholder="Quote" maxLength={3} value={quote} onChange={(e) => setQuote(e.target.value)} required /><Input aria-label="Rate" placeholder="Exact rate" value={rate} onChange={(e) => setRate(e.target.value)} required /><Input aria-label="Effective date" type="date" value={date} onChange={(e) => setDate(e.target.value)} required /><Input aria-label="Source" placeholder="Configured source" value={source} onChange={(e) => setSource(e.target.value)} required /><Button type="submit">Add rate</Button></form></CardContent></Card> : null}
    {message ? <Alert>{message}</Alert> : null}
    <Card><CardContent><Table><TableHeader><TableRow><TableHead>Pair</TableHead><TableHead>Rate</TableHead><TableHead>Effective</TableHead><TableHead>Source</TableHead><TableHead>Referenced</TableHead></TableRow></TableHeader><tbody>{rates.map((item) => <TableRow key={item.id}><TableCell>{item.base_currency}/{item.quote_currency}</TableCell><TableCell>{item.rate}</TableCell><TableCell>{item.effective_date}</TableCell><TableCell>{item.source}</TableCell><TableCell>{item.referenced ? 'Yes' : 'No'}</TableCell></TableRow>)}</tbody></Table></CardContent></Card>
  </div></main>
}
