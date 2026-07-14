import { Alert, Badge, Button, Card, CardContent, CardHeader, Tabs, TabsContent, TabsList, TabsTrigger } from '@/design-system'
import { accounts, auditRows, banks, fxRates, journals, payables, receivables, reports, settlements, taxCodes } from '../mock-data'
import { ModulePage } from './module-page'

export function ChartOfAccountsPage() {
  return (
    <ModulePage
      title="Chart of Accounts"
      description="Entity-scoped account structure with derived balance visibility."
      badge="Ledger"
      columns={['Code', 'Name', 'Class', 'Status', 'Derived balance']}
      rows={accounts}
      summary={[
        { label: 'Active accounts', value: '72', meta: 'Across 5 classes' },
        { label: 'Bank accounts', value: '7', meta: 'Ledger-owned master data' },
        { label: 'Inactive accounts', value: '4', meta: 'Preserved for audit' },
      ]}
    />
  )
}

export function JournalEntriesPage() {
  return (
    <ModulePage
      title="Journal Entries"
      description="Read-only posting workspace for manual and system entries."
      badge="Accrual"
      columns={['Entry ID', 'Type', 'Status', 'Source', 'Amount', 'Period']}
      rows={journals}
      summary={[
        { label: 'Posted this period', value: '184', meta: 'System and manual' },
        { label: 'Draft entries', value: '3', meta: 'Not posted' },
        { label: 'Reversals', value: '2', meta: 'Linked corrections' },
      ]}
    />
  )
}

export function ReceivablesPage() {
  return (
    <ModulePage
      title="Receivables"
      description="Customer invoices, credit notes, open balances, and ageing."
      badge="Documents"
      columns={['Document', 'Customer', 'Status', 'Amount', 'Open balance', 'Age']}
      rows={receivables}
      summary={[
        { label: 'Open AR', value: 'BDT 9.84M', meta: '12 overdue documents' },
        { label: 'Held credits', value: 'BDT 214K', meta: 'Customer credit balances' },
        { label: 'Foreign AR', value: 'USD 42.8K', meta: 'Rate referenced' },
      ]}
    />
  )
}

export function PayablesPage() {
  return (
    <ModulePage
      title="Payables"
      description="Supplier bills, debit notes, expenses, and AP ageing."
      badge="Documents"
      columns={['Document', 'Vendor', 'Status', 'Amount', 'Open balance', 'Due']}
      rows={payables}
      summary={[
        { label: 'Open AP', value: 'BDT 3.18M', meta: '5 due this week' },
        { label: 'Vendor credits', value: 'BDT 88K', meta: 'Unapplied' },
        { label: 'AIT/VDS tracked', value: 'BDT 314K', meta: 'Current period' },
      ]}
    />
  )
}

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

export function TaxPage() {
  return (
    <ModulePage
      title="Tax"
      description="Tax code registry, Bangladesh Tax Pack, and effective-dated versions."
      badge="Compliance"
      columns={['Code', 'Treatment', 'Rate', 'GL mapping', 'Version status']}
      rows={taxCodes}
      summary={[
        { label: 'Tax pack', value: 'Bangladesh', meta: 'Pack #1' },
        { label: 'Active codes', value: '9', meta: 'Versioned' },
        { label: 'Return boxes', value: 'Mapped', meta: 'Mushak placeholders' },
      ]}
      aside={<ComplianceAside title="Tax configuration" />}
    />
  )
}

export function FxPage() {
  return (
    <ModulePage
      title="FX"
      description="Effective-dated rate records and revaluation run visibility."
      badge="Currency"
      columns={['Pair', 'Rate', 'Source', 'Effective date', 'Status']}
      rows={fxRates}
      summary={[
        { label: 'Functional currencies', value: 'BDT / CAD', meta: 'Per entity' },
        { label: 'Referenced rates', value: '48', meta: 'Immutable once used' },
        { label: 'Pending revaluation', value: 'FY26-P01', meta: 'Soft close input' },
      ]}
      aside={<ComplianceAside title="Rate governance" />}
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

function ComplianceAside({ title }: { title: string }) {
  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold">{title}</h2>
      </CardHeader>
      <CardContent className="space-y-3">
        <Alert>Configuration changes are high-risk actions in the frozen access model.</Alert>
        <div className="rounded-md border border-[var(--color-border)] p-3 text-sm">
          <p className="font-medium">Review posture</p>
          <p className="mt-1 text-[var(--color-text-muted)]">Versioned, reproducible, and audit-visible.</p>
        </div>
      </CardContent>
    </Card>
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
          <TabsContent value="periods" className="mt-4 text-sm text-[var(--color-text-muted)]">Open, soft close, hard close, and reopen placeholders.</TabsContent>
        </Tabs>
      </CardContent>
    </Card>
  )
}
