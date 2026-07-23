import { Card, CardContent, CardHeader, Tabs, TabsContent, TabsList, TabsTrigger } from '@/design-system'
import { accountingPeriods, auditRows, banks } from '../mock-data'
import { ModulePage } from './module-page'

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
