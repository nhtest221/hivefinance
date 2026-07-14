# Information Architecture

## Navigation Architecture

HiveFinance uses a finance-domain primary navigation aligned to the frozen bounded contexts.

| Group | Items | Purpose |
|---|---|---|
| Overview | Dashboard | Executive finance workspace and exceptions |
| Ledger | Chart of Accounts, Journal Entries | Ledger setup and posting visibility |
| Documents | Receivables, Payables | Customer and vendor document workspaces |
| Cash | Settlement, Bank Accounts | Cash application and bank position |
| Compliance | Tax, FX, Audit Log | Tax, currency, and audit visibility |
| Reporting | Reports | Read-only financial reporting workspace |
| Admin | Settings | Entity, users, roles, periods, and system configuration |

Global navigation context:

- Active entity.
- Active fiscal period.
- Global search.
- User menu.

## Module IA

### Login

- Product identity.
- Authentication form.
- MFA placeholder state.
- Security and environment notices.

### Dashboard

- KPI strip.
- Cash and receivables/payables summaries.
- Exception queues.
- Close-readiness panel.
- Recent activity.

### Chart of Accounts

- Account list.
- Account classes.
- Status filters.
- Derived balance column.
- Account detail drawer placeholder.

### Journal Entries

- Journal list.
- Entry type filter.
- Period filter.
- Status badges.
- Journal detail drawer placeholder.

### Receivables

- Invoices and credit notes tabs.
- Customer filter.
- Status/ageing filters.
- AR summary strip.
- Document detail drawer placeholder.

### Payables

- Bills, expenses, and debit notes tabs.
- Vendor filter.
- Status/ageing filters.
- AP summary strip.
- Document detail drawer placeholder.

### Settlement

- Receipt/payment allocation list.
- Settlement direction filter.
- Unapplied credit panel.
- Withholding and realised FX columns.

### Bank Accounts

- Bank account list.
- Reconciliation status.
- Statement import placeholder.
- Balance and last reconciled metadata.

### Tax

- Tax codes.
- Tax packs.
- Effective-dated versions.
- Return-box mapping placeholder.

### FX

- Rate records.
- Revaluation runs.
- Currency pair filters.
- Override reason visibility.

### Reports

- Report catalog.
- Basis labels.
- Period/entity/SBU filters.
- Export action placeholders.

### Audit Log

- Immutable event stream.
- Actor, action, module, entity, timestamp.
- Correlation ID.
- Before/after detail drawer placeholder.

### Settings

- Entity settings.
- Users and roles.
- Approval policy.
- Period and fiscal calendar settings.
- System configuration.

## Responsive Behavior

| Viewport | Behavior |
|---|---|
| Desktop | Persistent sidebar, full table columns, summary strips, optional detail drawer |
| Tablet | Collapsible sidebar, fewer summary cards per row, tables retain priority columns |
| Mobile | Sidebar becomes hidden navigation area, tables become stacked rows, filters wrap above content |

## Layout Rules

- Primary screens use `AppShell`.
- Page content uses a max-width only when forms need reading control; table screens use full available width.
- Dense tables preserve action controls in the first or last visible column.
- Detail views use drawers so list context remains available.
