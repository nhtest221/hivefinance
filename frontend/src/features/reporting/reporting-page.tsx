import { useCallback, useEffect, useState } from 'react'

import { periodsApi, reportRunsApi, reportsApi, type PeriodDetail, type PeriodSummary, type ReportRun, type ReportType } from './reporting-api'
import { Alert, Badge, Button, Card, CardContent, CardHeader, Input, Table, TableCell, TableHead, TableHeader, TableRow, Tabs, TabsContent, TabsList, TabsTrigger } from '@/design-system'
import { hasPermission } from '@/features/identity/permissions'

const REPORT_TYPES: Array<{ value: ReportType; label: string; dateMode: 'asOf' | 'period' | 'range'; permission: string }> = [
  { value: 'trial_balance', label: 'Trial Balance', dateMode: 'asOf', permission: 'ledger.reports.read' },
  { value: 'general_ledger', label: 'General Ledger', dateMode: 'range', permission: 'ledger.reports.read' },
  { value: 'profit_and_loss', label: 'Profit and Loss', dateMode: 'period', permission: 'reporting.profit_and_loss.read' },
  { value: 'balance_sheet', label: 'Balance Sheet', dateMode: 'asOf', permission: 'reporting.balance_sheet.read' },
  { value: 'ar_ageing', label: 'Receivables Ageing', dateMode: 'asOf', permission: 'reporting.ar_ageing.read' },
  { value: 'ap_ageing', label: 'Payables Ageing', dateMode: 'asOf', permission: 'reporting.ap_ageing.read' },
  { value: 'tax_summary', label: 'Tax / VAT Summary', dateMode: 'period', permission: 'reporting.tax_summary.read' },
  { value: 'fx_revaluation', label: 'FX Revaluation', dateMode: 'period', permission: 'reporting.fx_revaluation.read' },
  { value: 'cash_view', label: 'Cash View', dateMode: 'period', permission: 'reporting.cash_view.read' },
]

function stateVariant(state: string): 'success' | 'warning' | 'danger' | 'neutral' {
  if (state === 'Approved') return 'success'
  if (state === 'PendingApproval') return 'warning'
  if (state === 'Rejected' || state === 'Superseded') return 'danger'

  return 'neutral'
}

/** Recursively renders any report JSON body as a readable table — one renderer for all nine report shapes and for drill-down/comparison data alike. */
function JsonTable({ value }: { value: unknown }) {
  if (value === null || value === undefined) return <span className="text-[var(--color-text-muted)]">—</span>
  if (Array.isArray(value)) {
    if (value.length === 0) return <span className="text-[var(--color-text-muted)]">none</span>
    if (value.every((v) => typeof v !== 'object' || v === null)) return <span>{value.join(', ')}</span>

    return <div className="space-y-2">{value.map((item, i) => <div key={i} className="rounded-md border border-[var(--color-border)] p-2"><JsonTable value={item} /></div>)}</div>
  }
  if (typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>)

    return <Table><tbody>{entries.map(([k, v]) => <TableRow key={k}><TableCell className="w-48 align-top font-medium">{k}</TableCell><TableCell><JsonTable value={v} /></TableCell></TableRow>)}</tbody></Table>
  }

  return <span className="tabular-nums">{String(value)}</span>
}

export function ReportingPage() {
  return <main className="p-6"><div className="mx-auto max-w-6xl space-y-5">
    <div><h1 className="text-2xl font-semibold">Reporting</h1><p className="text-sm text-[var(--color-text-muted)]">Financial statements, ReportRun sign-off, export, and Hard Close gate status.</p></div>
    <Tabs defaultValue="preview"><TabsList><TabsTrigger value="preview">Reports</TabsTrigger><TabsTrigger value="runs">ReportRuns</TabsTrigger><TabsTrigger value="gates">Close-Gate Status</TabsTrigger></TabsList>
      <TabsContent value="preview" className="space-y-4"><ReportPreviewPanel /></TabsContent>
      <TabsContent value="runs" className="space-y-4"><ReportRunsPanel /></TabsContent>
      <TabsContent value="gates" className="space-y-4"><CloseGatePanel /></TabsContent>
    </Tabs>
  </div></main>
}

function ReportPreviewPanel() {
  const [reportType, setReportType] = useState<ReportType>('trial_balance')
  const [asOf, setAsOf] = useState('')
  const [period, setPeriod] = useState('')
  const [compareTo, setCompareTo] = useState('')
  const [sbu, setSbu] = useState('')
  const [customer, setCustomer] = useState('')
  const [vendor, setVendor] = useState('')
  const [account, setAccount] = useState('')
  const [rangeFrom, setRangeFrom] = useState('')
  const [rangeTo, setRangeTo] = useState('')
  const [basis, setBasis] = useState('accrual')
  const [preview, setPreview] = useState<Record<string, unknown> | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const meta = REPORT_TYPES.find((t) => t.value === reportType)!
  const canPreview = hasPermission(meta.permission)
  const canGenerate = hasPermission('reporting.report_runs.generate')

  async function loadPreview() {
    setBusy(true); setMessage(null)
    try {
      const result = await (
        reportType === 'trial_balance' ? reportsApi.trialBalance({ asOf, period_ref: period || undefined, sbu: sbu || undefined })
        : reportType === 'general_ledger' ? reportsApi.generalLedger({ account, range: `${rangeFrom}..${rangeTo}`, sbu: sbu || undefined })
        : reportType === 'profit_and_loss' ? reportsApi.profitAndLoss({ period, sbu: sbu || undefined, basis, compare_to: compareTo || undefined })
        : reportType === 'balance_sheet' ? reportsApi.balanceSheet({ asOf, sbu: sbu || undefined, compare_to: compareTo || undefined })
        : reportType === 'ar_ageing' ? reportsApi.arAgeing({ asOf, customer: customer || undefined })
        : reportType === 'ap_ageing' ? reportsApi.apAgeing({ asOf, vendor: vendor || undefined })
        : reportType === 'tax_summary' ? reportsApi.taxSummary({ period })
        : reportType === 'fx_revaluation' ? reportsApi.fxRevaluation({ period })
        : reportsApi.cashView({ period, sbu: sbu || undefined })
      )
      setPreview(result)
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Preview failed.') } finally { setBusy(false) }
  }

  async function generate() {
    setBusy(true); setMessage(null)
    try {
      const filters: Record<string, unknown> = {}
      if (sbu) filters.sbu = sbu
      if (customer) filters.customer = customer
      if (vendor) filters.vendor = vendor
      if (basis && reportType === 'profit_and_loss') filters.basis = basis
      if (compareTo) filters.compare_to = compareTo
      if (reportType === 'general_ledger') { filters.account = account; filters.range = `${rangeFrom}..${rangeTo}` }
      const result = await reportRunsApi.generate({ report_type: reportType, period_ref: meta.dateMode === 'period' ? period : undefined, as_of: meta.dateMode === 'asOf' ? asOf : undefined, filters })
      setMessage(result.report_run ? `ReportRun ${result.report_run.id} generated (state ${result.report_run.state}). Approve it in the ReportRuns tab before it can satisfy a Close Gate.` : 'ReportRun generation completed.')
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Generation failed.') } finally { setBusy(false) }
  }

  return <div className="space-y-4">
    {message ? <Alert>{message}</Alert> : null}
    <Card><CardContent className="space-y-3">
      <div className="grid gap-2 md:grid-cols-4">
        <select className="rounded-md border p-2" value={reportType} onChange={(e) => { setReportType(e.target.value as ReportType); setPreview(null) }}>
          {REPORT_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
        </select>
        {meta.dateMode === 'asOf' ? <Input type="date" aria-label="As of" value={asOf} onChange={(e) => setAsOf(e.target.value)} /> : null}
        {meta.dateMode === 'period' ? <Input placeholder="Period (e.g. 2026-07)" value={period} onChange={(e) => setPeriod(e.target.value)} /> : null}
        {meta.dateMode === 'range' ? <><Input placeholder="Account UUID" value={account} onChange={(e) => setAccount(e.target.value)} /><Input type="date" value={rangeFrom} onChange={(e) => setRangeFrom(e.target.value)} /><Input type="date" value={rangeTo} onChange={(e) => setRangeTo(e.target.value)} /></> : null}
        <Input placeholder="SBU (optional)" value={sbu} onChange={(e) => setSbu(e.target.value)} />
        {reportType === 'ar_ageing' ? <Input placeholder="Customer UUID (optional)" value={customer} onChange={(e) => setCustomer(e.target.value)} /> : null}
        {reportType === 'ap_ageing' ? <Input placeholder="Vendor UUID (optional)" value={vendor} onChange={(e) => setVendor(e.target.value)} /> : null}
        {reportType === 'profit_and_loss' || reportType === 'balance_sheet' ? <Input placeholder="Compare to period/as-of (optional)" value={compareTo} onChange={(e) => setCompareTo(e.target.value)} /> : null}
        {reportType === 'profit_and_loss' ? <select className="rounded-md border p-2" value={basis} onChange={(e) => setBasis(e.target.value)}><option value="accrual">Accrual</option><option value="cash">Cash (excluded from M5 MVP)</option></select> : null}
      </div>
      <div className="flex gap-2">
        {canPreview ? <Button disabled={busy} variant="secondary" onClick={() => void loadPreview()}>Preview (not evidence)</Button> : null}
        {canGenerate ? <Button disabled={busy} onClick={() => void generate()}>Generate immutable ReportRun</Button> : null}
      </div>
      {!canPreview && !canGenerate ? <Alert>You do not have permission to preview or generate this report.</Alert> : null}
    </CardContent></Card>
    {preview ? <Card><CardHeader><h2 className="font-semibold">Preview — recomputed live, not evidence</h2></CardHeader><CardContent><JsonTable value={preview} /></CardContent></Card> : null}
  </div>
}

function ReportRunsPanel() {
  const [runs, setRuns] = useState<ReportRun[]>([])
  const [filterType, setFilterType] = useState('')
  const [filterState, setFilterState] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const [expanded, setExpanded] = useState<ReportRun | null>(null)
  const canApprove = hasPermission('reporting.report_runs.approve')
  const canRead = hasPermission('reporting.report_runs.read')

  const load = useCallback(async () => {
    if (!canRead) return
    try { setRuns((await reportRunsApi.list({ report_type: filterType || undefined, state: filterState || undefined })).report_runs) } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to load ReportRuns.') }
  }, [canRead, filterType, filterState])
  useEffect(() => { void load() }, [load])

  async function approve(run: ReportRun) {
    try {
      const result = await reportRunsApi.approve(run)
      setMessage(result.approval ? `Approval ${result.approval.id} is pending; the run is not yet Approved.` : `ReportRun ${run.id} approved.`)
      await load()
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Approval failed.') }
  }

  async function view(run: ReportRun) {
    try { setExpanded((await reportRunsApi.show(run.id)).report_run) } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to load ReportRun detail.') }
  }

  if (!canRead) return <Alert>You do not have permission to view ReportRuns.</Alert>

  return <div className="space-y-4">
    {message ? <Alert>{message}</Alert> : null}
    <div className="flex gap-2">
      <select className="rounded-md border p-2" value={filterType} onChange={(e) => setFilterType(e.target.value)}><option value="">All report types</option>{REPORT_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}</select>
      <select className="rounded-md border p-2" value={filterState} onChange={(e) => setFilterState(e.target.value)}><option value="">All states</option>{['Generated', 'PendingApproval', 'Approved', 'Rejected', 'Superseded'].map((s) => <option key={s} value={s}>{s}</option>)}</select>
    </div>
    <Card><CardContent><Table><TableHeader><TableRow><TableHead>Report</TableHead><TableHead>Period / As-of</TableHead><TableHead>State</TableHead><TableHead>Generated</TableHead><TableHead>Content hash</TableHead><TableHead>Action</TableHead></TableRow></TableHeader><tbody>
      {runs.map((run) => <TableRow key={run.id}>
        <TableCell>{REPORT_TYPES.find((t) => t.value === run.report_type)?.label ?? run.report_type}</TableCell>
        <TableCell>{run.period_ref ?? run.as_of}</TableCell>
        <TableCell><Badge variant={stateVariant(run.state)}>{run.state}</Badge>{run.superseded_by_report_run_id ? <span className="ml-2 text-xs text-[var(--color-text-muted)]">→ {run.superseded_by_report_run_id.slice(0, 8)}</span> : null}</TableCell>
        <TableCell>{new Date(run.generated_at).toLocaleString()}</TableCell>
        <TableCell className="font-mono text-xs">{run.content_hash.slice(0, 12)}…</TableCell>
        <TableCell className="space-x-2">
          <Button variant="secondary" onClick={() => void view(run)}>View</Button>
          {canApprove && (run.state === 'Generated' || run.state === 'PendingApproval') ? <Button onClick={() => void approve(run)}>Approve</Button> : null}
          <Button variant="secondary" onClick={() => void reportRunsApi.export(run, 'pdf').catch((e: unknown) => setMessage(e instanceof Error ? e.message : 'Export failed.'))}>PDF</Button>
          <Button variant="secondary" onClick={() => void reportRunsApi.export(run, 'csv').catch((e: unknown) => setMessage(e instanceof Error ? e.message : 'Export failed.'))}>CSV</Button>
        </TableCell>
      </TableRow>)}
    </tbody></Table></CardContent></Card>
    {expanded ? <Card><CardHeader><h2 className="font-semibold">ReportRun {expanded.id} — {expanded.state}</h2></CardHeader><CardContent><JsonTable value={expanded.content ?? {}} /></CardContent></Card> : null}
  </div>
}

function CloseGatePanel() {
  const [periods, setPeriods] = useState<PeriodSummary[]>([])
  const [detail, setDetail] = useState<PeriodDetail | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const canRead = hasPermission('periods.read')

  const load = useCallback(async () => {
    if (!canRead) return
    try { setPeriods((await periodsApi.list()).periods) } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to load periods.') }
  }, [canRead])
  useEffect(() => { void load() }, [load])

  async function inspect(period: PeriodSummary) {
    try { setDetail((await periodsApi.show(period.id)).period) } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to load period detail.') }
  }

  if (!canRead) return <Alert>You do not have permission to view period close-gate status.</Alert>

  return <div className="space-y-4">
    {message ? <Alert>{message}</Alert> : null}
    <Card><CardContent><Table><TableHeader><TableRow><TableHead>Period</TableHead><TableHead>State</TableHead><TableHead>Gates satisfied</TableHead><TableHead>Action</TableHead></TableRow></TableHeader><tbody>
      {periods.map((p) => <TableRow key={p.id}><TableCell>{p.period_ref}</TableCell><TableCell>{p.state}</TableCell><TableCell>{p.close_gate_summary.satisfied} / {p.close_gate_summary.required}</TableCell><TableCell><Button variant="secondary" onClick={() => void inspect(p)}>View gates</Button></TableCell></TableRow>)}
    </tbody></Table></CardContent></Card>
    {detail ? <Card><CardHeader><h2 className="font-semibold">{detail.period_ref} — {detail.state}</h2></CardHeader><CardContent><Table><TableHeader><TableRow><TableHead>Gate</TableHead><TableHead>Status</TableHead><TableHead>Evidence</TableHead></TableRow></TableHeader><tbody>
      {detail.close_gates.map((g) => <TableRow key={g.gate_type}><TableCell>{g.gate_type}</TableCell><TableCell><Badge variant={g.status === 'satisfied' ? 'success' : 'warning'}>{g.status}</Badge></TableCell><TableCell className="font-mono text-xs">{g.source_reference ?? '—'}</TableCell></TableRow>)}
    </tbody></Table></CardContent></Card> : null}
  </div>
}
