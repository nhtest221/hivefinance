# User Flows

## Journey 1: Daily Finance Review

1. User logs in.
2. User lands on Dashboard.
3. User checks cash, AR, AP, delayed invoices, and close readiness.
4. User opens Receivables or Payables from an exception queue.
5. User reviews document list and status.
6. User opens detail drawer placeholder.

M0.5 scope: static navigation and mock data only.

## Journey 2: Ledger Review

1. User opens Chart of Accounts.
2. User filters by class or status.
3. User scans account code, name, type, and derived balance.
4. User opens Journal Entries.
5. User filters by period and entry type.
6. User reviews journal status and source references.

M0.5 scope: no posting, no edit, no ledger computation.

## Journey 3: Receivables Review

1. User opens Receivables.
2. User reviews AR summary and ageing.
3. User switches between invoices and credit notes.
4. User searches customer/document.
5. User opens a document drawer placeholder.

M0.5 scope: static data and layout only.

## Journey 4: Payables Review

1. User opens Payables.
2. User reviews AP summary.
3. User switches between bills, expenses, and debit notes.
4. User filters by vendor/status.
5. User reviews due and overdue items.

M0.5 scope: static data and layout only.

## Journey 5: Settlement Review

1. User opens Settlement.
2. User chooses receipts or payments via filter.
3. User reviews allocations, withholding, and realised FX placeholder values.
4. User reviews unapplied credit summary.

M0.5 scope: no allocation logic and no posting.

## Journey 6: Compliance Review

1. User opens Tax.
2. User reviews tax codes and effective-dated versions.
3. User opens FX.
4. User reviews rate records and revaluation run placeholders.
5. User opens Audit Log.
6. User filters by actor, action, module, or correlation ID.

M0.5 scope: read-only static interface.

## Journey 7: Reporting Review

1. User opens Reports.
2. User scans report catalog by group.
3. User identifies basis labels: accrual, cash, or balance.
4. User sees export action placeholders.

M0.5 scope: no report generation and no export behavior.

## Journey 8: Administration Review

1. User opens Settings.
2. User reviews entity, roles, approval policy, and period configuration panels.
3. User sees form layout patterns for future implementation.

M0.5 scope: static controls only, no persistence.

## Accessibility Flow Review

- Keyboard-only users can reach primary navigation and page actions in source order.
- Screen reader users get text labels for statuses and controls.
- Dialog/drawer patterns must preserve focus management when behavior is implemented.
- Mobile users can complete review tasks from stacked summaries and rows.
