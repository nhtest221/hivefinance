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
