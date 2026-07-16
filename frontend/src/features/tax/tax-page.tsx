import { type FormEvent, useEffect, useState } from 'react'
import { Alert, Button, Card, CardContent, CardHeader, Input, Table, TableCell, TableHead, TableHeader, TableRow } from '@/design-system'
import { createTaxCode, listTaxCodes, type TaxCode } from './tax-api'

export function TaxPage() {
  const [codes, setCodes] = useState<TaxCode[]>([]); const [code, setCode] = useState(''); const [name, setName] = useState(''); const [jurisdiction, setJurisdiction] = useState(''); const [message, setMessage] = useState<string | null>(null)
  const load = () => listTaxCodes().then((data) => setCodes(data.tax_codes)).catch(() => setMessage('Unable to load tax codes.'))
  useEffect(() => { void load() }, [])
  async function submit(event: FormEvent) { event.preventDefault(); setMessage(null); try { const result = await createTaxCode({ code, name, jurisdiction }); setMessage(`Approval ${result.approval.id} is ${result.approval.status}.`); setCode(''); setName('') } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to submit tax code.') } }
  return <main className="p-6"><div className="mx-auto max-w-5xl space-y-5"><div><h1 className="text-2xl font-semibold">Tax configuration</h1><p className="text-sm text-[var(--color-text-muted)]">Effective-dated codes and TaxPack inputs. Changes require four-eyes approval.</p></div>
    <Card><CardHeader><h2 className="font-semibold">Define tax code</h2></CardHeader><CardContent><form className="grid gap-3 md:grid-cols-4" onSubmit={submit}><Input aria-label="Code" placeholder="Code" value={code} onChange={(e) => setCode(e.target.value)} required /><Input aria-label="Name" placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} required /><Input aria-label="Jurisdiction" placeholder="Configured jurisdiction" value={jurisdiction} onChange={(e) => setJurisdiction(e.target.value)} required /><Button type="submit">Request approval</Button></form></CardContent></Card>
    {message ? <Alert>{message}</Alert> : null}
    <Card><CardContent><Table><TableHeader><TableRow><TableHead>Code</TableHead><TableHead>Name</TableHead><TableHead>Jurisdiction</TableHead><TableHead>Status</TableHead><TableHead>Version</TableHead></TableRow></TableHeader><tbody>{codes.map((item) => <TableRow key={item.id}><TableCell>{item.code}</TableCell><TableCell>{item.name}</TableCell><TableCell>{item.jurisdiction}</TableCell><TableCell>{item.status}</TableCell><TableCell>{item.version}</TableCell></TableRow>)}</tbody></Table></CardContent></Card>
  </div></main>
}
