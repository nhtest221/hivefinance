# Screen Inventory

## Screens

| Screen | Route | Primary User Intent | Primary Components |
|---|---|---|---|
| Login | `/login` | Sign in securely | Card, Input, Button, Alert |
| Dashboard | `/` | Review current finance state | KPI cards, tables, alerts, charts |
| Chart of Accounts | `/chart-of-accounts` | Review and manage account structure | Table, filters, drawer placeholder |
| Journal Entries | `/journal-entries` | Review journals and posting status | Table, badges, tabs, drawer placeholder |
| Receivables | `/receivables` | Review invoices, credits, AR ageing | Tabs, summary strip, table |
| Payables | `/payables` | Review bills, expenses, AP ageing | Tabs, summary strip, table |
| Settlement | `/settlement` | Review receipts, payments, allocations | Table, summary cards |
| Bank Accounts | `/bank-accounts` | Review bank/cash accounts and reconciliation status | Cards, table |
| Tax | `/tax` | Review tax code and tax pack configuration | Tabs, table, alert |
| FX | `/fx` | Review rates and revaluation runs | Table, filters |
| Reports | `/reports` | Find and open reports | Report catalog grid, filters |
| Audit Log | `/audit-log` | Review immutable system activity | Table, filters |
| Settings | `/settings` | Configure platform and access settings | Tabs, cards, form placeholders |

## Wireframes

### Login

```text
+------------------------------------------------------+
|                    HiveFinance                       |
|              [email] [password] [sign in]            |
|          security note / MFA placeholder             |
+------------------------------------------------------+
```

### Standard App Screen

```text
+ sidebar +---------------- top navigation ------------+
| nav     | entity / period / search / user             |
|         +---------------- page header ----------------+
|         | title / description / actions               |
|         +---------------- filters --------------------+
|         | summary strip                               |
|         | data table or catalog                       |
+---------+---------------------------------------------+
```

### Dashboard

```text
+ sidebar + top nav -----------------------------------+
|         | KPI strip                                  |
|         | cash trend + receivables ageing            |
|         | close readiness + exception queues         |
|         | recent activity                           |
+---------+--------------------------------------------+
```

### Detail Drawer Pattern

```text
+ list/table --------------------------------+ drawer --+
| selected row remains visible               | title    |
| filters stay available                     | metadata |
|                                           | actions  |
+-------------------------------------------+----------+
```

## Screen States

Every table-heavy screen should define:

- Loading state.
- Empty state.
- Error state.
- Filtered empty state.
- Dense desktop table state.
- Mobile stacked-row state.

M0.5 implements static high-fidelity placeholders for these states where useful.
