# HiveFinance Release, Rollback, and Backup/DR Status

This document has two purposes: (1) point at the release/rollback procedure that already
lives in `docs/ops/DEPLOYMENT_AND_OPERATIONS_RUNBOOK.md` §6-7 rather than duplicating it,
and (2) state plainly what this repository does and does not have for backup and
disaster recovery, because that gap is explicitly acknowledged — not silently missing —
in the project's own frozen requirements.

## 1. Release checklist and rollback plan

See `docs/ops/DEPLOYMENT_AND_OPERATIONS_RUNBOOK.md`:
- §6 "Release checklist"
- §7 "Rollback plan"

Kept in one place to avoid the two documents drifting out of sync.

## 2. Backup and disaster recovery — open governance gap, not a solved problem

**There is no backup or restore tooling anywhere in this codebase.** A repository-wide
search (excluding `vendor/`/`node_modules/`) found no backup script, no `pg_dump`/
`pg_restore` usage, no scheduled backup job, and no offsite-backup integration.

This is not an oversight this session is positioned to fix by inventing a policy. The
project's own frozen SRS already names it as an explicitly deferred, unresolved item:

> **Production-readiness items still to schedule (from original review §12, unaffected
> by ADRs):** backup/DR (RPO/RTO); data-retention policy; PII handling for stored
> contacts/bank data; timezone-of-record (UTC vs BDT) resolution; parallel-run plan vs
> Xero and go-live acceptance sign-off.
> — `docs/HiveFin_SRS_v3.0.md`

> **Production-readiness gaps** (backup/DR, PII, timezone-of-record, parallel-run plan)
> — schedule explicitly in M0/M8 — still open from the original review
> — `docs/HiveFin_Implementation_Roadmap.md`

Per `CLAUDE.md`: *"Never guess, infer, or invent a missing accounting, tax, VAT, FX,
approval, security, period-close, or legal rule."* A backup cadence, retention period,
and RPO/RTO target are exactly this kind of rule — they carry real cost and compliance
implications (how much data loss is acceptable, how long can the system be down, what
retention period satisfies whatever regulatory regime applies to this entity's
jurisdiction). Inventing a number here (e.g. "daily backups, 24h RPO") would be
presenting an unapproved policy as if it were a settled decision.

### What this means practically, today

- **PostgreSQL is the system of record** (see the Deployment and Operations Runbook §1)
  and is the only thing that needs backing up for data-loss recovery — the application
  itself is stateless aside from the database and Redis (Redis holds only cache/queue/
  session state, all of which is reconstructable or acceptable-to-lose, not a system of
  record).
- Whatever managed PostgreSQL hosting is chosen for production almost certainly has its
  own built-in backup/point-in-time-recovery capability (e.g. automated snapshots) — the
  open item is not "how do we technically back up Postgres," it's "what RPO/RTO does the
  business require, and does the chosen hosting's default backup behavior meet it,"
  which is a Product Owner / governance decision this document cannot make.
- Until that decision is made and recorded as a Governance Approval Record, **do not
  represent this system as production-ready for real financial data** — an unrecoverable
  data-loss event with no backup policy is a business risk, not a technical one this
  document can close by writing a runbook.

### What to bring to the Product Owner

1. Required RPO (maximum acceptable data loss window) and RTO (maximum acceptable
   downtime) for a production HiveFinance deployment.
2. Required backup retention period (interacts with `AUDIT_RETENTION_DAYS`, currently
   defaulted to 2555 days / ~7 years in `.env.example`, and whatever statutory retention
   applies to the entity's accounting records in its jurisdiction).
3. Whether backups need to be geographically redundant / offsite, and any data-residency
   constraint (relevant given the M2 UAT fixtures already model both a domestic (BD) and
   a foreign-currency customer/vendor).
4. Confirmation of whether the chosen production database hosting's built-in backup
   capability is sufficient, or whether a separate application-level backup job needs to
   be built.

Once those are answered and approved, this document should be updated (or superseded)
with the actual procedure — at that point it stops being a gap statement and becomes a
real runbook section.
