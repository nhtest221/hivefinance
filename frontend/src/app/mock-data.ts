export const kpis = [
  { label: 'Cash in bank', value: 'BDT 18.42M', delta: '+4.2%', tone: 'success' },
  { label: 'Outstanding receivables', value: 'BDT 9.84M', delta: '12 overdue', tone: 'warning' },
  { label: 'Bills to pay', value: 'BDT 3.18M', delta: '5 due this week', tone: 'neutral' },
  { label: 'Net profit YTD', value: 'BDT 6.71M', delta: 'Accrual', tone: 'info' },
] as const

export const cashTrend = [
  { month: 'Jan', inflow: 8.4, outflow: 4.1 },
  { month: 'Feb', inflow: 7.2, outflow: 4.6 },
  { month: 'Mar', inflow: 10.6, outflow: 5.3 },
  { month: 'Apr', inflow: 9.1, outflow: 5.8 },
  { month: 'May', inflow: 12.4, outflow: 6.0 },
  { month: 'Jun', inflow: 11.8, outflow: 5.5 },
]

export const accounts = [
  ['1010', 'Cash in Bank - NRB', 'Asset', 'Active', 'BDT 12.80M'],
  ['1060', 'Accounts Receivable', 'Asset', 'Active', 'BDT 9.84M'],
  ['2010', 'Accounts Payable', 'Liability', 'Active', 'BDT 3.18M'],
  ['4010', 'Client Service Revenue', 'Revenue', 'Active', 'BDT 42.10M'],
  ['6220', 'FX Loss', 'Expense', 'Active', 'BDT 0.18M'],
]

export const journals = [
  ['JE-2026-0714-001', 'Manual', 'Posted', 'Revenue accrual', 'BDT 820,000.0000', 'FY26-P01'],
  ['JE-2026-0714-002', 'Manual', 'Draft', 'Month-end accrual', 'BDT 120,000.0000', 'FY26-P01'],
  ['JE-2026-0713-009', 'Reversal', 'Posted', 'Correction entry', 'BDT 64,400.0000', 'FY26-P01'],
]

export const accountingPeriods = [
  ['FY26-P01', '1 Jul 2026', '31 Jul 2026', 'Open', 'Normal posting'],
  ['FY25-P12', '1 Jun 2026', '30 Jun 2026', 'Hard closed', 'Corrections route forward'],
  ['FY25-P11', '1 May 2026', '31 May 2026', 'Soft closed', 'Adjusting only'],
]

export const trialBalanceRows = [
  ['1010', 'Cash in Bank - NRB', 'BDT 12,800,000.0000', 'BDT 0.0000'],
  ['1060', 'Accounts Receivable', 'BDT 9,840,000.0000', 'BDT 0.0000'],
  ['2010', 'Accounts Payable', 'BDT 0.0000', 'BDT 3,180,000.0000'],
  ['4010', 'Client Service Revenue', 'BDT 0.0000', 'BDT 19,460,000.0000'],
]

export const receivables = [
  ['NH-3928', 'Northstar Digital', 'Sent', 'USD 10,000', 'BDT 1.17M', 'Due in 9 days'],
  ['NH-3921', 'Orbit Labs', 'Partially Paid', 'BDT 840,000', 'BDT 310,000', 'Overdue 14 days'],
  ['CN-0041', 'Aster Studio', 'Held', 'BDT 72,000', 'BDT 72,000', 'Credit'],
]

export const payables = [
  ['BILL-1082', 'Cloud Platform Ltd.', 'Awaiting Payment', 'USD 1,240', 'BDT 145,080', 'Due tomorrow'],
  ['EXP-2210', 'Office Operations', 'Recorded', 'BDT 18,250', 'BDT 0', 'Cash-settled'],
  ['DN-0018', 'Media Supplier', 'Posted', 'BDT 44,000', 'BDT 44,000', 'Vendor credit'],
]

export const settlements = [
  ['RCPT-8831', 'Receipt', 'Northstar Digital', 'USD 9,000', 'USD 1,000 AIT', 'BDT 42,300 gain'],
  ['PMT-4419', 'Payment', 'Cloud Platform Ltd.', 'USD 1,240', '-', 'BDT 5,600 loss'],
  ['RCPT-8827', 'Receipt', 'Orbit Labs', 'BDT 530,000', 'BDT 0', '-'],
]

export const banks = [
  ['NRB Current', 'BDT', 'BDT 12.8M', 'Reconciled', '30 Jun 2026'],
  ['SCB', 'BDT', 'BDT 4.1M', 'Unreconciled', '28 Jun 2026'],
  ['Payoneer', 'USD', 'USD 12,440', 'Matched', '29 Jun 2026'],
]

export const taxCodes = [
  ['BD-VAT-15', 'Standard VAT', '15%', 'Output VAT 2020', 'Effective'],
  ['BD-ITES-5', 'ITES VAT', '5%', 'Output VAT 2021', 'Effective'],
  ['BD-EXPORT-0', 'Zero-rated Export', '0%', 'Input recoverable', 'Effective'],
]

export const fxRates = [
  ['USD/BDT', '117.20', 'Manual', '14 Jul 2026', 'Active'],
  ['CAD/BDT', '86.40', 'Manual', '14 Jul 2026', 'Active'],
  ['USD/CAD', '1.36', 'Manual', '13 Jul 2026', 'Referenced'],
]

export const auditRows = [
  ['14 Jul 2026 10:44', 'A. Rahman', 'InvoiceIssued', 'Receivables', 'corr-9f32'],
  ['14 Jul 2026 10:18', 'F. Manager', 'RoleAssigned', 'Identity', 'corr-7c14'],
  ['13 Jul 2026 16:02', 'System', 'JournalPosted', 'Ledger', 'corr-1a08'],
]

export const reports = [
  ['Profit and Loss', 'Accrual / Cash', 'Performance'],
  ['Balance Sheet', 'Balance', 'Financial position'],
  ['Trial Balance', 'Accrual', 'Close review'],
  ['General Ledger', 'Accrual', 'Account detail'],
  ['AR Ageing', 'Accrual', 'Receivables'],
  ['Tax Summary', 'Accrual', 'Compliance'],
]
