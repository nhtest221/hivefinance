import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { useEffect, useState } from 'react'

import { Alert, Badge, Card, CardContent, CardHeader, PageHeader, Table, TableCell, TableHead, TableHeader, TableRow } from '@/design-system'
import { AppLayout } from '@/layouts/app-layout'
import { documentsApi, type Invoice } from '@/features/documents/documents-api'
import { cashTrend, kpis } from '../mock-data'

export function DashboardPage() {
  const [receivables, setReceivables] = useState<Invoice[]>([])
  useEffect(() => { void documentsApi.invoices().then((result) => setReceivables(result.data.invoices)).catch(() => setReceivables([])) }, [])
  return (
    <AppLayout>
      <PageHeader title="Dashboard" description="Entity-scoped finance overview with cash, receivables, payables, and close readiness." />
      <div className="space-y-4 p-4 lg:p-6">
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          {kpis.map((kpi) => (
            <Card key={kpi.label}>
              <CardContent>
                <p className="text-xs font-medium uppercase text-[var(--color-text-subtle)]">{kpi.label}</p>
                <p className="mt-2 text-2xl font-semibold">{kpi.value}</p>
                <Badge className="mt-3" variant={kpi.tone}>{kpi.delta}</Badge>
              </CardContent>
            </Card>
          ))}
        </div>
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
          <Card>
            <CardHeader>
              <h2 className="text-sm font-semibold">Cash flow trend</h2>
            </CardHeader>
            <CardContent>
              <div className="h-72">
                <ResponsiveContainer width="100%" height="100%">
                  <AreaChart data={cashTrend}>
                    <CartesianGrid stroke="var(--color-border)" vertical={false} />
                    <XAxis dataKey="month" tickLine={false} axisLine={false} />
                    <YAxis tickLine={false} axisLine={false} />
                    <Tooltip />
                    <Area dataKey="inflow" stroke="var(--color-primary)" fill="var(--color-primary-soft)" />
                    <Area dataKey="outflow" stroke="var(--color-warning)" fill="#fef3c7" />
                  </AreaChart>
                </ResponsiveContainer>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <h2 className="text-sm font-semibold">Close readiness</h2>
            </CardHeader>
            <CardContent className="space-y-3">
              <Alert>FY26-P01 is open. Bank reconciliation and FX revaluation are pending review.</Alert>
              {['Bank reconciliation', 'Accrual review', 'FX revaluation', 'Trial balance review'].map((item, index) => (
                <div className="flex items-center justify-between rounded-md border border-[var(--color-border)] px-3 py-2 text-sm" key={item}>
                  <span>{item}</span>
                  <Badge variant={index < 1 ? 'success' : 'warning'}>{index < 1 ? 'Ready' : 'Pending'}</Badge>
                </div>
              ))}
            </CardContent>
          </Card>
        </div>
        <Card className="overflow-hidden">
          <CardHeader>
            <h2 className="text-sm font-semibold">Receivables requiring attention</h2>
          </CardHeader>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Document</TableHead>
                <TableHead>Customer</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Open balance</TableHead>
                <TableHead>Age</TableHead>
              </TableRow>
            </TableHeader>
            <tbody>
              {receivables.map((invoice) => (
                <TableRow key={invoice.id}>
                  <TableCell>{invoice.document_number ?? 'Draft'}</TableCell>
                  <TableCell>{invoice.customer_id}</TableCell>
                  <TableCell><Badge variant={invoice.status === 'sent' ? 'info' : 'warning'}>{invoice.status}</Badge></TableCell>
                  <TableCell className="text-right tabular-nums">{invoice.open_balance.currency} {invoice.open_balance.amount}</TableCell>
                  <TableCell>{invoice.due_date}</TableCell>
                </TableRow>
              ))}
            </tbody>
          </Table>
        </Card>
      </div>
    </AppLayout>
  )
}
