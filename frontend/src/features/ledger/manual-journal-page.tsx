import { type FormEvent, useEffect, useState } from 'react'
import { Alert, Button, Card, CardContent, CardHeader, Input } from '@/design-system'
import { ApiRequestError } from '@/features/identity/auth-api'
import { createJournal, getGeneralLedger, listAccounts, postJournal, type Account, type GeneralLedger } from './ledger-api'

export function ManualJournalPage() {
  const [accounts, setAccounts] = useState<Account[]>([])
  const [debitAccount, setDebitAccount] = useState('')
  const [creditAccount, setCreditAccount] = useState('')
  const [amount, setAmount] = useState('')
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10))
  const [message, setMessage] = useState<string | null>(null)
  const [correlationId, setCorrelationId] = useState<string | null>(null)
  const [ledger, setLedger] = useState<GeneralLedger | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => { listAccounts().then(({ data }) => setAccounts(data.accounts)).catch(() => setMessage('Unable to load entity accounts.')) }, [])

  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true); setMessage(null); setLedger(null)
    try {
      if (debitAccount === creditAccount) throw new Error('Choose two different accounts.')
      const currency = sessionStorage.getItem('hivefinance.functional_currency') ?? 'BDT'
      const draft = await createJournal({ entry_date: date, narration: 'M0 walking-skeleton manual journal', lines: [
        { account_id: debitAccount, description: 'Manual debit', debit: { amount, currency }, credit: null },
        { account_id: creditAccount, description: 'Manual credit', debit: null, credit: { amount, currency } },
      ] })
      const posted = await postJournal(draft.data.journal)
      const gl = await getGeneralLedger(debitAccount, date.slice(0, 8) + '01', date)
      setLedger(gl.data); setCorrelationId(gl.correlationId ?? posted.correlationId); setMessage(`Journal ${posted.data.journal.id} posted.`)
    } catch (error) {
      setMessage(error instanceof ApiRequestError ? `${error.message}${error.errorCode ? ` (${error.errorCode})` : ''}` : error instanceof Error ? error.message : 'Unable to post journal.')
    } finally { setBusy(false) }
  }

  return <main className="p-6"><div className="mx-auto max-w-5xl space-y-5"><div><h1 className="text-2xl font-semibold">Manual Journal</h1><p className="text-sm text-[var(--color-text-muted)]">Authenticated M0 path: draft, post, and General Ledger.</p></div>
    <Card><CardHeader><h2 className="font-semibold">Balanced entry</h2></CardHeader><CardContent><form className="grid gap-4 md:grid-cols-2" onSubmit={submit}>
      <label className="text-sm">Date<Input type="date" value={date} onChange={(e) => setDate(e.target.value)} required /></label>
      <label className="text-sm">Amount<Input inputMode="decimal" pattern="\d+(\.\d{1,4})?" value={amount} onChange={(e) => setAmount(e.target.value)} required /></label>
      <label className="text-sm">Debit account<select className="h-9 w-full rounded-md border px-3" value={debitAccount} onChange={(e) => setDebitAccount(e.target.value)} required><option value="">Select</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}</select></label>
      <label className="text-sm">Credit account<select className="h-9 w-full rounded-md border px-3" value={creditAccount} onChange={(e) => setCreditAccount(e.target.value)} required><option value="">Select</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}</select></label>
      <Button className="md:col-span-2" disabled={busy} type="submit">{busy ? 'Posting…' : 'Create draft and post'}</Button>
    </form></CardContent></Card>
    {message ? <Alert>{message}{correlationId ? ` Correlation: ${correlationId}` : ''}</Alert> : null}
    {ledger ? <Card><CardHeader><h2 className="font-semibold">General Ledger — {ledger.account.code} {ledger.account.name}</h2></CardHeader><CardContent><div className="space-y-2 text-sm">{ledger.entries.map((entry) => <div className="grid grid-cols-4 gap-2 border-b py-2" key={entry.journal_entry_id}><span>{entry.entry_date}</span><span>{entry.description}</span><span>{entry.debit?.amount ?? entry.credit?.amount}</span><span>{entry.running_balance.amount}</span></div>)}<p className="font-medium">Closing: {ledger.closing_balance.amount} {ledger.closing_balance.currency}</p></div></CardContent></Card> : null}
  </div></main>
}
