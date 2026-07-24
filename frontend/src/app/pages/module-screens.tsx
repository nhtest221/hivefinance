import { Link } from 'react-router'
import { CalendarClock, ClipboardList } from 'lucide-react'

import { Alert, Badge, Button, Card, CardContent, CardHeader, EmptyState, PageHeader } from '@/design-system'
import { AppLayout } from '@/layouts/app-layout'

/**
 * There is no `GET /v1/audit-log`-style read endpoint in the frozen API Contracts —
 * `AuditLog` rows exist in the database (every mutating command writes one) but nothing
 * exposes them over HTTP. Showing fabricated rows here would misrepresent real audit
 * data to a finance-team UAT tester, so this honestly states the gap instead. Exposing
 * audit-log reads is a real, separately governed backend decision, not something to
 * invent from the frontend.
 */
export function AuditLogPage() {
  return (
    <AppLayout>
      <PageHeader
        title="Audit Log"
        description="Immutable activity stream for financially significant actions and access changes."
        actions={<Badge variant="info">Append-only</Badge>}
      />
      <div className="p-4 lg:p-6">
        <Alert className="mb-4">
          Every mutating command in HiveFinance already writes a structured, immutable audit record
          (actor, module, action, before/after state, correlation id) to the database. There is currently
          no read API exposing that log to the frontend — the API Contracts define write-side audit
          requirements per command but not a general-purpose audit-log read endpoint. This screen will
          list real activity once that endpoint exists.
        </Alert>
        <Card>
          <CardContent className="p-0">
            <EmptyState
              title="Audit log reads are not yet exposed by the API"
              description="Ask a database administrator to query the audit_logs table directly for now, or raise a governance request for a read-only GET /v1/audit-log endpoint."
            />
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  )
}

export function SettingsPage() {
  return (
    <AppLayout>
      <PageHeader title="Settings" description="Entity, users, roles, approval policy, and periods." actions={<Badge variant="info">Admin</Badge>} />
      <div className="grid gap-4 p-4 lg:grid-cols-2 lg:p-6">
        <Card>
          <CardHeader><h2 className="text-sm font-semibold">Periods &amp; Close</h2></CardHeader>
          <CardContent className="space-y-3">
            <p className="text-sm text-[var(--color-text-muted)]">
              Soft Close, Hard Close, Reopen, and close-gate inspection have their own dedicated screen.
            </p>
            <Button asChild variant="secondary">
              <Link to="/periods"><CalendarClock className="size-4" /> Open Periods &amp; Close</Link>
            </Button>
          </CardContent>
        </Card>
        <Card>
          <CardHeader><h2 className="text-sm font-semibold">Approvals</h2></CardHeader>
          <CardContent className="space-y-3">
            <p className="text-sm text-[var(--color-text-muted)]">
              Complete a maker-checker command that is pending a second, distinct approver.
            </p>
            <Button asChild variant="secondary">
              <Link to="/approvals"><ClipboardList className="size-4" /> Open Approvals</Link>
            </Button>
          </CardContent>
        </Card>
        <Card className="lg:col-span-2">
          <CardHeader><h2 className="text-sm font-semibold">Entity, users, roles, and approval policy</h2></CardHeader>
          <CardContent>
            <EmptyState
              title="Not yet exposed by the API"
              description="Entity profile editing, user/role management, and approval-policy configuration are administered outside the app today (seeded fixtures or direct database access) — no frozen endpoint exposes them for a UI yet."
            />
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  )
}
