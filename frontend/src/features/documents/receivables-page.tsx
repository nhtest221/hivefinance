import { ChevronRight, Plus, Trash2 } from 'lucide-react'
import { type FormEvent, useCallback, useEffect, useState } from 'react'

import {
  Alert,
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
  DialogTitle as DrawerTitle,
  Drawer,
  DrawerContent,
  EmptyState,
  ErrorState,
  Input,
  LoadingState,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Table,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  Textarea,
} from '@/design-system'
import { AppLayout } from '@/layouts/app-layout'
import { hasPermission } from '@/features/identity/permissions'
import { ApiRequestError } from '@/features/identity/auth-api'
import { documentsApi, type Customer, type Invoice } from './documents-api'

const invoiceStatusVariant: Record<Invoice['status'], 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  draft: 'neutral',
  sent: 'info',
  partially_paid: 'warning',
  paid: 'success',
  void: 'danger',
}

export function ReceivablesPage() {
  const canRead = hasPermission('receivables.customers.read') || hasPermission('receivables.invoices.read')
  const canManage = hasPermission('receivables.customers.manage')
  const canCreate = hasPermission('receivables.invoices.create')
  const canIssue = hasPermission('receivables.invoices.issue')
  const canVoid = hasPermission('receivables.invoices.void')

  const [customers, setCustomers] = useState<Customer[]>([])
  const [invoices, setInvoices] = useState<Invoice[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [editing, setEditing] = useState<Invoice | null>(null)
  const [voiding, setVoiding] = useState<Invoice | null>(null)
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null)

  const load = useCallback(async () => {
    if (!canRead) return
    setLoading(true)
    setError(null)
    try {
      const [c, i] = await Promise.all([documentsApi.customers(), documentsApi.invoices()])
      setCustomers(c.data.customers)
      setInvoices(i.data.invoices)
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.message : 'Unable to load receivables.')
    } finally {
      setLoading(false)
    }
  }, [canRead])
  useEffect(() => { void load() }, [load])

  async function openPdf(invoice: Invoice) {
    try {
      const blob = await documentsApi.invoicePdf(invoice)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener,noreferrer')
      window.setTimeout(() => { URL.revokeObjectURL(url) }, 60_000)
    } catch (err) {
      setMessage(err instanceof ApiRequestError ? err.message : 'PDF retrieval failed.')
    }
  }

  async function openCustomer(customer: Customer) {
    try {
      setSelectedCustomer((await documentsApi.customer(customer.id)).data.customer)
    } catch (err) {
      setMessage(err instanceof ApiRequestError ? err.message : 'Unable to load customer detail.')
    }
  }

  async function issue(invoice: Invoice) {
    try {
      await documentsApi.issueInvoice(invoice)
      setMessage(`Invoice ${invoice.document_number ?? invoice.id.slice(0, 8)} issued.`)
      await load()
    } catch (err) {
      setMessage(err instanceof ApiRequestError ? err.message : 'Issue failed.')
    }
  }

  if (!canRead) {
    return (
      <AppLayout>
        <PageHeader title="Receivables" description="Customer masters and invoice drafting, issue, void, and PDF access." />
        <div className="p-4 lg:p-6"><Alert>You do not have permission to view receivables.</Alert></div>
      </AppLayout>
    )
  }

  return (
    <AppLayout>
      <PageHeader title="Receivables" description="Customer masters and invoice drafting, issue, void, and PDF access." />
      <div className="space-y-4 p-4 lg:p-6">
        {message ? <Alert>{message}</Alert> : null}
        {error ? <ErrorState description={error} /> : null}

        <Tabs defaultValue="invoices">
          <TabsList>
            <TabsTrigger value="invoices">Invoices</TabsTrigger>
            <TabsTrigger value="customers">Customers</TabsTrigger>
          </TabsList>

          <TabsContent value="invoices" className="space-y-4">
            {canCreate ? <InvoiceForm customers={customers} onDone={load} onError={setMessage} /> : null}
            <Card className="overflow-hidden">
              <CardContent className="p-0">
                {loading ? (
                  <div className="p-6"><LoadingState label="Loading invoices" /></div>
                ) : invoices.length === 0 ? (
                  <div className="p-6"><EmptyState title="No invoices yet" description="Draft your first invoice using the form above." /></div>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Number</TableHead>
                        <TableHead>Date</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="text-right">Total</TableHead>
                        <TableHead className="text-right">Open balance</TableHead>
                        <TableHead className="text-right">Actions</TableHead>
                      </TableRow>
                    </TableHeader>
                    <tbody>
                      {invoices.map((invoice) => (
                        <TableRow key={invoice.id}>
                          <TableCell className="font-medium">{invoice.document_number ?? 'Draft'}</TableCell>
                          <TableCell className="text-[var(--color-text-muted)]">{invoice.invoice_date}</TableCell>
                          <TableCell><Badge variant={invoiceStatusVariant[invoice.status]}>{invoice.status.replace(/_/g, ' ')}</Badge></TableCell>
                          <TableCell className="text-right tabular-nums">{invoice.total.currency} {invoice.total.amount}</TableCell>
                          <TableCell className="text-right tabular-nums">{invoice.open_balance.currency} {invoice.open_balance.amount}</TableCell>
                          <TableCell className="text-right space-x-2">
                            {invoice.status === 'draft' && canCreate ? <Button variant="secondary" size="sm" onClick={() => { setEditing(invoice) }}>Edit</Button> : null}
                            {invoice.status === 'draft' && canIssue ? <Button variant="secondary" size="sm" onClick={() => void issue(invoice)}>Issue</Button> : null}
                            {invoice.status === 'sent' ? <Button variant="secondary" size="sm" onClick={() => void openPdf(invoice)}>PDF</Button> : null}
                            {(invoice.status === 'draft' || invoice.status === 'sent') && canVoid ? <Button variant="danger" size="sm" onClick={() => { setVoiding(invoice) }}>Void</Button> : null}
                          </TableCell>
                        </TableRow>
                      ))}
                    </tbody>
                  </Table>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="customers" className="space-y-4">
            {canManage ? <CustomerForm onDone={load} onError={setMessage} /> : null}
            <Card className="overflow-hidden">
              <CardContent className="p-0">
                {loading ? (
                  <div className="p-6"><LoadingState label="Loading customers" /></div>
                ) : customers.length === 0 ? (
                  <div className="p-6"><EmptyState title="No customers yet" description="Add your first customer using the form above." /></div>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Currency</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="text-right">Actions</TableHead>
                      </TableRow>
                    </TableHeader>
                    <tbody>
                      {customers.map((customer) => (
                        <TableRow key={customer.id}>
                          <TableCell className="font-medium">
                            <button type="button" className="flex items-center gap-1 hover:underline" onClick={() => void openCustomer(customer)}>
                              {customer.name}
                              <ChevronRight className="size-3.5 text-[var(--color-text-muted)]" />
                            </button>
                          </TableCell>
                          <TableCell className="text-[var(--color-text-muted)]">{customer.type}</TableCell>
                          <TableCell>{customer.default_currency}</TableCell>
                          <TableCell><Badge variant={customer.status === 'active' ? 'success' : 'neutral'}>{customer.status}</Badge></TableCell>
                          <TableCell className="text-right">
                            {customer.status === 'active' && canManage ? (
                              <Button variant="secondary" size="sm" onClick={() => void documentsApi.deactivateCustomer(customer).then(load).catch((err: unknown) => { setMessage(err instanceof ApiRequestError ? err.message : 'Deactivation failed.') })}>
                                Deactivate
                              </Button>
                            ) : null}
                          </TableCell>
                        </TableRow>
                      ))}
                    </tbody>
                  </Table>
                )}
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </div>

      <EditInvoiceDialog invoice={editing} onClose={() => { setEditing(null) }} onDone={load} onError={setMessage} />
      <VoidInvoiceDialog invoice={voiding} onClose={() => { setVoiding(null) }} onDone={load} onError={setMessage} />

      <Drawer open={selectedCustomer !== null} onOpenChange={(open) => { if (!open) setSelectedCustomer(null) }}>
        <DrawerContent>
          {selectedCustomer ? (
            <CustomerDetailDrawer
              customer={selectedCustomer}
              invoices={invoices.filter((invoice) => invoice.customer_id === selectedCustomer.id)}
              canManage={canManage}
              onUpdated={(updated) => { setSelectedCustomer(updated); void load() }}
              onError={setMessage}
            />
          ) : null}
        </DrawerContent>
      </Drawer>
    </AppLayout>
  )
}

function CustomerDetailDrawer({ customer, invoices, canManage, onUpdated, onError }: { customer: Customer; invoices: Invoice[]; canManage: boolean; onUpdated: (customer: Customer) => void; onError: (value: string | null) => void }) {
  const [editing, setEditing] = useState(false)
  const [name, setName] = useState(customer.name)
  const [currency, setCurrency] = useState(customer.default_currency)
  const [terms, setTerms] = useState(customer.payment_terms)
  const [jurisdiction, setJurisdiction] = useState(customer.jurisdiction ?? '')
  const [taxId, setTaxId] = useState(customer.tax_identifier ?? '')
  const [email, setEmail] = useState(customer.contact?.email ?? '')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    setEditing(false)
    setName(customer.name)
    setCurrency(customer.default_currency)
    setTerms(customer.payment_terms)
    setJurisdiction(customer.jurisdiction ?? '')
    setTaxId(customer.tax_identifier ?? '')
    setEmail(customer.contact?.email ?? '')
  }, [customer])

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    try {
      const updated = await documentsApi.updateCustomer(customer, {
        name, default_currency: currency, payment_terms: terms,
        jurisdiction: jurisdiction || null, tax_identifier: taxId || null,
        contact: email ? { email, phone: customer.contact?.phone ?? null } : null,
      })
      onUpdated(updated.data.customer)
      setEditing(false)
    } catch (error) {
      onError(error instanceof ApiRequestError ? error.message : 'Customer update failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex h-full flex-col gap-4 overflow-y-auto">
      <div className="flex items-start justify-between gap-2">
        <div>
          <DrawerTitle className="text-lg font-semibold">{customer.name}</DrawerTitle>
          <div className="mt-2 flex items-center gap-2">
            <Badge variant={customer.status === 'active' ? 'success' : 'neutral'}>{customer.status}</Badge>
            <span className="text-xs text-[var(--color-text-muted)] capitalize">{customer.type}</span>
          </div>
        </div>
        {canManage && customer.status === 'active' && !editing ? (
          <Button variant="secondary" size="sm" onClick={() => { setEditing(true) }}>Edit</Button>
        ) : null}
      </div>

      {editing ? (
        <form className="space-y-3 rounded-md border border-[var(--color-border)] p-3" onSubmit={(event) => void submit(event)}>
          <Input placeholder="Name" value={name} onChange={(event) => { setName(event.target.value) }} required />
          <div className="grid grid-cols-2 gap-2">
            <Input placeholder="Currency" value={currency} onChange={(event) => { setCurrency(event.target.value.toUpperCase()) }} maxLength={3} required />
            <Input placeholder="Payment terms" value={terms} onChange={(event) => { setTerms(event.target.value) }} required />
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Input placeholder="Jurisdiction (optional)" value={jurisdiction} onChange={(event) => { setJurisdiction(event.target.value) }} />
            <Input placeholder="Tax identifier (optional)" value={taxId} onChange={(event) => { setTaxId(event.target.value) }} />
          </div>
          <Input placeholder="Contact email (optional)" value={email} onChange={(event) => { setEmail(event.target.value) }} />
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => { setEditing(false) }}>Cancel</Button>
            <Button type="submit" disabled={submitting}>{submitting ? 'Saving…' : 'Save'}</Button>
          </div>
        </form>
      ) : (
        <dl className="grid grid-cols-2 gap-3 rounded-md border border-[var(--color-border)] p-3 text-sm">
          <div><dt className="text-[var(--color-text-muted)]">Currency</dt><dd>{customer.default_currency}</dd></div>
          <div><dt className="text-[var(--color-text-muted)]">Payment terms</dt><dd>{customer.payment_terms}</dd></div>
          <div><dt className="text-[var(--color-text-muted)]">Jurisdiction</dt><dd>{customer.jurisdiction ?? '—'}</dd></div>
          <div><dt className="text-[var(--color-text-muted)]">Tax identifier</dt><dd>{customer.tax_identifier ?? '—'}</dd></div>
          <div className="col-span-2"><dt className="text-[var(--color-text-muted)]">Contact email</dt><dd>{customer.contact?.email ?? '—'}</dd></div>
        </dl>
      )}

      <section>
        <h3 className="mb-2 text-sm font-semibold">Invoices ({invoices.length})</h3>
        {invoices.length === 0 ? (
          <p className="text-sm text-[var(--color-text-muted)]">No invoices for this customer yet.</p>
        ) : (
          <ul className="space-y-2">
            {invoices.map((invoice) => (
              <li key={invoice.id} className="flex items-center justify-between rounded-md border border-[var(--color-border)] p-2.5 text-sm">
                <div>
                  <p className="font-medium">{invoice.document_number ?? 'Draft'}</p>
                  <p className="text-xs text-[var(--color-text-muted)]">{invoice.invoice_date}</p>
                </div>
                <div className="text-right">
                  <Badge variant={invoiceStatusVariant[invoice.status]}>{invoice.status.replace(/_/g, ' ')}</Badge>
                  <p className="mt-1 text-xs tabular-nums text-[var(--color-text-muted)]">{invoice.open_balance.currency} {invoice.open_balance.amount} open</p>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

function CustomerForm({ onDone, onError }: { onDone: () => Promise<void>; onError: (value: string | null) => void }) {
  const [name, setName] = useState('')
  const [currency, setCurrency] = useState('')
  const [terms, setTerms] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    try {
      await documentsApi.createCustomer({ name, type: 'local', default_currency: currency, payment_terms: terms })
      setName('')
      setCurrency('')
      setTerms('')
      await onDone()
    } catch (error) {
      onError(error instanceof ApiRequestError ? error.message : 'Customer creation failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <CardHeader><h2 className="font-semibold">Create customer</h2></CardHeader>
      <CardContent>
        <form className="grid gap-3 md:grid-cols-4" onSubmit={submit}>
          <Input placeholder="Name" value={name} onChange={(e) => { setName(e.target.value) }} required />
          <Input placeholder="Currency (e.g. BDT)" value={currency} onChange={(e) => { setCurrency(e.target.value.toUpperCase()) }} maxLength={3} required />
          <Input placeholder="Configured payment terms key" value={terms} onChange={(e) => { setTerms(e.target.value) }} required />
          <Button type="submit" disabled={submitting}>{submitting ? 'Creating…' : 'Create'}</Button>
        </form>
      </CardContent>
    </Card>
  )
}

type DraftLine = { description: string; quantity: string; unitPrice: string }
const emptyLine = (): DraftLine => ({ description: '', quantity: '1.0000', unitPrice: '' })

function InvoiceForm({ customers, onDone, onError }: { customers: Customer[]; onDone: () => Promise<void>; onError: (value: string | null) => void }) {
  const [customerId, setCustomerId] = useState('')
  const [date, setDate] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine()])
  const [submitting, setSubmitting] = useState(false)
  const selected = customers.find((c) => c.id === customerId)

  function updateLine(index: number, patch: Partial<DraftLine>) {
    setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!selected) return
    setSubmitting(true)
    try {
      await documentsApi.createInvoice({
        customer_id: customerId,
        invoice_date: date,
        currency: selected.default_currency,
        lines: lines.map((line) => ({ description: line.description, quantity: line.quantity, unit_price: { amount: line.unitPrice, currency: selected.default_currency }, tax_code_id: null })),
      })
      setLines([emptyLine()])
      await onDone()
    } catch (error) {
      onError(error instanceof ApiRequestError ? error.message : 'Invoice creation failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <CardHeader><h2 className="font-semibold">Create invoice draft</h2></CardHeader>
      <CardContent>
        <form className="space-y-3" onSubmit={submit}>
          <div className="grid gap-3 md:grid-cols-2">
            <Select value={customerId} onValueChange={setCustomerId}>
              <SelectTrigger><SelectValue placeholder="Customer" /></SelectTrigger>
              <SelectContent>
                {customers.map((customer) => <SelectItem key={customer.id} value={customer.id}>{customer.name}</SelectItem>)}
              </SelectContent>
            </Select>
            <Input type="date" value={date} onChange={(e) => { setDate(e.target.value) }} required />
          </div>

          <div className="space-y-2">
            {lines.map((line, index) => (
              <div className="grid grid-cols-[1fr_6rem_8rem_auto] gap-2" key={index}>
                <Input placeholder="Description" value={line.description} onChange={(e) => { updateLine(index, { description: e.target.value }) }} required />
                <Input placeholder="Qty" value={line.quantity} onChange={(e) => { updateLine(index, { quantity: e.target.value }) }} required />
                <Input placeholder={`Unit price${selected ? ` (${selected.default_currency})` : ''}`} value={line.unitPrice} onChange={(e) => { updateLine(index, { unitPrice: e.target.value }) }} required />
                <Button type="button" variant="ghost" size="sm" disabled={lines.length === 1} onClick={() => { setLines((current) => current.filter((_, i) => i !== index)) }}>
                  <Trash2 className="size-4" />
                </Button>
              </div>
            ))}
            <Button type="button" variant="secondary" size="sm" onClick={() => { setLines((current) => [...current, emptyLine()]) }}>
              <Plus className="size-4" /> Add line
            </Button>
          </div>

          <Button type="submit" disabled={submitting || !selected}>{submitting ? 'Saving…' : 'Save draft'}</Button>
        </form>
      </CardContent>
    </Card>
  )
}

function EditInvoiceDialog({ invoice, onClose, onDone, onError }: { invoice: Invoice | null; onClose: () => void; onDone: () => Promise<void>; onError: (value: string | null) => void }) {
  const [notes, setNotes] = useState('')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => { setNotes('') }, [invoice])

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!invoice) return
    setSubmitting(true)
    try {
      await documentsApi.updateInvoice(invoice, { notes: notes || null })
      onClose()
      await onDone()
    } catch (error) {
      onError(error instanceof ApiRequestError ? error.message : 'Draft update failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={invoice !== null} onOpenChange={(open) => { if (!open) onClose() }}>
      <DialogContent>
        <DialogTitle>Edit draft invoice</DialogTitle>
        <DialogDescription>Update the draft notes for {invoice?.document_number ?? 'this invoice'}.</DialogDescription>
        <form className="mt-4 space-y-3" onSubmit={submit}>
          <Textarea placeholder="Notes" value={notes} onChange={(e) => { setNotes(e.target.value) }} />
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={submitting}>{submitting ? 'Saving…' : 'Save'}</Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function VoidInvoiceDialog({ invoice, onClose, onDone, onError }: { invoice: Invoice | null; onClose: () => void; onDone: () => Promise<void>; onError: (value: string | null) => void }) {
  const [reasonCode, setReasonCode] = useState('')
  const [narrative, setNarrative] = useState('')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => { setReasonCode(''); setNarrative('') }, [invoice])

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!invoice) return
    setSubmitting(true)
    try {
      const result = await documentsApi.voidInvoice(invoice, { void_date: new Date().toISOString().slice(0, 10), reason_code: reasonCode, narrative })
      onError(result.status === 202 && result.data.approval ? `Approval ${result.data.approval.id} is pending; the invoice remains posted.` : 'Invoice voided.')
      onClose()
      await onDone()
    } catch (error) {
      onError(error instanceof ApiRequestError ? error.message : 'Void failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={invoice !== null} onOpenChange={(open) => { if (!open) onClose() }}>
      <DialogContent>
        <DialogTitle>Void invoice</DialogTitle>
        <DialogDescription>
          This permanently voids {invoice?.document_number ?? 'this invoice'}
          {invoice?.status === 'sent' ? ' and creates a reversing entry — it cannot be undone.' : '.'}
        </DialogDescription>
        <form className="mt-4 space-y-3" onSubmit={submit}>
          <label className="block space-y-1 text-sm">
            <span className="font-medium text-[var(--color-text)]">Reason code</span>
            <Input placeholder="Configured reason code" value={reasonCode} onChange={(e) => { setReasonCode(e.target.value) }} required />
          </label>
          <label className="block space-y-1 text-sm">
            <span className="font-medium text-[var(--color-text)]">Narrative</span>
            <Textarea placeholder="Why is this being voided?" value={narrative} onChange={(e) => { setNarrative(e.target.value) }} required />
          </label>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
            <Button type="submit" variant="danger" disabled={submitting}>{submitting ? 'Voiding…' : 'Void invoice'}</Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
