import { createBrowserRouter } from 'react-router'

import { DashboardPage } from './pages/dashboard-page'
import { ForgotPasswordPage } from './pages/forgot-password-page'
import { LoginPage } from './pages/login-page'
import {
  AuditLogPage,
  BankAccountsPage,
  ReportsPage,
  SettingsPage,
  SettlementPage,
} from './pages/module-screens'
import { ResetPasswordPage } from './pages/reset-password-page'
import { ManualJournalPage } from '@/features/ledger/manual-journal-page'
import { ChartOfAccountsPage } from '@/features/ledger/chart-of-accounts-page'
import { TaxPage } from '@/features/tax/tax-page'
import { FxPage } from '@/features/fx/fx-page'
import { ReceivablesPage } from '@/features/documents/receivables-page'
import { PayablesPage } from '@/features/documents/payables-page'

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
