import { type FormEvent, useEffect, useState } from 'react'
import { Alert, Badge, Button, Card, CardContent, CardHeader, EmptyState, Input, LoadingState, PageHeader, Table, TableCell, TableHead, TableHeader, TableRow } from '@/design-system'
import { AppLayout } from '@/layouts/app-layout'
import { hasPermission } from '@/features/identity/permissions'
import { createAccount, listAccounts, type Account } from './ledger-api'

export function ChartOfAccountsPage() {
  const canRead = hasPermission('ledger.accounts.read')
  const canManage = hasPermission('ledger.accounts.manage')
  const [accounts, setAccounts] = useState<Account[]>([])
  const [loading, setLoading] = useState(true)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [type, setType] = useState('asset')
  const [message, setMessage] = useState<string | null>(null)
  const load = () => { setLoading(true); return listAccounts().then(({ data }) => { setAccounts(data.accounts) }).catch((error: unknown) => { setMessage(error instanceof Error ? error.message : 'Unable to load accounts.') }).finally(() => { setLoading(false) }) }
  useEffect(() => { if (canRead) void load() }, [canRead])
  async function submit(event: FormEvent) {
    event.preventDefault(); setMessage(null)
    try { await createAccount({ code, name, description: null, type }); setMessage('Account created.'); setCode(''); setName(''); await load() }
    catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to create account.') }
  }

  if (!canRead) {
    return (
      <AppLayout>
        <PageHeader title="Chart of Accounts" description="Entity-scoped account master." />
        <div className="p-4 lg:p-6"><Alert>You do not have permission to view the Chart of Accounts.</Alert></div>
      </AppLayout>
    )
  }

  return (
    <AppLayout>
      <PageHeader title="Chart of Accounts" description="Entity-scoped account master." />
      <div className="space-y-4 p-4 lg:p-6">
        {canManage ? <Card><CardHeader><h2 className="font-semibold">Create account</h2></CardHeader><CardContent><form className="grid gap-3 md:grid-cols-4" onSubmit={submit}><Input aria-label="Code" placeholder="Code" value={code} onChange={(event) => setCode(event.target.value)} required /><Input aria-label="Name" placeholder="Name" value={name} onChange={(event) => setName(event.target.value)} required /><select aria-label="Type" className="h-9 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 text-sm" value={type} onChange={(event) => setType(event.target.value)}>{['asset', 'liability', 'equity', 'revenue', 'expense'].map((value) => <option key={value}>{value}</option>)}</select><Button type="submit">Create</Button></form></CardContent></Card> : null}
        {message ? <Alert>{message}</Alert> : null}
        <Card className="overflow-hidden"><CardContent className="p-0">
          {loading ? <div className="p-6"><LoadingState label="Loading accounts" /></div>
            : accounts.length === 0 ? <div className="p-6"><EmptyState title="No accounts yet" description="Create your first account using the form above." /></div>
            : <Table><TableHeader><TableRow><TableHead>Code</TableHead><TableHead>Name</TableHead><TableHead>Type</TableHead><TableHead>Status</TableHead></TableRow></TableHeader><tbody>{accounts.map((account) => <TableRow key={account.id}><TableCell className="font-medium">{account.code}</TableCell><TableCell>{account.name}</TableCell><TableCell className="text-[var(--color-text-muted)] capitalize">{account.type}</TableCell><TableCell><Badge variant={account.status === 'active' ? 'success' : 'neutral'}>{account.status}</Badge></TableCell></TableRow>)}</tbody></Table>}
        </CardContent></Card>
      </div>
    </AppLayout>
  )
}
