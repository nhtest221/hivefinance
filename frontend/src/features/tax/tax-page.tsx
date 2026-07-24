import { type FormEvent, useEffect, useState } from 'react'
import { Alert, Badge, Button, Card, CardContent, CardHeader, EmptyState, Input, LoadingState, PageHeader, Table, TableCell, TableHead, TableHeader, TableRow } from '@/design-system'
import { AppLayout } from '@/layouts/app-layout'
import { createTaxCode, listTaxCodes, type TaxCode } from './tax-api'
import { hasPermission } from '@/features/identity/permissions'

export function TaxPage() {
  const canRead = hasPermission('tax.codes.read'); const canManage = hasPermission('tax.codes.manage')
  const [codes, setCodes] = useState<TaxCode[]>([]); const [loading, setLoading] = useState(true); const [code, setCode] = useState(''); const [name, setName] = useState(''); const [jurisdiction, setJurisdiction] = useState(''); const [message, setMessage] = useState<string | null>(null)
  const load = () => { setLoading(true); return listTaxCodes().then((data) => { setCodes(data.tax_codes) }).catch(() => { setMessage('Unable to load tax codes.') }).finally(() => { setLoading(false) }) }
  useEffect(() => { if (canRead) void load() }, [canRead])
  async function submit(event: FormEvent) { event.preventDefault(); setMessage(null); try { const result = await createTaxCode({ code, name, jurisdiction }); setMessage(`Approval ${result.approval.id} is ${result.approval.status}. Share this id with an approver on the Approvals page.`); setCode(''); setName('') } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to submit tax code.') } }

  if (!canRead) {
    return (
      <AppLayout>
        <PageHeader title="Tax configuration" description="Effective-dated codes and TaxPack inputs. Changes require four-eyes approval." />
        <div className="p-4 lg:p-6"><Alert>You do not have permission to view tax configuration.</Alert></div>
      </AppLayout>
    )
  }

  return (
    <AppLayout>
      <PageHeader title="Tax configuration" description="Effective-dated codes and TaxPack inputs. Changes require four-eyes approval." />
      <div className="space-y-4 p-4 lg:p-6">
        {canManage ? <Card><CardHeader><h2 className="font-semibold">Define tax code</h2></CardHeader><CardContent><form className="grid gap-3 md:grid-cols-4" onSubmit={submit}><Input aria-label="Code" placeholder="Code" value={code} onChange={(e) => setCode(e.target.value)} required /><Input aria-label="Name" placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} required /><Input aria-label="Jurisdiction" placeholder="Configured jurisdiction" value={jurisdiction} onChange={(e) => setJurisdiction(e.target.value)} required /><Button type="submit">Request approval</Button></form></CardContent></Card> : null}
        {message ? <Alert>{message}</Alert> : null}
        <Card className="overflow-hidden"><CardContent className="p-0">
          {loading ? <div className="p-6"><LoadingState label="Loading tax codes" /></div>
            : codes.length === 0 ? <div className="p-6"><EmptyState title="No tax codes yet" description="Define your first tax code using the form above." /></div>
            : <Table><TableHeader><TableRow><TableHead>Code</TableHead><TableHead>Name</TableHead><TableHead>Jurisdiction</TableHead><TableHead>Status</TableHead><TableHead>Version</TableHead></TableRow></TableHeader><tbody>{codes.map((item) => <TableRow key={item.id}><TableCell className="font-medium">{item.code}</TableCell><TableCell>{item.name}</TableCell><TableCell className="text-[var(--color-text-muted)]">{item.jurisdiction}</TableCell><TableCell><Badge variant={item.status === 'active' ? 'success' : 'neutral'}>{item.status}</Badge></TableCell><TableCell>{item.version}</TableCell></TableRow>)}</tbody></Table>}
        </CardContent></Card>
      </div>
    </AppLayout>
  )
}
