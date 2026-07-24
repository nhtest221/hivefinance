import { useEffect, useState } from 'react'

import { Alert, Badge, Card, CardContent, CardHeader, EmptyState, LoadingState, PageHeader, Table, TableCell, TableHead, TableHeader, TableRow } from '@/design-system'
import { AppLayout } from '@/layouts/app-layout'
import { documentsApi, type Bill, type Customer, type Invoice } from '@/features/documents/documents-api'
import { periodsApi, type Period, type PeriodDetail } from '@/features/periods/periods-api'
import { hasPermission } from '@/features/identity/permissions'

/** Sums Money values per currency rather than collapsing mixed currencies into one
 * (mis)leading total — a dashboard KPI is not exempt from exact-decimal-per-currency
 * correctness just because it is a summary view. */
function sumByCurrency(amounts: Array<{ amount: string; currency: string }>): string {
  const totals = new Map<string, number>()
  for (const { amount, currency } of amounts) {
    totals.set(currency, (totals.get(currency) ?? 0) + Number.parseFloat(amount))
  }
  if (totals.size === 0) return '—'

  return [...totals.entries()].map(([currency, total]) => `${currency} ${total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`).join(' + ')
}

export function DashboardPage() {
  const canReadReceivables = hasPermission('receivables.invoices.read')
  const canReadPayables = hasPermission('payables.bills.read')
  const canReadPeriods = hasPermission('periods.read')

  const [invoices, setInvoices] = useState<Invoice[]>([])
  const [customers, setCustomers] = useState<Customer[]>([])
  const [bills, setBills] = useState<Bill[]>([])
  const [period, setPeriod] = useState<PeriodDetail | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    async function load() {
      setLoading(true)
      const [invoiceResult, customerResult, billResult, periodResult] = await Promise.allSettled([
        canReadReceivables ? documentsApi.invoices() : Promise.resolve(null),
        canReadReceivables ? documentsApi.customers() : Promise.resolve(null),
        canReadPayables ? documentsApi.bills() : Promise.resolve(null),
        canReadPeriods ? periodsApi.list({ limit: '1' }) : Promise.resolve(null),
      ])
      if (invoiceResult.status === 'fulfilled' && invoiceResult.value) setInvoices(invoiceResult.value.data.invoices)
      if (customerResult.status === 'fulfilled' && customerResult.value) setCustomers(customerResult.value.data.customers)
      if (billResult.status === 'fulfilled' && billResult.value) setBills(billResult.value.data.bills)
      if (periodResult.status === 'fulfilled' && periodResult.value?.periods[0]) {
        const first: Period = periodResult.value.periods[0]
        try { setPeriod((await periodsApi.show(first.id)).period) } catch { /* leave period null; the card below handles it */ }
      }
      setLoading(false)
    }
    void load()
  }, [canReadReceivables, canReadPayables, canReadPeriods])

  const customerName = (id: string) => customers.find((c) => c.id === id)?.name ?? id.slice(0, 8)
  const openInvoices = invoices.filter((i) => i.status === 'sent' || i.status === 'partially_paid')
  const openBills = bills.filter((b) => b.status === 'awaiting_payment' || b.status === 'partially_paid')
  const draftCount = invoices.filter((i) => i.status === 'draft').length + bills.filter((b) => b.status === 'draft').length

  return (
    <AppLayout>
      <PageHeader title="Dashboard" description="Entity-scoped finance overview with receivables, payables, and close readiness." />
      <div className="space-y-4 p-4 lg:p-6">
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <Card>
            <CardContent>
              <p className="text-xs font-medium uppercase text-[var(--color-text-subtle)]">Outstanding receivables</p>
              <p className="mt-2 text-2xl font-semibold">{sumByCurrency(openInvoices.map((i) => i.open_balance))}</p>
              <Badge className="mt-3" variant="info">{openInvoices.length} open invoice{openInvoices.length === 1 ? '' : 's'}</Badge>
            </CardContent>
          </Card>
          <Card>
            <CardContent>
              <p className="text-xs font-medium uppercase text-[var(--color-text-subtle)]">Outstanding payables</p>
              <p className="mt-2 text-2xl font-semibold">{sumByCurrency(openBills.map((b) => b.open_balance))}</p>
              <Badge className="mt-3" variant="warning">{openBills.length} open bill{openBills.length === 1 ? '' : 's'}</Badge>
            </CardContent>
          </Card>
          <Card>
            <CardContent>
              <p className="text-xs font-medium uppercase text-[var(--color-text-subtle)]">Drafts pending action</p>
              <p className="mt-2 text-2xl font-semibold">{draftCount}</p>
              <Badge className="mt-3" variant={draftCount > 0 ? 'warning' : 'success'}>{draftCount > 0 ? 'Needs review' : 'All clear'}</Badge>
            </CardContent>
          </Card>
          <Card>
            <CardContent>
              <p className="text-xs font-medium uppercase text-[var(--color-text-subtle)]">Current period</p>
              <p className="mt-2 text-2xl font-semibold">{period?.period_ref ?? '—'}</p>
              {period ? <Badge className="mt-3" variant={period.state === 'Open' || period.state === 'Reopened' ? 'info' : period.state === 'SoftClosed' ? 'warning' : 'success'}>{period.state}</Badge> : null}
            </CardContent>
          </Card>
        </div>

        <Card>
          <CardHeader>
            <h2 className="text-sm font-semibold">Close readiness{period ? ` — ${period.period_ref}` : ''}</h2>
          </CardHeader>
          <CardContent className="space-y-3">
            {!canReadPeriods ? (
              <Alert>You do not have permission to view period close status.</Alert>
            ) : loading ? (
              <LoadingState label="Loading close-gate status" />
            ) : !period ? (
              <EmptyState title="No period found" description="An administrator has not yet configured the fiscal calendar." />
            ) : (
              <>
                <Alert>
                  {period.period_ref} is {period.state}.{' '}
                  {period.close_gates.every((g) => g.status === 'satisfied') ? 'All mandatory close gates are satisfied.' : 'Some mandatory close gates are still pending.'}
                </Alert>
                {period.close_gates.map((gate) => (
                  <div className="flex items-center justify-between rounded-md border border-[var(--color-border)] px-3 py-2 text-sm" key={gate.gate_type}>
                    <span className="capitalize">{gate.gate_type.replace(/_/g, ' ')}</span>
                    <Badge variant={gate.status === 'satisfied' ? 'success' : gate.status === 'stale' ? 'warning' : 'danger'}>{gate.status}</Badge>
                  </div>
                ))}
              </>
            )}
          </CardContent>
        </Card>

        <Card className="overflow-hidden">
          <CardHeader>
            <h2 className="text-sm font-semibold">Receivables requiring attention</h2>
          </CardHeader>
          <CardContent className="p-0">
            {!canReadReceivables ? (
              <div className="p-6"><Alert>You do not have permission to view receivables.</Alert></div>
            ) : loading ? (
              <div className="p-6"><LoadingState label="Loading receivables" /></div>
            ) : openInvoices.length === 0 ? (
              <div className="p-6"><EmptyState title="Nothing outstanding" description="Every issued invoice is fully paid." /></div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Document</TableHead>
                    <TableHead>Customer</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Open balance</TableHead>
                    <TableHead>Due date</TableHead>
                  </TableRow>
                </TableHeader>
                <tbody>
                  {openInvoices.map((invoice) => (
                    <TableRow key={invoice.id}>
                      <TableCell className="font-medium">{invoice.document_number ?? 'Draft'}</TableCell>
                      <TableCell>{customerName(invoice.customer_id)}</TableCell>
                      <TableCell><Badge variant={invoice.status === 'sent' ? 'info' : 'warning'}>{invoice.status.replace(/_/g, ' ')}</Badge></TableCell>
                      <TableCell className="text-right tabular-nums">{invoice.open_balance.currency} {invoice.open_balance.amount}</TableCell>
                      <TableCell className="text-[var(--color-text-muted)]">{invoice.due_date}</TableCell>
                    </TableRow>
                  ))}
                </tbody>
              </Table>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  )
}
