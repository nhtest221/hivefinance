# HiveFinance Full UAT Runbook (M0–M6)

> **LOCAL/UAT ONLY.** The entity, tax rate, FX rate, credentials, account mapping,
> numbering, payment terms, and reason codes below are illustrative test fixtures. They
> are not approved production defaults or legal/accounting policy. Use a dedicated
> disposable PostgreSQL database. Never run `M2UatSeeder` against a shared or production
> database.

This supersedes `docs/uat/M2_DOCUMENTS_UAT.md` in scope (that document is still valid
for its narrower M2-only walkthrough) — `M2UatSeeder` now also seeds M3 Settlement, M4A
Notes, and M6 Reconciliation fixtures, so this runbook covers every governed module a
finance user can currently exercise through the UI.

## 1. Prerequisites

- PHP 8.4+ with `pdo_pgsql`
- Composer 2
- PostgreSQL 17 (a dedicated, disposable database — never a shared one)
- Node.js 20.19+ or 22.12+ and npm (the repo currently works on 20.17.0 with an
  advisory-only Vite warning; prefer 20.19+/22.12+ if available)
- Redis is not required for this local profile: `.env.uat.example` sets
  `QUEUE_CONNECTION=sync` and `CACHE_STORE=database`.

## 2. Dedicated PostgreSQL database

These names match `backend/.env.uat.example` exactly, so §3's plain `cp` works with no
further editing. Substitute your own names/password and edit `.env.local` accordingly
if you'd rather not use the example's illustrative password.

```bash
dropdb --if-exists hivefinance_m2_uat
dropuser --if-exists hivefinance_uat_app
createuser --login --pwprompt hivefinance_uat_app
createdb --owner=hivefinance_uat_app hivefinance_m2_uat
```

Enter `uat-only-change-me` at the password prompt (matching `.env.uat.example`), or your
own password if you edit `.env.local`'s `DB_PASSWORD` to match.

## 3. Backend configuration, seed, and launch

From the repository root:

```bash
cd backend
cp .env.uat.example .env.local
php artisan key:generate --env=local
mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/framework/testing
php artisan migrate:fresh --force --env=local
HIVEFIN_UAT_SEED_ALLOWED=true php artisan db:seed --class=M2UatSeeder --force --env=local
php artisan serve --env=local --host=127.0.0.1 --port=8080
```

The seeder refuses to run unless the environment is `local`/`testing` **and**
`HIVEFIN_UAT_SEED_ALLOWED=true` (both, deliberately, to make accidental production
seeding hard). It also refuses to reseed an existing UAT entity — run
`migrate:fresh` first if reseeding.

Health check in another terminal:

```bash
curl --fail http://127.0.0.1:8080/v1/health
```

## 4. Frontend launch

```bash
cd frontend
npm install
VITE_API_PROXY_TARGET=http://127.0.0.1:8080 npm run dev -- --host 127.0.0.1 --port 5173
```

Open `http://127.0.0.1:5173/login`.

## 5. Seeded credentials

| Role | Email | Password |
|---|---|---|
| Maker (finance preparer) | `maker.m2.uat@hivefinance.local` | `UatOnly!ChangeMe2026` |
| Checker (finance approver) | `checker.m2.uat@hivefinance.local` | `UatOnly!ChangeMe2026` |
| Auditor (read-only) | `auditor.m2.uat@hivefinance.local` | `UatOnly!ChangeMe2026` |

Entity: `Notionhive Bangladesh` (ID `10000000-0000-4000-8000-000000000001`), functional
currency BDT, fiscal year starting July.

The Maker can create/issue/post most documents and submit approval-gated commands
(receipts, payments, note posting, Hard Close, Reopen); the Checker can approve them
(a different, distinct user is always required — the maker can never approve their own
request); the Auditor can read everything the Maker/Checker can but cannot create,
post, approve, or reverse anything.

## 6. What's already in the seeded fixtures

So every screen below has real data to look at on first login, the seeder pre-creates:

- 3 customers (1 domestic/BDT, 1 foreign/USD, 1 deactivated) and 3 vendors (2 active,
  1 deactivated).
- 2 issued invoices: a domestic BDT invoice (fully settled — see below) and a foreign
  USD invoice (still open, `USD 110.00`).
- 2 bills: one approved and fully paid, one still pending its own approval (useful for
  testing the Approvals page — see §11).
- 1 accrued expense.
- 1 posted credit note (`UAT-CN-2026-1`, against the domestic invoice) and 1 posted
  debit note (`UAT-DN-2026-1`, against the approved bill) — both undisposed, ready for
  you to test Hold/Apply/Refund/Reverse.
- 2 receipts (one fully settling the domestic invoice, one a customer advance that
  produced a real `BDT 200.00` party-credit tranche) and 1 payment (fully settling the
  approved bill) — all already posted through the maker→checker approval flow.
- 1 reconciliation account configured against the operating bank account, marked
  mandatory for Hard Close.
- 1 open fiscal period (`FY2026-P01`).

## 7. Customers and Vendors

Route: `/receivables` (Customers tab) and `/payables` (Vendors tab).

1. Log in as Maker.
2. Click **Customers** (or **Vendors**), fill the "Create customer/vendor" form, submit.
3. Click any existing row (e.g. "UAT Domestic Customer") to open its detail drawer —
   confirm jurisdiction, tax identifier, and (for vendors) masked bank details render,
   and that its invoices/bills list shows real documents.
4. Click **Edit** in the drawer, change a field, **Save** — confirm the row updates.
5. Click **Deactivate** on an active party — confirm its status badge flips and it can
   no longer be selected as a new document's party.

## 8. Invoices (create, edit, issue, view, void)

Route: `/receivables` (Invoices tab).

1. **Create**: fill the invoice form (pick a customer, a date, one or more lines with
   description/quantity/unit price), **Save draft**.
2. **Edit**: while still `draft`, click **Edit**, change the notes, **Save**.
3. **Issue**: click **Issue** — the invoice gets a real document number and moves to
   `sent`.
4. **View PDF**: click **PDF** on a `sent` invoice — a real PDF opens in a new tab.
5. **Void**: click **Void** on a draft or sent invoice, fill the reason code and
   narrative in the confirm dialog, submit — if the entity's approval policy applies,
   you'll get an approval-pending message instead of an immediate void (see §11).

## 9. Bills (create, edit, approve, view, void)

Route: `/payables` (Bills tab). Same shape as Invoices: **Save draft** → **Edit** (draft
only) → **Approve** (moves to `awaiting_payment`, posts a journal entry) → **Void**
(with the same confirm-dialog + reason-code pattern). The seeded pending bill
(`UAT-PENDING-BILL`) is a ready-made example of a bill sitting in its own approval
request — approve it as Checker via §11 to see the full loop.

## 10. Expenses

Route: `/payables` (Expenses tab). Fill date/description/amount/currency/expense
account/SBU code, **Record** — appears immediately in the list (expenses are not a
draft/approve workflow, they post directly).

## 11. Maker-checker: submit and approve

Route: `/approvals`.

There is no centralized list of every pending approval across modules (a genuine,
confirmed backend gap — the frozen API Contracts only define
`POST /v1/approvals/{id}/approve`, no `GET` list/read endpoint). The originating
screen shows you the pending approval's id and version when a maker submits a
gated command (bill approval, receipt, payment, note posting, Hard Close, Reopen).

1. As Maker, trigger any approval-gated command (e.g. approve the seeded pending bill
   from `/payables`) — note the approval id shown in the message.
2. Log out, log in as Checker.
3. Go to `/approvals`, paste the approval id and version, click **Approve**.
4. Go back to the originating screen and refresh — confirm the command's effect (e.g.
   the bill is now `awaiting_payment`, posted).
5. Log in as Maker again and try approving your own pending request — confirm it is
   rejected (the maker can never approve their own request).

## 12. Receipts, Payments, and Party Credit

Route: `/settlement`.

1. **Allocations** tab — see every posted receipt/payment/credit-application/refund/
   reversal, with a `Reverse` action (Checker or Maker with `settlement.allocations.
   reverse`) that opens a confirm dialog before permanently reversing.
2. **Receipts & payments** tab — record a new receipt or payment: pick the party, bank
   account, amounts, add one or more document allocations and (if applicable)
   withholding lines using the row editors, submit.
3. **Party credit** tab — enter a party id (e.g. the seeded domestic customer) and
   click **Load named sources** to see its real credit tranches (the seeded `BDT
   200.00` advance). Use **Explicit credit apply**/**refund** to apply or refund a
   named tranche against a document.

## 13. Credit Notes and Debit Notes (create, post, apply, hold, refund, reverse)

Route: `/notes`.

1. **Credit notes** / **Debit notes** tabs — draft a note against a source invoice/bill
   line, **Save draft**.
2. **Post**: as a *different* user than the one who created it (four-eyes applies the
   same way as bill approval), post the draft — it becomes `posted` with a real document
   number and posts a journal entry.
3. Click **Disposition** on a posted note (the seeded `UAT-CN-2026-1`/`UAT-DN-2026-1`
   are ready-made examples) — choose Hold, Apply, Refund, or Reverse; each mode's
   row-editor fields adjust to what that command actually requires. Reverse is styled
   as a destructive action and warns it cannot be undone.

## 14. Periods and Close (Soft Close, Hard Close, Reopen)

Route: `/periods`.

1. Click a period to open its detail drawer — see every close gate (Trial Balance
   Reviewed, P&L Approved, Balance Sheet Approved, VAT Outputs Approved, Bank
   Reconciliation Completed) and its transition history.
2. **Soft Close** (Maker with `periods.soft_close`) — moves the period to `SoftClosed`.
3. **Hard Close** (from `SoftClosed`) and **Reopen** (from `HardClosed`) **always**
   require a second, distinct approver regardless of the entity's approval policy —
   both always return a pending approval; complete them via §11.
4. Confirm Hard Close is blocked (`unmet_gates` in the error) until every mandatory
   gate is satisfied — the seeded reconciliation account (configured but with no
   completed reconciliation cycle yet) is a ready-made example of a gate that is
   genuinely unmet, not vacuously passing.

## 15. Reports and Cash View

Route: `/reports`. Generate a report (Trial Balance, P&L, Balance Sheet, AR/AP Ageing,
Tax Summary, FX Revaluation, General Ledger, Cash View), review it, approve it (four-
eyes if configured), and export it (CSV/PDF) from the same screen.

## 16. Bank Reconciliation

Route: `/bank-accounts`.

1. **Bank Accounts** tab — the seeded "UAT Operating Bank Reconciliation" account is
   already configured against the operating bank ledger account, marked mandatory for
   Hard Close. Configure a second one from here if needed.
2. **Reconciliations** tab — open a reconciliation batch for a period, import a
   statement, generate match suggestions, confirm matches, create bank-only entries for
   unexplained lines, and **Complete** the cycle (this is what actually satisfies the
   "Bank Reconciliation Completed" close gate — configuring the account alone does not).
   Reopening a completed cycle always requires four-eyes, same as Hard Close.

## 17. Audit and operational traceability

Route: `/audit-log`. **Honest gap, not a bug**: there is no `GET /v1/audit-log`-style
endpoint anywhere in the frozen API Contracts or the backend routes, even though every
mutating command writes an `AuditLog` row server-side. This screen says so plainly
instead of presenting fabricated data. If audit visibility is required for UAT, it must
be inspected directly in the database (`audit_logs` table) for now — that is a real,
confirmed backend gap to raise with the Product Owner, not something the frontend can
paper over honestly.

## 18. Exports

- Invoice PDF: `/receivables`, PDF button on any `sent` invoice.
- Reconciliation statement export (CSV/PDF): `/bank-accounts`, within a reconciliation
  batch.
- Report export (CSV/PDF): `/reports`, on any generated report.

## 19. Known residual gaps for this UAT pass

- No centralized approvals inbox (§11) — confirmed backend gap, not a frontend
  oversight.
- No audit-log read endpoint (§17) — confirmed backend gap.
- M7 Migration (bulk import from an external system) does not exist yet — see
  `docs/ops/DATA_MIGRATION_READINESS_ASSESSMENT.md`.
- `FxService::reverseRevaluation()` has no scheduled or event-driven trigger yet — an
  open architecture decision, documented in
  `HIVEFINANCE_PRODUCTION_READINESS_REPORT.md` §5.1. It does not block this UAT pass.
