import {
  Banknote,
  BarChart3,
  BookOpen,
  ClipboardList,
  CircleDollarSign,
  FileText,
  Gauge,
  Landmark,
  Receipt,
  Repeat2,
  Settings,
  StickyNote,
  WalletCards,
} from 'lucide-react'

export const navigationGroups = [
  {
    label: 'Overview',
    items: [{ label: 'Dashboard', path: '/', icon: Gauge, shortcut: 'G D' }],
  },
  {
    label: 'Ledger',
    items: [
      { label: 'Chart of Accounts', path: '/chart-of-accounts', icon: BookOpen, shortcut: 'G C' },
      { label: 'Journal Entries', path: '/journal-entries', icon: FileText, shortcut: 'G J' },
    ],
  },
  {
    label: 'Documents',
    items: [
      { label: 'Receivables', path: '/receivables', icon: Receipt, shortcut: 'G R' },
      { label: 'Payables', path: '/payables', icon: WalletCards, shortcut: 'G P' },
      { label: 'Notes', path: '/notes', icon: StickyNote, shortcut: 'G N' },
    ],
  },
  {
    label: 'Cash',
    items: [
      { label: 'Settlement', path: '/settlement', icon: Repeat2, shortcut: 'G S' },
      { label: 'Reconciliation', path: '/bank-accounts', icon: Landmark, shortcut: 'G B' },
    ],
  },
  {
    label: 'Compliance',
    items: [
      { label: 'Tax', path: '/tax', icon: CircleDollarSign, shortcut: 'G T' },
      { label: 'FX', path: '/fx', icon: Banknote, shortcut: 'G F' },
      { label: 'Audit Log', path: '/audit-log', icon: ClipboardList, shortcut: 'G A' },
    ],
  },
  {
    label: 'Reporting',
    items: [{ label: 'Reports', path: '/reports', icon: BarChart3, shortcut: 'G X' }],
  },
  {
    label: 'Admin',
    items: [{ label: 'Settings', path: '/settings', icon: Settings, shortcut: 'G ,' }],
  },
] as const
