import { CheckCircle2, ShieldCheck } from 'lucide-react'
import { type FormEvent, useState } from 'react'

import { Alert, Badge, Button, Card, CardContent, CardHeader, Input, PageHeader } from '@/design-system'
import { ApiRequestError } from '@/features/identity/auth-api'
import { hasPermission } from '@/features/identity/permissions'
import { approvalsApi, type ApprovalOutcome } from './approvals-api'

export function ApprovalsPage() {
  const canApprove = hasPermission('identity.approvals.approve')
  const [approvalId, setApprovalId] = useState('')
  const [expectedVersion, setExpectedVersion] = useState('1')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<ApprovalOutcome | null>(null)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    setResult(null)
    try {
      const version = Number.parseInt(expectedVersion, 10)
      const outcome = await approvalsApi.approve(approvalId.trim(), Number.isFinite(version) ? version : 1)
      setResult(outcome)
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.message : 'Approval failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="p-6">
      <div className="mx-auto max-w-3xl space-y-5">
        <PageHeader
          title="Approvals"
          description="Complete a maker-checker command that is pending a second, distinct approver."
          actions={<Badge variant="info">Four-eyes</Badge>}
        />

        <Alert>
          <p className="font-medium text-[var(--color-text)]">How to find an approval id</p>
          <p className="mt-1 text-[var(--color-text-muted)]">
            When a maker submits a command that requires approval (a bill, credit or debit note, tax code,
            FX rate, Hard Close, or period Reopen), the app shows them the pending approval&apos;s id and
            version. Share that id with a different, authorized approver, who enters it here to complete
            the action. HiveFinance does not yet have a centralized list of every pending approval across
            modules &mdash; only the originating maker&apos;s screen currently surfaces a new approval id.
          </p>
        </Alert>

        {!canApprove ? (
          <Alert>You do not have permission to approve commands.</Alert>
        ) : (
          <Card>
            <CardHeader>
              <h2 className="flex items-center gap-2 text-sm font-semibold">
                <ShieldCheck className="size-4 text-[var(--color-info)]" />
                Approve a pending command
              </h2>
            </CardHeader>
            <CardContent>
              <form className="grid gap-3 sm:grid-cols-[1fr_8rem_auto] sm:items-end" onSubmit={submit}>
                <label className="space-y-1 text-sm">
                  <span className="font-medium text-[var(--color-text)]">Approval id</span>
                  <Input
                    value={approvalId}
                    onChange={(event) => { setApprovalId(event.target.value) }}
                    placeholder="e.g. 6a1e2b3c-4d5e-4f6a-8b9c-0d1e2f3a4b5c"
                    required
                  />
                </label>
                <label className="space-y-1 text-sm">
                  <span className="font-medium text-[var(--color-text)]">Version</span>
                  <Input
                    value={expectedVersion}
                    onChange={(event) => { setExpectedVersion(event.target.value) }}
                    inputMode="numeric"
                    required
                  />
                </label>
                <Button type="submit" disabled={submitting}>
                  {submitting ? 'Approving…' : 'Approve'}
                </Button>
              </form>
            </CardContent>
          </Card>
        )}

        {error ? <Alert className="border-red-200 bg-red-50 text-[var(--color-danger)]">{error}</Alert> : null}

        {result ? (
          <Card>
            <CardHeader>
              <h2 className="flex items-center gap-2 text-sm font-semibold text-[var(--color-success)]">
                <CheckCircle2 className="size-4" />
                Approved
              </h2>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <p>
                Approval <span className="font-mono">{result.approval.id}</span> is now{' '}
                <Badge variant="success">{result.approval.status}</Badge>.
              </p>
              <p className="text-[var(--color-text-muted)]">
                The underlying command executed with status {result.command_result.status}. It may return its
                own result payload (a document, a period, a rate) — refresh the originating screen to see it
                reflected.
              </p>
            </CardContent>
          </Card>
        ) : null}
      </div>
    </main>
  )
}
