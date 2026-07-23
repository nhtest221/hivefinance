import { createBrowserRouter } from 'react-router'

import { DashboardPage } from './pages/dashboard-page'
import { ForgotPasswordPage } from './pages/forgot-password-page'
import { LoginPage } from './pages/login-page'
import {
  AuditLogPage,
  SettingsPage,
} from './pages/module-screens'
import { ResetPasswordPage } from './pages/reset-password-page'
import { ManualJournalPage } from '@/features/ledger/manual-journal-page'
import { ChartOfAccountsPage } from '@/features/ledger/chart-of-accounts-page'
import { TaxPage } from '@/features/tax/tax-page'
import { FxPage } from '@/features/fx/fx-page'
import { ReceivablesPage } from '@/features/documents/receivables-page'
import { PayablesPage } from '@/features/documents/payables-page'
import { SettlementPage } from '@/features/settlement/settlement-page'
import { NotesPage } from '@/features/notes/notes-page'
import { ReportingPage } from '@/features/reporting/reporting-page'
import { ReconciliationPage } from '@/features/reconciliation/reconciliation-page'

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
  { path: '/notes', element: <NotesPage /> },
  { path: '/bank-accounts', element: <ReconciliationPage /> },
  { path: '/tax', element: <TaxPage /> },
  { path: '/fx', element: <FxPage /> },
  { path: '/reports', element: <ReportingPage /> },
  { path: '/audit-log', element: <AuditLogPage /> },
  { path: '/settings', element: <SettingsPage /> },
])
