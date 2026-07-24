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
import { documentsApi, type Bill, type Expense, type Vendor } from './documents-api'
import { listAccounts, type Account } from '@/features/ledger/ledger-api'

const billStatusVariant: Record<Bill['status'], 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  draft: 'neutral',
  awaiting_payment: 'info',
  partially_paid: 'warning',
  paid: 'success',
  void: 'danger',
}

export function PayablesPage() {
  const canRead = hasPermission('payables.vendors.read') || hasPermission('payables.bills.read') || hasPermission('payables.expenses.read')
  const canVendor = hasPermission('payables.vendors.manage')
  const canBill = hasPermission('payables.bills.create')
  const canApprove = hasPermission('payables.bills.approve')
  const canVoid = hasPermission('payables.bills.void')
  const canExpense = hasPermission('payables.expenses.create')

  const [vendors, setVendors] = useState<Vendor[]>([])
  const [bills, setBills] = useState<Bill[]>([])
  const [expenses, setExpenses] = useState<Expense[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [editing, setEditing] = useState<Bill | null>(null)
  const [voiding, setVoiding] = useState<Bill | null>(null)
  const [selectedVendor, setSelectedVendor] = useState<Vendor | null>(null)

  const load = useCallback(async () => {
    if (!canRead) return
    setLoading(true)
    setError(null)
    try {
      const [v, b, e, a] = await Promise.all([documentsApi.vendors(), documentsApi.bills(), documentsApi.expenses(), listAccounts()])
      setVendors(v.data.vendors)
      setBills(b.data.bills)
      setExpenses(e.data.expenses)
      setAccounts(a.data.accounts)
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.message : 'Unable to load payables.')
    } finally {
      setLoading(false)
    }
  }, [canRead])
  useEffect(() => { void load() }, [load])

  async function openVendor(vendor: Vendor) {
    try {
      setSelectedVendor((await documentsApi.vendor(vendor.id)).data.vendor)
    } catch (err) {
      setMessage(err instanceof ApiRequestError ? err.message : 'Unable to load vendor detail.')
    }
  }

  async function approve(bill: Bill) {
    try {
      const result = await documentsApi.approveBill(bill)
      setMessage(result.status === 202 && result.data.approval ? `Approval ${result.data.approval.id} is pending. No bill posting has occurred.` : 'Bill approved and posted.')
      await load()
    } catch (err) {
      setMessage(err instanceof ApiRequestError ? err.message : 'Bill approval failed.')
    }
  }

  if (!canRead) {
    return (
      <AppLayout>
        <PageHeader title="Payables" description="Vendor masters, bill drafting and approval, and recorded expenses." />
        <div className="p-4 lg:p-6"><Alert>You do not have permission to view payables.</Alert></div>
      </AppLayout>
    )
  }

  return (
    <AppLayout>
      <PageHeader title="Payables" description="Vendor masters, bill drafting and approval, and recorded expenses." />
      <div className="space-y-4 p-4 lg:p-6">
        {message ? <Alert>{message}</Alert> : null}
        {error ? <ErrorState description={error} /> : null}

        <Tabs defaultValue="bills">
          <TabsList>
            <TabsTrigger value="bills">Bills</TabsTrigger>
            <TabsTrigger value="expenses">Expenses</TabsTrigger>
            <TabsTrigger value="vendors">Vendors</TabsTrigger>
          </TabsList>

          <TabsContent value="bills" className="space-y-4">
            {canBill ? <BillForm vendors={vendors} accounts={accounts} onDone={load} onError={setMessage} /> : null}
            <Card className="overflow-hidden">
              <CardContent className="p-0">
                {loading ? (
                  <div className="p-6"><LoadingState label="Loading bills" /></div>
                ) : bills.length === 0 ? (
                  <div className="p-6"><EmptyState title="No bills yet" description="Draft your first bill using the form above." /></div>
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
                      {bills.map((bill) => (
                        <TableRow key={bill.id}>
                          <TableCell className="font-medium">{bill.document_number ?? 'Draft'}</TableCell>
                          <TableCell className="text-[var(--color-text-muted)]">{bill.bill_date}</TableCell>
                          <TableCell><Badge variant={billStatusVariant[bill.status]}>{bill.status.replace(/_/g, ' ')}</Badge></TableCell>
                          <TableCell className="text-right tabular-nums">{bill.total.currency} {bill.total.amount}</TableCell>
                          <TableCell className="text-right tabular-nums">{bill.open_balance.currency} {bill.open_balance.amount}</TableCell>
                          <TableCell className="text-right space-x-2">
                            {bill.status === 'draft' && canBill ? <Button variant="secondary" size="sm" onClick={() => { setEditing(bill) }}>Edit</Button> : null}
                            {bill.status === 'draft' && canApprove ? <Button variant="secondary" size="sm" onClick={() => void approve(bill)}>Approve</Button> : null}
                            {(bill.status === 'draft' || bill.status === 'awaiting_payment') && canVoid ? <Button variant="danger" size="sm" onClick={() => { setVoiding(bill) }}>Void</Button> : null}
                          </TableCell>
                        </TableRow>
                      ))}
                    </tbody>
                  </Table>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="expenses" className="space-y-4">
            {canExpense ? <ExpenseForm vendors={vendors} accounts={accounts} onDone={load} onError={setMessage} /> : null}
            <Card className="overflow-hidden">
              <CardContent className="p-0">
                {loading ? (
                  <div className="p-6"><LoadingState label="Loading expenses" /></div>
                ) : expenses.length === 0 ? (
                  <div className="p-6"><EmptyState title="No expenses recorded yet" description="Record your first expense using the form above." /></div>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Date</TableHead>
                        <TableHead>Description</TableHead>
                        <TableHead>Settlement</TableHead>
                        <TableHead className="text-right">Amount</TableHead>
                      </TableRow>
                    </TableHeader>
                    <tbody>
                      {expenses.map((expense) => (
                        <TableRow key={expense.id}>
                          <TableCell className="text-[var(--color-text-muted)]">{expense.expense_date}</TableCell>
                          <TableCell>{expense.description}</TableCell>
                          <TableCell><Badge variant="neutral">{expense.settlement_type}</Badge></TableCell>
                          <TableCell className="text-right tabular-nums">{expense.amount.currency} {expense.amount.amount}</TableCell>
                        </TableRow>
                      ))}
                    </tbody>
                  </Table>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="vendors" className="space-y-4">
            {canVendor ? <VendorForm onDone={load} onError={setMessage} /> : null}
            <Card className="overflow-hidden">
              <CardContent className="p-0">
                {loading ? (
                  <div className="p-6"><LoadingState label="Loading vendors" /></div>
                ) : vendors.length === 0 ? (
                  <div className="p-6"><EmptyState title="No vendors yet" description="Add your first vendor using the form above." /></div>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Currency</TableHead>
                        <TableHead>Terms</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="text-right">Actions</TableHead>
                      </TableRow>
                    </TableHeader>
                    <tbody>
                      {vendors.map((vendor) => (
                        <TableRow key={vendor.id}>
                          <TableCell className="font-medium">
                            <button type="button" className="flex items-center gap-1 hover:underline" onClick={() => void openVendor(vendor)}>
                              {vendor.name}
                              <ChevronRight className="size-3.5 text-[var(--color-text-muted)]" />
                            </button>
                          </TableCell>
                          <TableCell>{vendor.default_currency}</TableCell>
                          <TableCell className="text-[var(--color-text-muted)]">{vendor.payment_terms}</TableCell>
                          <TableCell><Badge variant={vendor.status === 'active' ? 'success' : 'neutral'}>{vendor.status}</Badge></TableCell>
                          <TableCell className="text-right">
                            {vendor.status === 'active' && canVendor ? (
                              <Button variant="secondary" size="sm" onClick={() => void documentsApi.deactivateVendor(vendor).then(load).catch((err: unknown) => { setMessage(err instanceof ApiRequestError ? err.message : 'Deactivation failed.') })}>
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

      <EditBillDialog bill={editing} onClose={() => { setEditing(null) }} onDone={load} onError={setMessage} />
      <VoidBillDialog bill={voiding} onClose={() => { setVoiding(null) }} onDone={load} onError={setMessage} />

      <Drawer open={selectedVendor !== null} onOpenChange={(open) => { if (!open) setSelectedVendor(null) }}>
        <DrawerContent>
          {selectedVendor ? (
            <VendorDetailDrawer
              vendor={selectedVendor}
              bills={bills.filter((bill) => bill.vendor_id === selectedVendor.id)}
              canManage={canVendor}
              onUpdated={(updated) => { setSelectedVendor(updated); void load() }}
              onError={setMessage}
            />
          ) : null}
        </DrawerContent>
      </Drawer>
    </AppLayout>
  )
}

function VendorDetailDrawer({ vendor, bills, canManage, onUpdated, onError }: { vendor: Vendor; bills: Bill[]; canManage: boolean; onUpdated: (vendor: Vendor) => void; onError: (value: string | null) => void }) {
  const [editing, setEditing] = useState(false)
  const [name, setName] = useState(vendor.name)
  const [currency, setCurrency] = useState(vendor.default_currency)
  const [terms, setTerms] = useState(vendor.payment_terms)
  const [jurisdiction, setJurisdiction] = useState(vendor.jurisdiction ?? '')
  const [taxId, setTaxId] = useState(vendor.tax_identifier ?? '')
  const [email, setEmail] = useState(vendor.contact?.email ?? '')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    setEditing(false)
    setName(vendor.name)
    setCurrency(vendor.default_currency)
    setTerms(vendor.payment_terms)
    setJurisdiction(vendor.jurisdiction ?? '')
    setTaxId(vendor.tax_identifier ?? '')
    setEmail(vendor.contact?.email ?? '')
  }, [vendor])

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    try {
      const updated = await documentsApi.updateVendor(vendor, {
        name, default_currency: currency, payment_terms: terms,
        jurisdiction: jurisdiction || null, tax_identifier: taxId || null,
        contact: email ? { email, phone: vendor.contact?.phone ?? null } : null,
      })
      onUpdated(updated.data.vendor)
      setEditing(false)
    } catch (error) {
      onError(error instanceof ApiRequestError ? error.message : 'Vendor update failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex h-full flex-col gap-4 overflow-y-auto">
      <div className="flex items-start justify-between gap-2">
        <div>
          <DrawerTitle className="text-lg font-semibold">{vendor.name}</DrawerTitle>
          <div className="mt-2">
            <Badge variant={vendor.status === 'active' ? 'success' : 'neutral'}>{vendor.status}</Badge>
          </div>
        </div>
        {canManage && vendor.status === 'active' && !editing ? (
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
          <p className="text-xs text-[var(--color-text-muted)]">Bank details are not editable here; raw account identifiers are never returned by the API once saved.</p>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => { setEditing(false) }}>Cancel</Button>
            <Button type="submit" disabled={submitting}>{submitting ? 'Saving…' : 'Save'}</Button>
          </div>
        </form>
      ) : (
        <dl className="grid grid-cols-2 gap-3 rounded-md border border-[var(--color-border)] p-3 text-sm">
          <div><dt className="text-[var(--color-text-muted)]">Currency</dt><dd>{vendor.default_currency}</dd></div>
          <div><dt className="text-[var(--color-text-muted)]">Payment terms</dt><dd>{vendor.payment_terms}</dd></div>
          <div><dt className="text-[var(--color-text-muted)]">Jurisdiction</dt><dd>{vendor.jurisdiction ?? '—'}</dd></div>
          <div><dt className="text-[var(--color-text-muted)]">Tax identifier</dt><dd>{vendor.tax_identifier ?? '—'}</dd></div>
          <div className="col-span-2"><dt className="text-[var(--color-text-muted)]">Contact email</dt><dd>{vendor.contact?.email ?? '—'}</dd></div>
          {vendor.bank_details ? (
            <div className="col-span-2">
              <dt className="text-[var(--color-text-muted)]">Bank details</dt>
              <dd>{vendor.bank_details.institution_name} · {vendor.bank_details.account_identifier_masked}</dd>
            </div>
          ) : null}
        </dl>
      )}

      <section>
        <h3 className="mb-2 text-sm font-semibold">Bills ({bills.length})</h3>
        {bills.length === 0 ? (
          <p className="text-sm text-[var(--color-text-muted)]">No bills for this vendor yet.</p>
        ) : (
          <ul className="space-y-2">
            {bills.map((bill) => (
              <li key={bill.id} className="flex items-center justify-between rounded-md border border-[var(--color-border)] p-2.5 text-sm">
                <div>
                  <p className="font-medium">{bill.document_number ?? 'Draft'}</p>
                  <p className="text-xs text-[var(--color-text-muted)]">{bill.bill_date}</p>
                </div>
                <div className="text-right">
                  <Badge variant={billStatusVariant[bill.status]}>{bill.status.replace(/_/g, ' ')}</Badge>
                  <p className="mt-1 text-xs tabular-nums text-[var(--color-text-muted)]">{bill.open_balance.currency} {bill.open_balance.amount} open</p>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

function VendorForm({ onDone, onError }: { onDone: () => Promise<void>; onError: (v: string | null) => void }) {
  const [name, setName] = useState('')
  const [currency, setCurrency] = useState('')
  const [terms, setTerms] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    try {
      await documentsApi.createVendor({ name, default_currency: currency, payment_terms: terms })
      setName('')
      await onDone()
    } catch (error) {
      onError(error instanceof ApiRequestError ? error.message : 'Vendor creation failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <CardHeader><h2 className="font-semibold">Create vendor</h2></CardHeader>
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

type DraftLine = { description: string; quantity: string; unitPrice: string; accountId: string }
const emptyLine = (): DraftLine => ({ description: '', quantity: '1.0000', unitPrice: '', accountId: '' })

function BillForm({ vendors, accounts, onDone, onError }: { vendors: Vendor[]; accounts: Account[]; onDone: () => Promise<void>; onError: (v: string | null) => void }) {
  const [vendorId, setVendorId] = useState('')
  const [date, setDate] = useState('')
  const [sbu, setSbu] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine()])
  const [submitting, setSubmitting] = useState(false)
  const selected = vendors.find((v) => v.id === vendorId)

  function updateLine(index: number, patch: Partial<DraftLine>) {
    setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!selected) return
    setSubmitting(true)
    try {
      await documentsApi.createBill({
        vendor_id: vendorId,
        bill_date: date,
        currency: selected.default_currency,
        lines: lines.map((line) => ({ description: line.description, quantity: line.quantity, unit_price: { amount: line.unitPrice, currency: selected.default_currency }, expense_account_id: line.accountId, tax_code_id: null })),
        sbu_allocations: [{ sbu_code: sbu, weight: '1.0000' }],
      })
      setLines([emptyLine()])
      await onDone()
    } catch (error) {
      onError(error instanceof ApiRequestError ? error.message : 'Bill creation failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <CardHeader><h2 className="font-semibold">Create bill draft</h2></CardHeader>
      <CardContent>
        <form className="space-y-3" onSubmit={submit}>
          <div className="grid gap-3 md:grid-cols-3">
            <Select value={vendorId} onValueChange={setVendorId}>
              <SelectTrigger><SelectValue placeholder="Vendor" /></SelectTrigger>
              <SelectContent>
                {vendors.map((vendor) => <SelectItem key={vendor.id} value={vendor.id}>{vendor.name}</SelectItem>)}
              </SelectContent>
            </Select>
            <Input type="date" value={date} onChange={(e) => { setDate(e.target.value) }} required />
            <Input placeholder="Configured SBU code" value={sbu} onChange={(e) => { setSbu(e.target.value) }} required />
          </div>

          <div className="space-y-2">
            {lines.map((line, index) => (
              <div className="grid grid-cols-[1fr_5rem_7rem_1fr_auto] gap-2" key={index}>
                <Input placeholder="Description" value={line.description} onChange={(e) => { updateLine(index, { description: e.target.value }) }} required />
                <Input placeholder="Qty" value={line.quantity} onChange={(e) => { updateLine(index, { quantity: e.target.value }) }} required />
                <Input placeholder={`Price${selected ? ` (${selected.default_currency})` : ''}`} value={line.unitPrice} onChange={(e) => { updateLine(index, { unitPrice: e.target.value }) }} required />
                <Select value={line.accountId} onValueChange={(value) => { updateLine(index, { accountId: value }) }}>
                  <SelectTrigger><SelectValue placeholder="Expense account" /></SelectTrigger>
                  <SelectContent>
                    {accounts.map((account) => <SelectItem key={account.id} value={account.id}>{account.code} · {account.name}</SelectItem>)}
                  </SelectContent>
                </Select>
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

function ExpenseForm({ vendors, accounts, onDone, onError }: { vendors: Vendor[]; accounts: Account[]; onDone: () => Promise<void>; onError: (v: string | null) => void }) {
  const [date, setDate] = useState('')
  const [description, setDescription] = useState('')
  const [amount, setAmount] = useState('')
  const [currency, setCurrency] = useState('')
  const [accountId, setAccountId] = useState('')
  const [vendorId, setVendorId] = useState('')
  const [sbu, setSbu] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    try {
      await documentsApi.createExpense({ expense_date: date, description, category_account_id: accountId, settlement_type: 'accrued', vendor_id: vendorId, currency, amount: { amount, currency }, tax_code_id: null, ait: null, sbu_allocations: [{ sbu_code: sbu, weight: '1.0000' }] })
      setDescription('')
      setAmount('')
      await onDone()
    } catch (error) {
      onError(error instanceof ApiRequestError ? error.message : 'Expense creation failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <CardHeader><h2 className="font-semibold">Record accrued expense</h2></CardHeader>
      <CardContent>
        <form className="grid gap-3 md:grid-cols-3" onSubmit={submit}>
          <Input type="date" value={date} onChange={(e) => { setDate(e.target.value) }} required />
          <Select value={vendorId} onValueChange={setVendorId}>
            <SelectTrigger><SelectValue placeholder="Vendor" /></SelectTrigger>
            <SelectContent>
              {vendors.map((vendor) => <SelectItem key={vendor.id} value={vendor.id}>{vendor.name}</SelectItem>)}
            </SelectContent>
          </Select>
          <Input placeholder="Description" value={description} onChange={(e) => { setDescription(e.target.value) }} required />
          <Input placeholder="Currency (e.g. BDT)" value={currency} onChange={(e) => { setCurrency(e.target.value.toUpperCase()) }} maxLength={3} required />
          <Input placeholder="Amount" value={amount} onChange={(e) => { setAmount(e.target.value) }} required />
          <Select value={accountId} onValueChange={setAccountId}>
            <SelectTrigger><SelectValue placeholder="Expense account" /></SelectTrigger>
            <SelectContent>
              {accounts.map((account) => <SelectItem key={account.id} value={account.id}>{account.code} · {account.name}</SelectItem>)}
            </SelectContent>
          </Select>
          <Input placeholder="Configured SBU code" value={sbu} onChange={(e) => { setSbu(e.target.value) }} required />
          <Button type="submit" disabled={submitting}>{submitting ? 'Recording…' : 'Record'}</Button>
        </form>
      </CardContent>
    </Card>
  )
}

function EditBillDialog({ bill, onClose, onDone, onError }: { bill: Bill | null; onClose: () => void; onDone: () => Promise<void>; onError: (v: string | null) => void }) {
  const [notes, setNotes] = useState('')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => { setNotes('') }, [bill])

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!bill) return
    setSubmitting(true)
    try {
      await documentsApi.updateBill(bill, { notes: notes || null })
      onClose()
      await onDone()
    } catch (error) {
      onError(error instanceof ApiRequestError ? error.message : 'Draft update failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={bill !== null} onOpenChange={(open) => { if (!open) onClose() }}>
      <DialogContent>
        <DialogTitle>Edit draft bill</DialogTitle>
        <DialogDescription>Update the draft notes for {bill?.document_number ?? 'this bill'}.</DialogDescription>
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

function VoidBillDialog({ bill, onClose, onDone, onError }: { bill: Bill | null; onClose: () => void; onDone: () => Promise<void>; onError: (v: string | null) => void }) {
  const [reasonCode, setReasonCode] = useState('')
  const [narrative, setNarrative] = useState('')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => { setReasonCode(''); setNarrative('') }, [bill])

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!bill) return
    setSubmitting(true)
    try {
      const result = await documentsApi.voidBill(bill, { void_date: new Date().toISOString().slice(0, 10), reason_code: reasonCode, narrative })
      onError(result.status === 202 && result.data.approval ? `Approval ${result.data.approval.id} is pending; the bill remains posted.` : 'Bill voided.')
      onClose()
      await onDone()
    } catch (error) {
      onError(error instanceof ApiRequestError ? error.message : 'Void failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={bill !== null} onOpenChange={(open) => { if (!open) onClose() }}>
      <DialogContent>
        <DialogTitle>Void bill</DialogTitle>
        <DialogDescription>
          This permanently voids {bill?.document_number ?? 'this bill'}
          {bill?.status === 'awaiting_payment' ? ' and creates a reversing entry — it cannot be undone.' : '.'}
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
            <Button type="submit" variant="danger" disabled={submitting}>{submitting ? 'Voiding…' : 'Void bill'}</Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
