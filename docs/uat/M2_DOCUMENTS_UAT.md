# M2 Documents Release Candidate — Local Product Owner and Finance UAT

> **LOCAL/UAT ONLY.** The entity, tax rate, FX rate, credentials, account mapping, numbering, payment terms, and SBU labels below are illustrative test fixtures. They are not approved production defaults or legal/accounting policy. Use a dedicated disposable PostgreSQL database. Never run `M2UatSeeder` against shared or production data.

## 1. Release candidate

- Branch: `codex/m2-documents`
- Pull request: [#10](https://github.com/nhtest221/hivefinance/pull/10)
- Backend URL: `http://127.0.0.1:8080`
- Frontend URL: `http://127.0.0.1:5173`
- Login URL: `http://127.0.0.1:5173/login`
- Receivables: `http://127.0.0.1:5173/receivables`
- Payables: `http://127.0.0.1:5173/payables`
- Entity ID: `10000000-0000-4000-8000-000000000001`

## 2. Prerequisites

- PHP 8.4 or newer with `pdo_pgsql`
- Composer 2
- PostgreSQL 17
- Node.js 20.19+ or 22.12+ and npm
- No PDF package or native binary is required. M2 uses the built-in PHP PDF renderer and returns `application/pdf` directly.
- Redis is not required for this local profile because `QUEUE_CONNECTION=sync` and `CACHE_STORE=database`.

On macOS with Homebrew:

```bash
brew install php composer postgresql@17 node@22
brew services start postgresql@17
```

## 3. Dedicated PostgreSQL database

These commands deliberately affect only the named UAT role and database:

```bash
dropdb --if-exists hivefinance_m2_uat
dropuser --if-exists hivefinance_uat_app
createuser --login --pwprompt hivefinance_uat_app
createdb --owner=hivefinance_uat_app hivefinance_m2_uat
```

Enter `uat-only-change-me` at both password prompts. Confirm connectivity:

```bash
PGPASSWORD=uat-only-change-me psql -h 127.0.0.1 -U hivefinance_uat_app -d hivefinance_m2_uat -c 'select current_database(), current_user, version();'
```

## 4. Backend configuration, seed, and launch

From the repository root:

```bash
cp backend/.env backend/.env.before-m2-uat 2>/dev/null || true
cp backend/.env.uat.example backend/.env
cd backend
composer install --no-interaction --prefer-dist
php artisan config:clear
php artisan migrate:fresh --force --seed --seeder='Database\Seeders\M2UatSeeder'
php artisan serve --host=127.0.0.1 --port=8080
```

The seeder refuses to run unless `APP_ENV` is `local` or `testing` and `HIVEFIN_UAT_SEED_ALLOWED=true`. It also refuses to overwrite an existing UAT entity; reseeding therefore requires the explicit `migrate:fresh` command against the disposable database.

Health check in another terminal:

```bash
curl --fail http://127.0.0.1:8080/v1/health
```

## 5. Frontend launch

In a second terminal:

```bash
cd frontend
npm install
VITE_API_PROXY_TARGET=http://127.0.0.1:8080 npm run dev -- --host 127.0.0.1 --port 5173
```

The Vite server proxies `/v1` to the backend. Open `http://127.0.0.1:5173/login`.

## 6. Queue, scheduler, and outbox

No queue worker is required for the UAT profile: jobs use the synchronous connection. Transactional outbox rows are committed atomically with business changes. To exercise dispatch, run either a single batch:

```bash
cd backend
php artisan outbox:dispatch --limit=100
```

Or keep scheduled dispatch running in a third terminal:

```bash
cd backend
php artisan schedule:work
```

Do not run both continuously. A separate `queue:work` process is unnecessary while `QUEUE_CONNECTION=sync`.

## 7. UAT users

All credentials are local fixtures and share the password `UatOnly!ChangeMe2026`.

| Persona | Email | Role and intended access |
|---|---|---|
| Finance preparer/maker | `maker.m2.uat@hivefinance.local` | Customer/vendor management; invoice draft/issue; bill draft/approval request; expense creation; M2 read access |
| Finance approver/checker | `checker.m2.uat@hivefinance.local` | Approval execution plus M2 read access; cannot alter masters or create documents |
| Read-only auditor | `auditor.m2.uat@hivefinance.local` | M2, Ledger, Tax, FX, Period, and report read capabilities only |

MFA is disabled only for these local fixtures. Production MFA policy is unchanged.

## 8. Local/UAT-only entity policy

| Area | UAT fixture |
|---|---|
| Functional currency | BDT |
| Fiscal year | Starts 1 July; seeded period `FY2026-P01` is open for July 2026 |
| Payment terms | `NET15` = 15 days; `NET30` = 30 days |
| Customer/vendor identity | UUID. M2 has no approved human-readable customer/vendor number field or sequence, so none is invented. |
| Invoice sequence | Prefix `UAT-INV`; format `{prefix}-{fiscal_year}-{sequence}` |
| Bill sequence | Prefix `UAT-BILL`; format `{prefix}-{fiscal_year}-{sequence}` |
| SBU labels | `OPS`, `SALES`; stored as allocation labels. Current M2 validates exact total weight but has no frozen SBU master registry. |
| Tax | UAT-only `UAT-VAT10`, illustrative 10% exclusive VAT, effective 1 July 2026, recoverable for input tax |
| FX | UAT-only `uat_manual` USD/BDT RateRecord at `120.00000000`, effective 1 July 2026 |
| Rounding | `half_up`, four functional-currency decimal places |
| Approval | Non-empty entity approval policy requires durable maker-checker execution for bill approval |

Account mappings:

| Code | Name | UUID |
|---|---|---|
| 1100 | Trade Receivables | `20000000-0000-4000-8000-000000000001` |
| 1200 | UAT Operating Bank | `20000000-0000-4000-8000-000000000002` |
| 1300 | Recoverable Input VAT | `20000000-0000-4000-8000-000000000003` |
| 2100 | Trade Payables | `20000000-0000-4000-8000-000000000004` |
| 2200 | Output VAT Payable | `20000000-0000-4000-8000-000000000005` |
| 4100 | Service Revenue | `20000000-0000-4000-8000-000000000006` |
| 5100 | Operating Expense | `20000000-0000-4000-8000-000000000007` |

## 9. Seeded dataset

- Active customers: `UAT Domestic Customer`, `UAT Foreign Customer`
- Deactivated customer: `UAT Deactivated Customer`
- Active vendors: `UAT Domestic Vendor`, `UAT Approval Vendor`
- Deactivated vendor: `UAT Deactivated Vendor`
- Issued domestic invoice: `UAT-INV-2026-1`
- Issued foreign invoice: `UAT-INV-2026-2`
- Approved domestic bill: `UAT-BILL-2026-1`
- Draft bill with pending approval: vendor reference `UAT-PENDING-BILL`
- Recorded expense: `UAT office supplies`

The seed command prints the UUID of the pending bill approval. Retain it for checker UAT. It can also be retrieved with:

```bash
PGPASSWORD=uat-only-change-me psql -h 127.0.0.1 -U hivefinance_uat_app -d hivefinance_m2_uat -Atc "select id from identity_approval_requests where status='pending' and command_type='bill_approve';"
```

## 10. Browser console helper

Dedicated detail pages and an approval inbox are not part of the current shell. Use the browser developer console while signed in to exercise those frozen endpoints without handling tokens manually. Paste once per login:

```javascript
window.uatApi = async (path, options = {}) => {
  const method = options.method ?? 'GET';
  const headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: `Bearer ${sessionStorage.getItem('hivefinance.auth_token')}`,
    'X-Entity-Id': sessionStorage.getItem('hivefinance.entity_id'),
    'X-Correlation-Id': options.correlation ?? crypto.randomUUID(),
  };
  if (method !== 'GET') headers['Idempotency-Key'] = options.key ?? crypto.randomUUID();
  if (options.ifMatch !== undefined) headers['If-Match'] = String(options.ifMatch);
  const response = await fetch(path, { method, headers, body: options.body === undefined ? undefined : JSON.stringify(options.body) });
  const data = await response.json().catch(() => null);
  const result = { status: response.status, replay: response.headers.get('Idempotent-Replay'), data };
  console.log(result);
  return result;
};
```

## 11. Browser UAT checklist

### Customers — `/receivables` → Customers

1. Sign in as maker and open `/receivables`; select **Customers**.
2. Confirm two active seeded customers are listed. The default list deliberately hides the deactivated customer.
3. Create a customer using a configured currency (`BDT` or `USD`) and terms (`NET15` or `NET30`).
4. Detail: in the console run `const cs=(await uatApi('/v1/customers?status=active&limit=100')).data.customers; const c=cs[0]; await uatApi('/v1/customers/'+c.id)`.
5. Edit: run `await uatApi('/v1/customers/'+c.id,{method:'PATCH',ifMatch:c.version,body:{name:c.name+' UAT EDIT'}})` and confirm version increments.
6. Deactivate a newly created customer with the visible **Deactivate** action. Confirm it disappears from the active list.
7. Confirm deactivated records with `await uatApi('/v1/customers?status=deactivated&limit=100')`.

### Invoices — `/receivables` → Invoices

1. Confirm `UAT-INV-2026-1` and `UAT-INV-2026-2` show status `sent`.
2. Create a draft from an active customer. Confirm status `draft` and no document number.
3. Use **Edit** to replace draft notes; confirm the version increments.
4. Detail: `const is=(await uatApi('/v1/invoices?limit=100')).data.invoices; const i=is.find(x=>x.status==='draft'); await uatApi('/v1/invoices/'+i.id)`.
5. Use **Issue**. Confirm status becomes `sent`, a number appears, and no Edit action remains.
6. Use **PDF** and confirm an authorized PDF opens in a new browser tab.

### Vendors — `/payables` → Vendors

1. Confirm two active seeded vendors are listed.
2. Create a vendor using configured currency and payment terms.
3. Detail: `const vs=(await uatApi('/v1/vendors?status=active&limit=100')).data.vendors; const v=vs[0]; await uatApi('/v1/vendors/'+v.id)`.
4. Edit: `await uatApi('/v1/vendors/'+v.id,{method:'PATCH',ifMatch:v.version,body:{name:v.name+' UAT EDIT'}})`.
5. Deactivate a newly created vendor with **Deactivate** and confirm it leaves the active list.
6. Confirm deactivated records with `await uatApi('/v1/vendors?status=deactivated&limit=100')`.
7. For `UAT Domestic Vendor`, confirm only masked bank identifiers are returned.

### Bills — `/payables` → Bills

1. Confirm `UAT-BILL-2026-1` is `awaiting_payment` and `UAT-PENDING-BILL` remains an unnumbered `draft`.
2. Create another bill draft using account `20000000-0000-4000-8000-000000000007` and SBU `OPS`.
3. Use **Edit** to replace draft notes.
4. Detail: `const bs=(await uatApi('/v1/bills?limit=100')).data.bills; const b=bs.find(x=>x.status==='draft'); await uatApi('/v1/bills/'+b.id)`.
5. As maker, use **Approve** on the new draft. Confirm the UI states approval is pending and does not claim the bill was posted.
6. Sign out and sign in as checker. Paste the console helper and approve the pending UUID printed by the seed/request: `await uatApi('/v1/approvals/PASTE_APPROVAL_ID/approve',{method:'POST',ifMatch:1,body:{}})`.
7. Reload `/payables`; confirm the bill is now `awaiting_payment`, numbered, immutable, and posted once.

### Expenses — `/payables` → Expenses

1. Confirm `UAT office supplies` is listed as recorded cash expense for BDT 120.0000.
2. Create an accrued expense using `UAT Domestic Vendor`, account `20000000-0000-4000-8000-000000000007`, and SBU `OPS`.
3. Detail: `const es=(await uatApi('/v1/expenses?limit=100')).data.expenses; await uatApi('/v1/expenses/'+es[0].id)`.
4. Sign in as auditor and confirm lists/details remain visible while create, edit, deactivate, issue, and approve controls are absent.

## 12. Expected accounting results

| Scenario | Document and status | Document / tax / functional totals | Expected posting |
|---|---|---|---|
| Domestic invoice | `UAT-INV-2026-1`; draft → sent; version 1 → 2 | BDT subtotal 1,000.0000; VAT 100.0000; total/open 1,100.0000; functional BDT 1,100.0000 | Dr 1100 Receivables 1,100; Cr 4100 Revenue 1,000; Cr 2200 Output VAT 100 |
| Foreign invoice | `UAT-INV-2026-2`; draft → sent; version 1 → 2 | USD subtotal 100.0000; VAT 10.0000; total/open 110.0000; rate 120; functional BDT 13,200.0000 | Dr 1100 Receivables 13,200; Cr 4100 Revenue 12,000; Cr 2200 Output VAT 1,200; every line references the exact USD/BDT RateRecord |
| Approved bill | `UAT-BILL-2026-1`; draft → pending Identity approval → awaiting_payment; bill version 1 → 2 | BDT subtotal 500.0000; recoverable VAT 50.0000; total/open 550.0000 | Dr 5100 Expense 500; Dr 1300 Recoverable Input VAT 50; Cr 2100 Payables 550 |
| Pending bill | No number; bill stays draft/version 1 while Identity ApprovalRequest is pending | BDT 250.0000; no tax; open balance 0.0000 until approved | No journal and no BillApproved event before checker execution |
| Cash expense | Recorded/version 1 | BDT 120.0000; no tax; functional BDT 120.0000 | Dr 5100 Expense 120; Cr 1200 Bank 120 |

Expected immutable effects:

- Each issued invoice: `invoice_draft_created`, `invoice_issued`; `InvoiceIssued`, `TaxDetermined`, `JournalPosted`, and `SystemEntryPosted`.
- Approved bill: `bill_draft_created`, `approval_requested`, `bill_approved`, `approval_granted`; matching ApprovalRequested, BillApproved, TaxDetermined, ApprovalGranted, JournalPosted, and SystemEntryPosted events.
- Pending bill: draft and approval-request audit/outbox only; no posting or numbering.
- Expense: `expense_recorded`; ExpenseRecorded, JournalPosted, and SystemEntryPosted.
- Master deactivation: one immutable deactivation audit plus CustomerDeactivated or VendorDeactivated.
- Exact replay creates no additional business audit, journal, number, or business event.

Verification query:

```bash
PGPASSWORD=uat-only-change-me psql -h 127.0.0.1 -U hivefinance_uat_app -d hivefinance_m2_uat -P pager=off -c "select j.entry_type,a.code,l.debit,l.credit,l.currency,l.fx_amount,l.fx_currency,l.fx_rate from journal_entries j join journal_lines l on l.journal_entry_id=j.id join ledger_accounts a on a.id=l.account_id order by j.entry_date,j.id,l.line_no;"
```

## 13. Negative tests

Use a newly created disposable draft/master where possible.

1. **Edit issued invoice:** obtain an issued detail and run a PATCH with its current version. Expect `422 invariant_violation`, rule `invoice_not_draft`, and no mutation.
2. **Edit approved bill:** PATCH `UAT-BILL-2026-1`. Expect `422 invariant_violation`, rule `bill_not_draft`.
3. **Maker self-approval:** while signed in as maker, call `POST /v1/approvals/{pending-id}/approve` with `If-Match: 1`. Expect `403 maker_cannot_approve`; request stays pending.
4. **Exact idempotent replay:** save `const key=crypto.randomUUID(); const body={name:'Replay Customer',type:'local',default_currency:'BDT',payment_terms:'NET30'};` then run the same `uatApi('/v1/customers',{method:'POST',key,body})` twice. Expect identical `201` body on the second response and `replay: 'true'`; only one customer/audit/event.
5. **Key reused with different input:** repeat the second request with the same key but a different name. Expect `409 idempotency_conflict`.
6. **Stale If-Match:** update an active master successfully, then issue a second update using the old version and a new idempotency key. Expect `409 concurrency_conflict` and `required_version`.
7. **Deactivated master:** use the deactivated customer/vendor UUID from the status-filtered list to create an invoice/bill. Expect `422 customer_inactive` or `422 vendor_inactive`.
8. **Missing Tax:** create a document with `tax_code_id: crypto.randomUUID()`. Expect `422 missing_tax_configuration` and no document/posting.
9. **Missing FX:** create a USD invoice for `UAT Foreign Customer` with a random `rate_record_id`. Expect `422 missing_rate_reference` and no document/posting.
10. **Missing numbering:** stop the backend, blank `INVOICE_NUMBER_FORMAT` in `.env`, run `php artisan config:clear`, restart, and attempt to issue a disposable draft. Expect `422 missing_numbering_configuration`; draft and journal count remain unchanged. Restore the UAT env before continuing.
11. **Missing account mapping:** repeat with `INVOICE_RECEIVABLE_ACCOUNT_ID=`. Expect `422 missing_posting_configuration`, no journal, and the drawn number recorded void. Restore the UAT env.
12. **Invalid correlation:** `await uatApi('/v1/customers?limit=1',{correlation:'not-a-uuid'})`. Expect `400 validation`; it must not be replaced silently.
13. **Unknown field:** include `attachment:'forbidden'` in any M2 create request. Expect `400 validation` and no mutation.

## 14. Focused release-candidate self-review

| Area | Finding |
|---|---|
| Posting correctness | Confirmed exact debit/credit balance for domestic invoice, foreign invoice, recoverable-tax bill, and expense. Foreign journal lines retain exact RateRecord, foreign amount/currency, rate, and BDT functional amount. |
| Transaction/rollback | Document recognition, journal, audit, outbox, tax/rate reference marking, state transition, and idempotency record share a database transaction. Failed posting rolls back document recognition. |
| Numbering failure | Missing configuration fails before draw. Failures after draw call the Numbering void contract; no document is recognized and the issued value is retained as an explicit void rather than silently reused. |
| Idempotency/concurrency | Sequential exact replay and payload conflict are covered; state-changing edits use compare-and-update versions. A remaining concurrency risk is noted below for two truly simultaneous first requests using the same idempotency key. |
| PostgreSQL immutability | Issued invoices, approved bills, recorded expenses, referenced TaxCodeVersions, and RateRecords are protected. RC review corrected repeated no-op reference marking so legitimate reuse does not trip immutable-record triggers. |
| Entity isolation | Every query and command is entity-scoped and capability-default-deny. M2 has no cross-context foreign keys. |
| Authorization/approval | Maker/checker separation, approval capability, same-entity access, immutable encrypted replay payload, and pending-on-failure behavior are preserved. |
| Vendor bank details | Stored through Laravel encrypted casts; API and audit expose masked identifiers only. The UAT seed verifies the raw database value does not contain the plaintext account identifier. |
| PDF | Requires invoice-read authorization and entity scope; only issued invoices are rendered. ETag and document metadata are returned. No external PDF dependency exists. |
| Frontend | Validation errors are rendered as safe messages; pending approval is explicitly not presented as success; role capabilities hide mutation controls; PDF uses authenticated retrieval. RC review added a local `/v1` development proxy. |

## 15. Known limitations and risks

1. The current shell has combined `/receivables` and `/payables` workspaces rather than dedicated detail routes or an approval inbox. Detail, full master edit, and checker approval are therefore exercised through the authenticated browser console helper.
2. The M2 PDF renderer is intentionally minimal because PDF layout/design rules remain unapproved. It proves authorized binary retrieval and metadata, not final invoice presentation design.
3. Customer and vendor human-readable numbering is not present in the approved M2 schema; UUID is identity. Adding a sequence would require governance, so the UAT fixture does not invent one.
4. SBU codes are stored labels with exact allocation-total validation. A shared authoritative SBU master registry is not part of current frozen M2 artifacts.
5. Two genuinely simultaneous first requests with the same idempotency key cannot repeat business effects because the losing transaction rolls back, but may surface a database conflict rather than the already-committed replay response. Sequential replay behavior is contract-correct and tested; high-contention first-write replay normalization is follow-up hardening.
6. The checked local Node 20.17 runtime builds with a Vite warning; Product Owner UAT should use Node 20.19+ or 22.12+ as listed above.

## 16. Stop and cleanup

Stop the backend, frontend, and scheduler with `Ctrl-C`. Remove only the disposable UAT database and role when finished:

```bash
dropdb --if-exists hivefinance_m2_uat
dropuser --if-exists hivefinance_uat_app
```

Restore the previous backend environment if one was backed up:

```bash
cp backend/.env.before-m2-uat backend/.env
```
