import { type FormEvent, useEffect, useState } from 'react'
import { Alert, Button, Card, CardContent, CardHeader, Input, Table, TableCell, TableHead, TableHeader, TableRow } from '@/design-system'
import { hasPermission } from '@/features/identity/permissions'
import { createAccount, listAccounts, type Account } from './ledger-api'

export function ChartOfAccountsPage() {
  const canRead = hasPermission('ledger.accounts.read')
  const canManage = hasPermission('ledger.accounts.manage')
  const [accounts, setAccounts] = useState<Account[]>([])
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [type, setType] = useState('asset')
  const [message, setMessage] = useState<string | null>(null)
  const load = () => listAccounts().then(({ data }) => setAccounts(data.accounts)).catch((error: unknown) => setMessage(error instanceof Error ? error.message : 'Unable to load accounts.'))
  useEffect(() => { if (canRead) void load() }, [canRead])
  async function submit(event: FormEvent) {
    event.preventDefault(); setMessage(null)
    try { await createAccount({ code, name, description: null, type }); setMessage('Account created.'); setCode(''); setName(''); await load() }
    catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to create account.') }
  }

  if (!canRead) return <main className="p-6"><Alert>You do not have permission to view the Chart of Accounts.</Alert></main>
  return <main className="p-6"><div className="mx-auto max-w-5xl space-y-5"><div><h1 className="text-2xl font-semibold">Chart of Accounts</h1><p className="text-sm text-[var(--color-text-muted)]">Entity-scoped account master.</p></div>
    {canManage ? <Card><CardHeader><h2 className="font-semibold">Create account</h2></CardHeader><CardContent><form className="grid gap-3 md:grid-cols-4" onSubmit={submit}><Input aria-label="Code" value={code} onChange={(event) => setCode(event.target.value)} required /><Input aria-label="Name" value={name} onChange={(event) => setName(event.target.value)} required /><select aria-label="Type" className="h-9 rounded-md border px-3" value={type} onChange={(event) => setType(event.target.value)}>{['asset','liability','equity','revenue','expense'].map((value) => <option key={value}>{value}</option>)}</select><Button type="submit">Create</Button></form></CardContent></Card> : null}
    {message ? <Alert>{message}</Alert> : null}
    <Card><CardContent><Table><TableHeader><TableRow><TableHead>Code</TableHead><TableHead>Name</TableHead><TableHead>Type</TableHead><TableHead>Status</TableHead></TableRow></TableHeader><tbody>{accounts.map((account) => <TableRow key={account.id}><TableCell>{account.code}</TableCell><TableCell>{account.name}</TableCell><TableCell>{account.type}</TableCell><TableCell>{account.status}</TableCell></TableRow>)}</tbody></Table></CardContent></Card>
  </div></main>
}
