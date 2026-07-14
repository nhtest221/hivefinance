import { createBrowserRouter } from 'react-router'

import { DashboardPage } from './pages/dashboard-page'
import { LoginPage } from './pages/login-page'
import {
  AuditLogPage,
  BankAccountsPage,
  ChartOfAccountsPage,
  FxPage,
  JournalEntriesPage,
  PayablesPage,
  ReceivablesPage,
  ReportsPage,
  SettingsPage,
  SettlementPage,
  TaxPage,
} from './pages/module-screens'

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  { path: '/', element: <DashboardPage /> },
  { path: '/chart-of-accounts', element: <ChartOfAccountsPage /> },
  { path: '/journal-entries', element: <JournalEntriesPage /> },
  { path: '/receivables', element: <ReceivablesPage /> },
  { path: '/payables', element: <PayablesPage /> },
  { path: '/settlement', element: <SettlementPage /> },
  { path: '/bank-accounts', element: <BankAccountsPage /> },
  { path: '/tax', element: <TaxPage /> },
  { path: '/fx', element: <FxPage /> },
  { path: '/reports', element: <ReportsPage /> },
  { path: '/audit-log', element: <AuditLogPage /> },
  { path: '/settings', element: <SettingsPage /> },
])
