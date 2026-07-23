import { useCallback, useEffect, useState } from 'react'

import { approvalsApi, reconciliationAccountsApi, reconciliationsApi, type Approval, type BankReconciliation, type ReconciliationAccount, type StatementLine } from './reconciliation-api'
import { Alert, Badge, Button, Card, CardContent, CardHeader, Input, Table, TableCell, TableHead, TableHeader, TableRow, Tabs, TabsContent, TabsList, TabsTrigger } from '@/design-system'
import { hasPermission } from '@/features/identity/permissions'

function stateVariant(state: string): 'success' | 'warning' | 'danger' | 'neutral' {
  if (state === 'Completed') return 'success'
  if (state === 'PendingCompletionApproval' || state === 'Reopened') return 'warning'

  return 'neutral'
}

function lineVariant(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
  if (status === 'Reconciled') return 'success'
  if (status === 'Matched' || status === 'Suggested') return 'warning'
  if (status === 'Unexplained') return 'danger'

  return 'neutral'
}

export function ReconciliationPage() {
  return <main className="p-6"><div className="mx-auto max-w-6xl space-y-5">
    <div><h1 className="text-2xl font-semibold">Reconciliation</h1><p className="text-sm text-[var(--color-text-muted)]">Bank accounts, statement import, matching, bank-only entries, and Hard Close evidence.</p></div>
    <Tabs defaultValue="reconciliations"><TabsList><TabsTrigger value="accounts">Bank Accounts</TabsTrigger><TabsTrigger value="reconciliations">Reconciliations</TabsTrigger></TabsList>
      <TabsContent value="accounts" className="space-y-4"><AccountsPanel /></TabsContent>
      <TabsContent value="reconciliations" className="space-y-4"><ReconciliationsPanel /></TabsContent>
    </Tabs>
  </div></main>
}

function AccountsPanel() {
  const [accounts, setAccounts] = useState<ReconciliationAccount[]>([])
  const [ledgerAccountId, setLedgerAccountId] = useState('')
  const [currency, setCurrency] = useState('BDT')
  const [displayName, setDisplayName] = useState('')
  const [maskedId, setMaskedId] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const canRead = hasPermission('reconciliation.accounts.read')
  const canConfigure = hasPermission('reconciliation.accounts.configure')

  const load = useCallback(async () => {
    if (!canRead) return
    try { setAccounts((await reconciliationAccountsApi.list()).reconciliation_accounts) } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to load accounts.') }
  }, [canRead])
  useEffect(() => { void load() }, [load])

  async function configure() {
    try {
      await reconciliationAccountsApi.configure({ ledger_account_id: ledgerAccountId, currency, display_name: displayName, masked_bank_identifier: maskedId || undefined })
      setLedgerAccountId(''); setDisplayName(''); setMaskedId('')
      await load()
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Configuration failed.') }
  }

  async function toggle(account: ReconciliationAccount) {
    try { await reconciliationAccountsApi.update(account, { reconciliation_enabled: !account.reconciliation_enabled }); await load() } catch (error) { setMessage(error instanceof Error ? error.message : 'Update failed.') }
  }

  if (!canRead) return <Alert>You do not have permission to view reconciliation accounts.</Alert>

  return <div className="space-y-4">
    {message ? <Alert>{message}</Alert> : null}
    {canConfigure ? <Card><CardHeader><h2 className="font-semibold">Configure a bank account for reconciliation</h2></CardHeader><CardContent className="space-y-3">
      <div className="grid gap-2 md:grid-cols-4">
        <Input placeholder="Ledger account UUID (asset-type)" value={ledgerAccountId} onChange={(e) => setLedgerAccountId(e.target.value)} />
        <Input placeholder="Currency (e.g. BDT)" value={currency} onChange={(e) => setCurrency(e.target.value)} />
        <Input placeholder="Display name" value={displayName} onChange={(e) => setDisplayName(e.target.value)} />
        <Input placeholder="Masked bank identifier (optional)" value={maskedId} onChange={(e) => setMaskedId(e.target.value)} />
      </div>
      <Button onClick={() => void configure()}>Configure account</Button>
    </CardContent></Card> : null}
    <Card><CardContent><Table><TableHeader><TableRow><TableHead>Display name</TableHead><TableHead>Ledger account</TableHead><TableHead>Currency</TableHead><TableHead>Reconciliation enabled</TableHead><TableHead>Action</TableHead></TableRow></TableHeader><tbody>
      {accounts.map((a) => <TableRow key={a.id}>
        <TableCell>{a.display_name}</TableCell>
        <TableCell className="font-mono text-xs">{a.ledger_account_id.slice(0, 8)}…</TableCell>
        <TableCell>{a.currency}</TableCell>
        <TableCell><Badge variant={a.reconciliation_enabled ? 'success' : 'neutral'}>{a.reconciliation_enabled ? 'mandatory for Hard Close' : 'not mandatory'}</Badge></TableCell>
        <TableCell>{canConfigure ? <Button variant="secondary" onClick={() => void toggle(a)}>{a.reconciliation_enabled ? 'Disable' : 'Enable'}</Button> : null}</TableCell>
      </TableRow>)}
    </tbody></Table></CardContent></Card>
  </div>
}

function ReconciliationsPanel() {
  const [accounts, setAccounts] = useState<ReconciliationAccount[]>([])
  const [reconciliations, setReconciliations] = useState<BankReconciliation[]>([])
  const [selectedAccount, setSelectedAccount] = useState('')
  const [periodRef, setPeriodRef] = useState('')
  const [opening, setOpening] = useState('0.0000')
  const [closing, setClosing] = useState('0.0000')
  const [active, setActive] = useState<BankReconciliation | null>(null)
  const [pendingApproval, setPendingApproval] = useState<Approval | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const canRead = hasPermission('reconciliation.reconciliations.read')
  const canOpen = hasPermission('reconciliation.reconciliations.open')

  const load = useCallback(async () => {
    if (!canRead) return
    try {
      setReconciliations((await reconciliationsApi.list()).reconciliations)
      setAccounts((await reconciliationAccountsApi.list()).reconciliation_accounts)
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to load reconciliations.') }
  }, [canRead])
  useEffect(() => { void load() }, [load])

  async function open() {
    try {
      const result = await reconciliationsApi.open({ reconciliation_account_id: selectedAccount, period_ref: periodRef, opening_balance: opening, closing_balance: closing })
      setActive(result.reconciliation)
      await load()
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Open failed.') }
  }

  async function view(reconciliation: BankReconciliation) {
    try { setActive((await reconciliationsApi.show(reconciliation.id)).reconciliation); setPendingApproval(null) } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to load reconciliation detail.') }
  }

  if (!canRead) return <Alert>You do not have permission to view reconciliations.</Alert>

  return <div className="space-y-4">
    {message ? <Alert>{message}</Alert> : null}
    {canOpen ? <Card><CardHeader><h2 className="font-semibold">Open a reconciliation batch</h2></CardHeader><CardContent className="space-y-3">
      <div className="grid gap-2 md:grid-cols-4">
        <select className="rounded-md border p-2" value={selectedAccount} onChange={(e) => setSelectedAccount(e.target.value)}>
          <option value="">Select bank account…</option>
          {accounts.map((a) => <option key={a.id} value={a.id}>{a.display_name}</option>)}
        </select>
        <Input placeholder="Period (e.g. 2026-07)" value={periodRef} onChange={(e) => setPeriodRef(e.target.value)} />
        <Input placeholder="Opening balance" value={opening} onChange={(e) => setOpening(e.target.value)} />
        <Input placeholder="Closing balance (per statement)" value={closing} onChange={(e) => setClosing(e.target.value)} />
      </div>
      <Button onClick={() => void open()}>Open reconciliation</Button>
    </CardContent></Card> : null}

    <Card><CardContent><Table><TableHeader><TableRow><TableHead>Period</TableHead><TableHead>State</TableHead><TableHead>Opening → Closing</TableHead><TableHead>Action</TableHead></TableRow></TableHeader><tbody>
      {reconciliations.map((r) => <TableRow key={r.id}>
        <TableCell>{r.period_ref}</TableCell>
        <TableCell><Badge variant={stateVariant(r.state)}>{r.state}</Badge></TableCell>
        <TableCell>{r.opening_balance} → {r.closing_balance}</TableCell>
        <TableCell className="space-x-2">
          <Button variant="secondary" onClick={() => void view(r)}>Open</Button>
          <Button variant="secondary" onClick={() => void reconciliationsApi.export(r, 'pdf').catch((e: unknown) => setMessage(e instanceof Error ? e.message : 'Export failed.'))}>PDF</Button>
          <Button variant="secondary" onClick={() => void reconciliationsApi.export(r, 'csv').catch((e: unknown) => setMessage(e instanceof Error ? e.message : 'Export failed.'))}>CSV</Button>
        </TableCell>
      </TableRow>)}
    </tbody></Table></CardContent></Card>

    {active ? <ReconciliationDetail reconciliation={active} pendingApproval={pendingApproval} setPendingApproval={setPendingApproval} setMessage={setMessage} onRefresh={async () => { setActive((await reconciliationsApi.show(active.id)).reconciliation); await load() }} /> : null}
  </div>
}

function ReconciliationDetail({ reconciliation, pendingApproval, setPendingApproval, setMessage, onRefresh }: {
  reconciliation: BankReconciliation
  pendingApproval: Approval | null
  setPendingApproval: (a: Approval | null) => void
  setMessage: (m: string | null) => void
  onRefresh: () => Promise<void>
}) {
  const [fileHash, setFileHash] = useState('')
  const [narration, setNarration] = useState('')
  const [amount, setAmount] = useState('')
  const [currency, setCurrency] = useState('BDT')
  const [transactionDate, setTransactionDate] = useState('')
  const [externalRef, setExternalRef] = useState('')
  const [offsetAccountId, setOffsetAccountId] = useState('')
  const [approvalVersion, setApprovalVersion] = useState('1')
  const canImport = hasPermission('reconciliation.reconciliations.import')
  const canMatch = hasPermission('reconciliation.reconciliations.match')
  const canConfirm = hasPermission('reconciliation.reconciliations.confirm')
  const canBankEntry = hasPermission('reconciliation.reconciliations.create_bank_entry')
  const canComplete = hasPermission('reconciliation.reconciliations.complete')
  const canReopen = hasPermission('reconciliation.reconciliations.reopen')
  const editable = reconciliation.state === 'Draft' || reconciliation.state === 'InProgress' || reconciliation.state === 'Reopened'

  async function importLine() {
    try {
      await reconciliationsApi.import(reconciliation.id, { file_hash: fileHash || crypto.randomUUID(), lines: [{ source_line_identity: crypto.randomUUID(), transaction_date: transactionDate, narration, amount: { amount, currency }, external_bank_reference: externalRef || undefined }] })
      setNarration(''); setAmount(''); setTransactionDate(''); setExternalRef(''); setFileHash('')
      await onRefresh()
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Import failed.') }
  }

  async function generateSuggestions() {
    try { const result = await reconciliationsApi.generateMatchSuggestions(reconciliation.id); setMessage(`${result.suggested} suggested, ${result.unexplained} unexplained.`); await onRefresh() } catch (error) { setMessage(error instanceof Error ? error.message : 'Suggestion generation failed.') }
  }

  async function matchFirstSuggestion(line: StatementLine) {
    try {
      const unmatched = (await reconciliationsApi.unmatched(reconciliation.id)).lines.find((l) => l.id === line.id)
      if (!unmatched) return
      // In this minimal UI, matching a suggestion requires fetching it via GenerateMatchSuggestions'
      // own response; re-run suggestions and match the first ranked group for this line.
      const result = await reconciliationsApi.generateMatchSuggestions(reconciliation.id)
      const suggestion = result.lines.find((l) => l.line_id === line.id)?.suggestions[0]
      if (!suggestion) { setMessage('No suggestion available for this line.'); return }
      await reconciliationsApi.matchLine(reconciliation.id, { ...unmatched, version: result.lines.find((l) => l.line_id === line.id)!.version }, suggestion.allocation_ids)
      await onRefresh()
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Match failed.') }
  }

  async function confirm(line: StatementLine) {
    try { await reconciliationsApi.confirmMatch(reconciliation.id, line); await onRefresh() } catch (error) { setMessage(error instanceof Error ? error.message : 'Confirm failed.') }
  }

  async function createBankEntry(line: StatementLine) {
    try {
      const result = await reconciliationsApi.createBankEntry(reconciliation.id, line, offsetAccountId, 'Bank-only reconciliation entry')
      if (result.approval) { setPendingApproval(result.approval); setMessage(`Approval ${result.approval.id} is pending — a different user must approve it.`) }
      await onRefresh()
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Bank-only entry failed.') }
  }

  async function complete() {
    try {
      const result = await reconciliationsApi.complete(reconciliation)
      if (result.approval) { setPendingApproval(result.approval); setMessage(`Approval ${result.approval.id} is pending — a different user must approve it.`) }
      await onRefresh()
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Completion failed.') }
  }

  async function reopen() {
    try {
      const result = await reconciliationsApi.reopen(reconciliation)
      if (result.approval) { setPendingApproval(result.approval); setMessage(`Approval ${result.approval.id} is pending — a different user must approve it.`) }
      await onRefresh()
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Reopen failed.') }
  }

  async function approvePending() {
    if (!pendingApproval) return
    try {
      const result = await approvalsApi.approve(pendingApproval.id, Number(approvalVersion))
      setMessage(`Approval committed (command status ${result.command_result.status}).`)
      setPendingApproval(null)
      await onRefresh()
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Approval failed.') }
  }

  return <Card><CardHeader><h2 className="font-semibold">{reconciliation.period_ref} — <Badge variant={stateVariant(reconciliation.state)}>{reconciliation.state}</Badge></h2></CardHeader>
    <CardContent className="space-y-4">
      {pendingApproval ? <Alert>
        Pending approval <span className="font-mono text-xs">{pendingApproval.id}</span> — a different, authorized user must approve it.
        <div className="mt-2 flex items-center gap-2">
          <Input className="w-24" placeholder="Approval version" value={approvalVersion} onChange={(e) => setApprovalVersion(e.target.value)} />
          <Button onClick={() => void approvePending()}>Approve as current user</Button>
        </div>
      </Alert> : null}

      {editable && canImport ? <div className="space-y-2">
        <h3 className="text-sm font-medium">Import a statement line</h3>
        <div className="grid gap-2 md:grid-cols-5">
          <Input type="date" value={transactionDate} onChange={(e) => setTransactionDate(e.target.value)} />
          <Input placeholder="Narration" value={narration} onChange={(e) => setNarration(e.target.value)} />
          <Input placeholder="Amount (signed)" value={amount} onChange={(e) => setAmount(e.target.value)} />
          <Input placeholder="Currency" value={currency} onChange={(e) => setCurrency(e.target.value)} />
          <Input placeholder="External bank reference (optional)" value={externalRef} onChange={(e) => setExternalRef(e.target.value)} />
        </div>
        <Button variant="secondary" onClick={() => void importLine()}>Import line</Button>
      </div> : null}

      {editable ? <Button variant="secondary" onClick={() => void generateSuggestions()}>Generate match suggestions</Button> : null}

      <Table><TableHeader><TableRow><TableHead>Date</TableHead><TableHead>Narration</TableHead><TableHead>Amount</TableHead><TableHead>Status</TableHead><TableHead>Action</TableHead></TableRow></TableHeader><tbody>
        {(reconciliation.lines ?? []).map((line) => <TableRow key={line.id}>
          <TableCell>{line.transaction_date}</TableCell>
          <TableCell>{line.narration}</TableCell>
          <TableCell className="tabular-nums">{line.amount.amount} {line.amount.currency}</TableCell>
          <TableCell><Badge variant={lineVariant(line.status)}>{line.status}</Badge></TableCell>
          <TableCell className="space-x-2">
            {editable && canMatch && (line.status === 'Unreconciled' || line.status === 'Suggested' || line.status === 'Unexplained') ? <Button variant="secondary" onClick={() => void matchFirstSuggestion(line)}>Match top suggestion</Button> : null}
            {editable && canConfirm && line.status === 'Matched' ? <Button onClick={() => void confirm(line)}>Confirm</Button> : null}
            {editable && canBankEntry && (line.status === 'Unreconciled' || line.status === 'Unexplained') ? <span className="inline-flex items-center gap-1"><Input className="w-40" placeholder="Offset account UUID" value={offsetAccountId} onChange={(e) => setOffsetAccountId(e.target.value)} /><Button variant="secondary" onClick={() => void createBankEntry(line)}>Bank-only entry</Button></span> : null}
          </TableCell>
        </TableRow>)}
      </tbody></Table>

      <div className="flex gap-2">
        {editable && canComplete ? <Button onClick={() => void complete()}>Complete (mandatory four-eyes)</Button> : null}
        {reconciliation.state === 'Completed' && canReopen ? <Button variant="secondary" onClick={() => void reopen()}>Reopen (mandatory four-eyes)</Button> : null}
      </div>
    </CardContent>
  </Card>
}
