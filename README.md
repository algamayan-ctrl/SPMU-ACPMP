# SPMU-ACPMP

SPMU Asset Custody and Performance Monitoring Program is a Laravel 13 system implementing the approved CSPC property-borrowing process for five user groups: Borrower, SPMU, GSU, VPAF, and ICTU.

## Implemented system

- Employee/faculty/staff accounts only, with ICTU-controlled access classifications and separate active workspaces.
- Heads of SPMU, GSU, and VPAF are approvers and cannot borrow; SPMU Action Officers and ICTU maintainers can switch to Borrower when authorized.
- Formal temporary delegated approver records are time-bound, office-specific, attributable, and never require sharing a Head's password.
- Editable user profiles and protected e-signature upload/snapshots.
- Date-aware inventory availability and an approved/active borrowing calendar.
- Editable descriptive inventory records without barcode or physical asset-ID dependency.
- Digital request letter with represented student organization, program/department, year, event, place, and fixed borrowing period.
- Sequential SPMU → GSU → VPAF signed approvals with exact version, approver name, received/action timestamps, and audit history.
- Atomic allocation only after VPAF approval; pending requests do not reserve stock.
- Same-day approved-letter download gate before Borrower's Slip access.
- SPMU-recorded final issued quantities, SPMU preparation check, borrower e-signed acknowledgement, and physical release before an item becomes Borrowed.
- Cancellation before release with allocation restoration; released transactions use Early Return.
- Partial/complete return, manual physical inspection, linen-to-laundry routing, and inventory state ledger.
- Barricade-only off-campus policy with a Gate Pass digitally verified by an SPMU Action Officer, digitally approved by the SPMU Head/delegate, and wet-signed by the guard after exit.
- Conditional Laundry Form for linen only, digitally signed by Borrower and SPMU Head/delegate, then wet-signed by the laundry worker.
- One consolidated PDF packet with the approved letter, Borrower's Slip, and only the applicable conditional forms, each preserved on its own page.
- Item-level custody states, Early Return notices, and separate return/laundry/gate compliance instead of one request-level return flag.
- Damage, destruction, missing, lost, and stolen incident records; evidence is required and theft requires a police-blotter reference.
- Provisional RSLDDP case data and controlled output after its template setting is marked `APPROVED`.
- 24-hour overdue grace period, due-soon/overdue notices, configurable daily tariff, escalating offense sanctions, and borrowing restrictions.
- Billing Statements limited to penalties/property charges; borrower receipt upload, SPMU original inspection and OR encoding, settlement, and authorized waiver.
- In-system notices, real email transport, configurable SMS webhook, and delivery reports.
- Printable dashboards, period filters, CSV exports, inventory states, utilization, overdue, penalties, repeat offenses, return compliance, audit, and KPI observations.
- ICTU local SQLite backup, Docker/MariaDB deployment files, scheduled deadline processing, security headers, login throttling, and technical audit records.

## Quick local start on Windows

Open PowerShell in this folder and run:

```powershell
.\tools\artisan.cmd migrate --seed
.\tools\artisan.cmd serve --host=127.0.0.1 --port=8001
```

Keep PowerShell open, then visit `http://127.0.0.1:8001`.

Local demonstration accounts all use `SPMU-Demo-2026!`:

| Role | Email |
|---|---|
| Borrower | `borrower@spmu.test` |
| SPMU Action Officer / Borrower | `spmu@spmu.test` |
| SPMU Head | `spmu-head@spmu.test` |
| GSU | `gsu@spmu.test` |
| VPAF | `vpaf@spmu.test` |
| ICTU | `ictu@spmu.test` |

These accounts and signatures are for local demonstration only. `SEED_DEMO_USERS` is disabled in the production example.

See [RUN-IN-VSCODE.md](RUN-IN-VSCODE.md) for the manuscript-aligned Visual Studio Code, Docker, and MariaDB instructions. See [START-HERE.md](START-HERE.md) for the shortest local-preview guide, [docs/USER-GUIDE.md](docs/USER-GUIDE.md) for each role's workflow, and [docs/INTERFACE-DESIGN.md](docs/INTERFACE-DESIGN.md) for the approved simple role-based UI pattern.

## Verification

```powershell
.\tools\artisan.cmd test
.\tools\php.cmd vendor\bin\pint --test
.\tools\composer.cmd validate --strict --no-check-publish
```

The tests cover access classifications, workspace isolation, formal delegation, request/approval/allocation/download/release, mixed conditional forms, final issued quantities, Early Return, overdue billing and settlement, theft evidence/RSLDDP, inventory data, and security boundaries.

## Deadline processor

For local testing, run it manually:

```powershell
.\tools\artisan.cmd spmu:process-deadlines
```

Production must continuously run Laravel's scheduler. The supplied Docker `scheduler` service does this automatically.

## Production deployment

The included Docker configuration uses PHP 8.4/Apache and MariaDB 11.4. Follow [docs/DEPLOYMENT-ICTU.md](docs/DEPLOYMENT-ICTU.md). Never use local demo accounts, the SQLite database, `APP_DEBUG=true`, or placeholder secrets in production.

## Intentionally open client values

The operational design expressly leaves these configurable until client approval: daily penalty tariff, SMS provider, official RSLDDP layout/status, backup schedule, final barricade quantity, and other opening inventory corrections. The system is functional with those values unset, but dependent actions remain visibly pending rather than inventing an official value. See [docs/CONFIGURATION-REGISTER.md](docs/CONFIGURATION-REGISTER.md).

## Source map

- `app/Http/Controllers` — role workspaces and form actions.
- `app/Services` — workflow, inventory, documents, signatures, custody, notifications, files, and audit logic.
- `app/Models` — business records and relationships.
- `database/migrations` — full relational schema.
- `database/seeders` — approved inventory/reference data and optional local accounts.
- `resources/views` and `public/css/app.css` — responsive user interface.
- `routes/web.php` and `routes/console.php` — protected pages and scheduled operations.
- `tests` — executable acceptance and regression coverage.
- `docker` and `docker-compose.yml` — ICTU deployment runtime.

Generated documents and evidence are protected application files, not public web files. Do not edit `vendor`, `database/database.sqlite`, generated storage files, or finalized records manually.
