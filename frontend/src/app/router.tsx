import { createBrowserRouter } from 'react-router'

import { DashboardPage } from './pages/dashboard-page'
import { ForgotPasswordPage } from './pages/forgot-password-page'
import { LoginPage } from './pages/login-page'
import {
  AuditLogPage,
  BankAccountsPage,
  ChartOfAccountsPage,
  FxPage,
  PayablesPage,
  ReceivablesPage,
  ReportsPage,
  SettingsPage,
  SettlementPage,
  TaxPage,
} from './pages/module-screens'
import { ResetPasswordPage } from './pages/reset-password-page'
import { ManualJournalPage } from '@/features/ledger/manual-journal-page'

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  { path: '/forgot-password', element: <ForgotPasswordPage /> },
  { path: '/reset-password', element: <ResetPasswordPage /> },
  { path: '/', element: <DashboardPage /> },
  { path: '/chart-of-accounts', element: <ChartOfAccountsPage /> },
  { path: '/journal-entries', element: <ManualJournalPage /> },
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
