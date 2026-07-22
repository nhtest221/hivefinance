import { ApiRequestError } from '@/features/identity/auth-api'

export type Page = { limit: number; next_cursor: string | null }
export type Money = { amount: string; currency: string }
export type Customer = { id: string; name: string; type: 'local' | 'foreign'; default_currency: string; payment_terms: string; status: 'active' | 'deactivated'; version: number }
export type Vendor = { id: string; name: string; default_currency: string; payment_terms: string; status: 'active' | 'deactivated'; version: number }
export type Invoice = { id: string; document_number: string | null; customer_id: string; invoice_date: string; due_date: string; currency: string; total: Money; open_balance: Money; status: 'draft' | 'sent' | 'partially_paid' | 'paid' | 'void'; version: number }
export type Bill = { id: string; document_number: string | null; vendor_id: string; bill_date: string; due_date: string; currency: string; total: Money; open_balance: Money; status: 'draft' | 'awaiting_payment' | 'partially_paid' | 'paid' | 'void'; version: number }
export type VoidInput = { void_date: string; reason_code: string; narrative: string }
export type Expense = { id: string; expense_date: string; description: string; settlement_type: 'cash' | 'accrued'; amount: Money; status: 'recorded'; version: number }
export type ApprovalResponse = { approval: { id: string; status: 'pending'; version: number } }

export function commandId() { return crypto.randomUUID() }

async function request<T>(path: string, init: RequestInit = {}) {
  const response = await fetch(path, { ...init, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${sessionStorage.getItem('hivefinance.auth_token') ?? ''}`, 'X-Entity-Id': sessionStorage.getItem('hivefinance.entity_id') ?? '', 'X-Correlation-Id': commandId(), ...init.headers } })
  const data = (await response.json().catch(() => ({}))) as T & { message?: string; error_code?: string }
  if (!response.ok) throw new ApiRequestError(data.message ?? 'The document request failed.', response.status, data.error_code)
  return { data, status: response.status }
}

export const documentsApi = {
  customers: () => request<{ customers: Customer[]; page: Page }>('/v1/customers?limit=100'),
  createCustomer: (input: Omit<Customer, 'id' | 'status' | 'version'>) => request<{ customer: Customer }>('/v1/customers', { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(input) }),
  deactivateCustomer: (customer: Customer) => request<{ customer: Customer }>(`/v1/customers/${customer.id}/deactivate`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(customer.version) }, body: '{}' }),
  invoices: () => request<{ invoices: Invoice[]; page: Page }>('/v1/invoices?limit=100'),
  createInvoice: (input: { customer_id: string; invoice_date: string; due_date?: string; currency: string; lines: Array<{ description: string; quantity: string; unit_price: Money; tax_code_id: string | null }> }) => request<{ invoice: Invoice }>('/v1/invoices', { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(input) }),
  updateInvoice: (invoice: Invoice, input: { notes: string | null }) => request<{ invoice: Invoice }>(`/v1/invoices/${invoice.id}`, { method: 'PATCH', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(invoice.version) }, body: JSON.stringify(input) }),
  issueInvoice: (invoice: Invoice) => request<{ invoice: Invoice }>(`/v1/invoices/${invoice.id}/issue`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(invoice.version) }, body: '{}' }),
  voidInvoice: (invoice: Invoice, input: VoidInput) => request<{ invoice?: Invoice; reversal?: { journal_entry_id: string } | null; approval?: ApprovalResponse['approval'] }>(`/v1/invoices/${invoice.id}/void`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(invoice.version) }, body: JSON.stringify(input) }),
  invoicePdf: async (invoice: Invoice) => {
    const response = await fetch(`/v1/invoices/${invoice.id}/pdf`, { headers: { Accept: 'application/pdf', Authorization: `Bearer ${sessionStorage.getItem('hivefinance.auth_token') ?? ''}`, 'X-Entity-Id': sessionStorage.getItem('hivefinance.entity_id') ?? '', 'X-Correlation-Id': commandId() } })
    if (!response.ok) { const error = await response.json().catch(() => ({})) as { message?: string; error_code?: string }; throw new ApiRequestError(error.message ?? 'PDF retrieval failed.', response.status, error.error_code) }
    return response.blob()
  },
  vendors: () => request<{ vendors: Vendor[]; page: Page }>('/v1/vendors?limit=100'),
  createVendor: (input: Omit<Vendor, 'id' | 'status' | 'version'>) => request<{ vendor: Vendor }>('/v1/vendors', { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(input) }),
  deactivateVendor: (vendor: Vendor) => request<{ vendor: Vendor }>(`/v1/vendors/${vendor.id}/deactivate`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(vendor.version) }, body: '{}' }),
  bills: () => request<{ bills: Bill[]; page: Page }>('/v1/bills?limit=100'),
  createBill: (input: { vendor_id: string; bill_date: string; due_date?: string; currency: string; lines: Array<{ description: string; quantity: string; unit_price: Money; expense_account_id: string; tax_code_id: string | null }>; sbu_allocations: Array<{ sbu_code: string; weight: string }> }) => request<{ bill: Bill }>('/v1/bills', { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(input) }),
  updateBill: (bill: Bill, input: { notes: string | null }) => request<{ bill: Bill }>(`/v1/bills/${bill.id}`, { method: 'PATCH', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(bill.version) }, body: JSON.stringify(input) }),
  approveBill: (bill: Bill) => request<{ bill?: Bill; approval?: ApprovalResponse['approval'] }>(`/v1/bills/${bill.id}/approve`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(bill.version) }, body: '{}' }),
  voidBill: (bill: Bill, input: VoidInput) => request<{ bill?: Bill; reversal?: { journal_entry_id: string } | null; approval?: ApprovalResponse['approval'] }>(`/v1/bills/${bill.id}/void`, { method: 'POST', headers: { 'Idempotency-Key': commandId(), 'If-Match': String(bill.version) }, body: JSON.stringify(input) }),
  expenses: () => request<{ expenses: Expense[]; page: Page }>('/v1/expenses?limit=100'),
  createExpense: (input: { expense_date: string; description: string; category_account_id: string; settlement_type: 'cash' | 'accrued'; bank_account_id?: string; vendor_id?: string; currency: string; amount: Money; tax_code_id: null; ait: null; sbu_allocations: Array<{ sbu_code: string; weight: string }> }) => request<{ expense: Expense }>('/v1/expenses', { method: 'POST', headers: { 'Idempotency-Key': commandId() }, body: JSON.stringify(input) }),
}
