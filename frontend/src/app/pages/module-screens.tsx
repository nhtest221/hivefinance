import { Badge, Button, Card, CardContent, CardHeader, Table, TableCell, TableHead, TableHeader, TableRow, Tabs, TabsContent, TabsList, TabsTrigger } from '@/design-system'
import { accountingPeriods, auditRows, banks, reports, settlements, trialBalanceRows } from '../mock-data'
import { ModulePage } from './module-page'

export function SettlementPage() {
  return (
    <ModulePage
      title="Settlement"
      description="Receipt and payment allocation review, including withholding and realised FX placeholders."
      badge="Cash application"
      columns={['Allocation', 'Direction', 'Party', 'Amount', 'Withholding', 'Realised FX']}
      rows={settlements}
      summary={[
        { label: 'Receipts this month', value: 'BDT 8.2M', meta: 'Cash layer source' },
        { label: 'Payments this month', value: 'BDT 4.6M', meta: 'Cash layer source' },
        { label: 'Unapplied credits', value: 'BDT 402K', meta: 'Customer and vendor' },
      ]}
    />
  )
}

export function BankAccountsPage() {
  return (
    <ModulePage
      title="Bank Accounts"
      description="Ledger-owned bank account master and reconciliation status."
      badge="Cash"
      columns={['Account', 'Currency', 'Book balance', 'Reconciliation', 'Last reconciled']}
      rows={banks}
      summary={[
        { label: 'Book cash', value: 'BDT 18.42M', meta: 'Across active accounts' },
        { label: 'Foreign cash', value: 'USD 12.4K', meta: 'Subject to revaluation' },
        { label: 'Pending imports', value: '2', meta: 'CSV statement placeholders' },
      ]}
    />
  )
}

export function AuditLogPage() {
  return (
    <ModulePage
      title="Audit Log"
      description="Immutable activity stream for financially significant actions and access changes."
      badge="Append-only"
      columns={['Timestamp', 'Actor', 'Action', 'Module', 'Correlation ID']}
      rows={auditRows}
      summary={[
        { label: 'Events today', value: '146', meta: 'Structured logs separate' },
        { label: 'SoD exceptions', value: '1', meta: 'Queued for review' },
        { label: 'Break-glass activations', value: '0', meta: 'Current month' },
      ]}
    />
  )
}

export function ReportsPage() {
  return (
    <ModulePage
      title="Reports"
      description="Read-side report catalog with basis labels and export placeholders."
      badge="Read models"
      columns={['Report', 'Basis', 'Purpose']}
      rows={reports}
      summary={[
        { label: 'Accrual reports', value: '9', meta: 'Statutory safe' },
        { label: 'Cash views', value: '2', meta: 'Derived layer' },
        { label: 'Export formats', value: 'PDF / CSV', meta: 'Placeholder only' },
      ]}
      aside={<ReportAside />}
    />
  )
}

export function SettingsPage() {
  return (
    <ModulePage
      title="Settings"
      description="Entity, users, roles, approval policy, periods, and system configuration."
      badge="Admin"
      columns={['Area', 'Owner context', 'Status', 'Review cadence']}
      rows={[
        ['Entity profile', 'Identity & Access', 'Active', 'Annual'],
        ['Users and roles', 'Identity & Access', 'Review', 'Monthly'],
        ['Approval policy', 'Identity & Access', 'Active', 'Quarterly'],
        ['Fiscal calendar', 'Period & Close', 'Active', 'Annual'],
      ]}
      summary={[
        { label: 'Active users', value: '14', meta: 'Finance scoped' },
        { label: 'Custom roles', value: '3', meta: 'Within granter privilege' },
        { label: 'Open period', value: 'FY26-P01', meta: 'BD entity' },
      ]}
      aside={<SettingsAside />}
    />
  )
}

function ReportAside() {
  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold">Report basis</h2>
      </CardHeader>
      <CardContent className="space-y-3 text-sm">
        <div className="flex items-center justify-between"><span>Balance Sheet</span><Badge>Balance</Badge></div>
        <div className="flex items-center justify-between"><span>Tax Summary</span><Badge variant="info">Accrual only</Badge></div>
        <div className="flex items-center justify-between"><span>Cash View</span><Badge variant="warning">Derived</Badge></div>
        <div className="rounded-md border border-[var(--color-border)]">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Account</TableHead>
                <TableHead>Debit</TableHead>
                <TableHead>Credit</TableHead>
              </TableRow>
            </TableHeader>
            <tbody>
              {trialBalanceRows.slice(0, 3).map((row) => (
                <TableRow key={row[0]}>
                  <TableCell>{row[0]}</TableCell>
                  <TableCell className="text-right tabular-nums">{row[2]}</TableCell>
                  <TableCell className="text-right tabular-nums">{row[3]}</TableCell>
                </TableRow>
              ))}
            </tbody>
          </Table>
        </div>
        <Button variant="secondary" className="w-full">Preview export</Button>
      </CardContent>
    </Card>
  )
}

function SettingsAside() {
  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold">Settings sections</h2>
      </CardHeader>
      <CardContent>
        <Tabs defaultValue="entity">
          <TabsList>
            <TabsTrigger value="entity">Entity</TabsTrigger>
            <TabsTrigger value="access">Access</TabsTrigger>
            <TabsTrigger value="periods">Periods</TabsTrigger>
          </TabsList>
          <TabsContent value="entity" className="mt-4 text-sm text-[var(--color-text-muted)]">Legal entity and fiscal configuration placeholders.</TabsContent>
          <TabsContent value="access" className="mt-4 text-sm text-[var(--color-text-muted)]">Roles, SoD, delegation, and MFA placeholders.</TabsContent>
          <TabsContent value="periods" className="mt-4">
            <div className="space-y-2 text-sm">
              {accountingPeriods.map((period) => (
                <div className="flex items-center justify-between gap-3" key={period[0]}>
                  <span className="font-medium">{period[0]}</span>
                  <span className="text-right text-[var(--color-text-muted)]">{period[3]}</span>
                </div>
              ))}
            </div>
          </TabsContent>
        </Tabs>
      </CardContent>
    </Card>
  )
}
