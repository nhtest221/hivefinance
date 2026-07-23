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

export const accountingPeriods = [
  ['FY26-P01', '1 Jul 2026', '31 Jul 2026', 'Open', 'Normal posting'],
  ['FY25-P12', '1 Jun 2026', '30 Jun 2026', 'Hard closed', 'Corrections route forward'],
  ['FY25-P11', '1 May 2026', '31 May 2026', 'Soft closed', 'Adjusting only'],
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

export const auditRows = [
  ['14 Jul 2026 10:44', 'A. Rahman', 'InvoiceIssued', 'Receivables', 'corr-9f32'],
  ['14 Jul 2026 10:18', 'F. Manager', 'RoleAssigned', 'Identity', 'corr-7c14'],
  ['13 Jul 2026 16:02', 'System', 'JournalPosted', 'Ledger', 'corr-1a08'],
]
