import { CheckCircle2, ChevronRight, Clock, XCircle } from 'lucide-react'
import { type FormEvent, useCallback, useEffect, useState } from 'react'

import {
  Alert,
  Badge,
  Button,
  Card,
  CardContent,
  Checkbox,
  Drawer,
  DrawerContent,
  DialogTitle as DrawerTitle,
  EmptyState,
  Input,
  LoadingState,
  PageHeader,
  Table,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system'
import { ApiRequestError } from '@/features/identity/auth-api'
import { hasPermission } from '@/features/identity/permissions'
import { periodsApi, type CloseGate, type Period, type PeriodDetail, type PeriodState, PeriodRequestError } from './periods-api'

const stateVariant: Record<PeriodState, 'info' | 'warning' | 'success' | 'danger'> = {
  Open: 'info',
  SoftClosed: 'warning',
  HardClosed: 'success',
  Reopened: 'danger',
}

const gateVariant: Record<CloseGate['status'], 'success' | 'danger' | 'warning'> = {
  satisfied: 'success',
  unmet: 'danger',
  stale: 'warning',
}

export function PeriodsPage() {
  const canRead = hasPermission('periods.read')
  const canSoftClose = hasPermission('periods.soft_close')
  const canHardClose = hasPermission('periods.hard_close')
  const canReopen = hasPermission('periods.reopen')
  const [periods, setPeriods] = useState<Period[]>([])
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState<{ tone: 'info' | 'error'; text: string } | null>(null)
  const [selected, setSelected] = useState<PeriodDetail | null>(null)
  const [busyId, setBusyId] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await periodsApi.list({ limit: '50' })
      setPeriods(result.periods)
    } catch (error) {
      setMessage({ tone: 'error', text: error instanceof ApiRequestError ? error.message : 'Unable to load periods.' })
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { if (canRead) void load() }, [canRead, load])

  async function openDetail(period: Period) {
    try {
      const result = await periodsApi.show(period.id)
      setSelected(result.period)
    } catch (error) {
      setMessage({ tone: 'error', text: error instanceof ApiRequestError ? error.message : 'Unable to load period detail.' })
    }
  }

  function describeApprovalOrGates(error: unknown, fallback: string): string {
    if (error instanceof PeriodRequestError && error.errorCode === 'invariant_violation' && error.details && 'unmet_gates' in error.details) {
      const gates = error.details.unmet_gates
      return `Mandatory close gates are not satisfied: ${Array.isArray(gates) ? gates.join(', ') : 'see period detail'}.`
    }
    return error instanceof ApiRequestError ? error.message : fallback
  }

  async function softClose(period: Period) {
    setBusyId(period.id)
    try {
      const result = await periodsApi.softClose(period)
      setMessage({ tone: 'info', text: result.approval ? `Approval ${result.approval.id} is pending Soft Close.` : 'Period moved to Soft Close.' })
      await load()
    } catch (error) {
      setMessage({ tone: 'error', text: describeApprovalOrGates(error, 'Soft Close failed.') })
    } finally {
      setBusyId(null)
    }
  }

  async function hardClose(period: Period) {
    setBusyId(period.id)
    try {
      const result = await periodsApi.hardClose(period)
      setMessage({ tone: 'info', text: result.approval ? `Hard Close always requires a second approver. Share approval id ${result.approval.id} with them on the Approvals page.` : 'Period Hard Closed.' })
      await load()
    } catch (error) {
      setMessage({ tone: 'error', text: describeApprovalOrGates(error, 'Hard Close failed.') })
    } finally {
      setBusyId(null)
    }
  }

  if (!canRead) return <main className="p-6"><Alert>You do not have permission to view periods.</Alert></main>

  return (
    <main className="p-6">
      <div className="mx-auto max-w-6xl space-y-5">
        <PageHeader
          title="Periods & Close"
          description="Soft Close, Hard Close, and Reopen the fiscal calendar. Hard Close and Reopen always require a second, distinct approver."
        />

        {message ? (
          <Alert className={message.tone === 'error' ? 'border-red-200 bg-red-50 text-[var(--color-danger)]' : undefined}>{message.text}</Alert>
        ) : null}

        <Card>
          <CardContent className="p-0">
            {loading ? (
              <div className="p-6"><LoadingState label="Loading periods" /></div>
            ) : periods.length === 0 ? (
              <div className="p-6"><EmptyState title="No periods yet" description="Accounting periods are created by an administrator as part of fiscal calendar setup." /></div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Period</TableHead>
                    <TableHead>Range</TableHead>
                    <TableHead>State</TableHead>
                    <TableHead>VAT lock</TableHead>
                    <TableHead>Close gates</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <tbody>
                  {periods.map((period) => (
                    <TableRow key={period.id}>
                      <TableCell>
                        <button type="button" className="flex items-center gap-1 font-medium hover:underline" onClick={() => void openDetail(period)}>
                          {period.period_ref}
                          <ChevronRight className="size-3.5 text-[var(--color-text-muted)]" />
                        </button>
                      </TableCell>
                      <TableCell className="text-[var(--color-text-muted)]">{period.starts_on} – {period.ends_on}</TableCell>
                      <TableCell><Badge variant={stateVariant[period.state]}>{period.state}</Badge></TableCell>
                      <TableCell className="text-[var(--color-text-muted)]">{period.vat_lock_status}</TableCell>
                      <TableCell>
                        {period.close_gate_summary ? (
                          <span className={period.close_gate_summary.satisfied === period.close_gate_summary.required ? 'text-[var(--color-success)]' : 'text-[var(--color-text-muted)]'}>
                            {period.close_gate_summary.satisfied}/{period.close_gate_summary.required} satisfied
                          </span>
                        ) : '—'}
                      </TableCell>
                      <TableCell className="text-right space-x-2">
                        {period.state === 'Open' || period.state === 'Reopened' ? (
                          canSoftClose ? <Button variant="secondary" size="sm" disabled={busyId === period.id} onClick={() => void softClose(period)}>Soft Close</Button> : null
                        ) : period.state === 'SoftClosed' ? (
                          canHardClose ? <Button variant="secondary" size="sm" disabled={busyId === period.id} onClick={() => void hardClose(period)}>Hard Close</Button> : null
                        ) : period.state === 'HardClosed' && canReopen ? (
                          <ReopenAction period={period} onDone={load} onMessage={setMessage} />
                        ) : null}
                      </TableCell>
                    </TableRow>
                  ))}
                </tbody>
              </Table>
            )}
          </CardContent>
        </Card>
      </div>

      <Drawer open={selected !== null} onOpenChange={(open) => { if (!open) setSelected(null) }}>
        <DrawerContent>
          {selected ? <PeriodDetailView period={selected} /> : null}
        </DrawerContent>
      </Drawer>
    </main>
  )
}

function PeriodDetailView({ period }: { period: PeriodDetail }) {
  return (
    <div className="flex h-full flex-col gap-4 overflow-y-auto">
      <div>
        <DrawerTitle className="text-lg font-semibold">{period.period_ref}</DrawerTitle>
        <p className="text-sm text-[var(--color-text-muted)]">{period.starts_on} – {period.ends_on}</p>
        <div className="mt-2 flex items-center gap-2">
          <Badge variant={stateVariant[period.state]}>{period.state}</Badge>
          <span className="text-xs text-[var(--color-text-muted)]">VAT: {period.vat_lock_status}</span>
        </div>
      </div>

      <section>
        <h3 className="mb-2 text-sm font-semibold">Close gates</h3>
        {period.close_gates.length === 0 ? (
          <p className="text-sm text-[var(--color-text-muted)]">No mandatory close gates apply.</p>
        ) : (
          <ul className="space-y-2">
            {period.close_gates.map((gate) => (
              <li key={gate.gate_type} className="flex items-start justify-between gap-3 rounded-md border border-[var(--color-border)] p-2.5 text-sm">
                <div>
                  <p className="font-medium">{gate.gate_type.replace(/_/g, ' ')}</p>
                  <p className="text-xs text-[var(--color-text-muted)]">{gate.source_context ?? 'No evidence yet'}</p>
                </div>
                <Badge variant={gateVariant[gate.status]}>
                  <span className="flex items-center gap-1">
                    {gate.status === 'satisfied' ? <CheckCircle2 className="size-3" /> : gate.status === 'unmet' ? <XCircle className="size-3" /> : <Clock className="size-3" />}
                    {gate.status}
                  </span>
                </Badge>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section>
        <h3 className="mb-2 text-sm font-semibold">Transition history</h3>
        {period.transitions.length === 0 ? (
          <p className="text-sm text-[var(--color-text-muted)]">No transitions recorded yet.</p>
        ) : (
          <ul className="space-y-2">
            {period.transitions.map((transition, index) => (
              <li key={index} className="rounded-md border border-[var(--color-border)] p-2.5 text-sm">
                <p className="font-medium">{transition.from_state} → {transition.to_state}</p>
                <p className="text-xs text-[var(--color-text-muted)]">{new Date(transition.transitioned_at).toLocaleString()}</p>
                {transition.reason_code ? <p className="mt-1 text-xs">Reason: {transition.reason_code}</p> : null}
                {transition.narrative ? <p className="text-xs text-[var(--color-text-muted)]">{transition.narrative}</p> : null}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

function ReopenAction({ period, onDone, onMessage }: { period: Period; onDone: () => Promise<void>; onMessage: (value: { tone: 'info' | 'error'; text: string }) => void }) {
  const [open, setOpen] = useState(false)
  const [reasonCode, setReasonCode] = useState('')
  const [vatUnlock, setVatUnlock] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    try {
      const result = await periodsApi.reopen(period, { reason_code: reasonCode, vat_unlock_requested: vatUnlock })
      onMessage({ tone: 'info', text: result.approval ? `Reopen always requires a second approver. Share approval id ${result.approval.id} with them on the Approvals page.` : 'Period reopened.' })
      setOpen(false)
      await onDone()
    } catch (error) {
      onMessage({ tone: 'error', text: error instanceof ApiRequestError ? error.message : 'Reopen failed.' })
    } finally {
      setSubmitting(false)
    }
  }

  if (!open) return <Button variant="danger" size="sm" onClick={() => { setOpen(true) }}>Reopen</Button>

  return (
    <form className="ml-auto flex flex-col items-end gap-2 rounded-md border border-[var(--color-border)] bg-[var(--color-surface-subtle)] p-2" onSubmit={submit}>
      <Input placeholder="Configured reason code" value={reasonCode} onChange={(event) => { setReasonCode(event.target.value) }} required className="w-56" />
      <label className="flex items-center gap-2 text-xs">
        <Checkbox checked={vatUnlock} onCheckedChange={(value) => { setVatUnlock(value === true) }} />
        Request VAT unlock
      </label>
      <div className="flex gap-2">
        <Button type="button" variant="ghost" size="sm" onClick={() => { setOpen(false) }}>Cancel</Button>
        <Button type="submit" variant="danger" size="sm" disabled={submitting}>{submitting ? 'Submitting…' : 'Confirm reopen'}</Button>
      </div>
    </form>
  )
}
