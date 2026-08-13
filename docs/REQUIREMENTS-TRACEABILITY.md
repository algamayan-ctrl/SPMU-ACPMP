# Requirements Traceability Summary

| Approved requirement area | Primary implementation | Verification |
|---|---|---|
| Identity, eligibility, role/workspace matrix | AccessClassification, ICTU user administration, active-workspace middleware | AccessControlTest, RoleWorkspaceSmokeTest, RevisionControlsTest |
| Heads cannot borrow; dual-workspace officers | Access classification policies and workspace selector | RevisionControlsTest |
| Temporary delegated approver | TemporaryDelegation, own-account signatures, decision linkage and audit | RevisionControlsTest |
| Profile and e-signature | ProfileController, protected storage, SignatureService snapshots | page smoke tests, workflow tests |
| Real-time inventory/calendar | InventoryService, InventoryController, CalendarController | FoundationTest, workflow allocation assertions |
| Request letter and student representation | BorrowingRequestController, version/item snapshots, DocumentService | CompleteWorkflowTest |
| SPMU → GSU → VPAF approvals | RequestWorkflowService, approval steps/signatures/timestamps | CompleteWorkflowTest |
| Atomic allocation and conflict control | InventoryService database transaction/locking | CompleteWorkflowTest reduced-quantity and allocation assertions |
| Same-day download gate and slip | DocumentController, DownloadEvent, controlled hashes, custody creation | CompleteWorkflowTest |
| Preparation, acknowledgement, physical release | CustodyService, signature snapshot, inventory ledger | CompleteWorkflowTest |
| Cancellation/Early Return | RequestWorkflowService, EarlyReturnRequest and physical inspection gate | RevisionControlsTest and workflow rules |
| Gate Pass and Barricade policy | per-line location, two digital SPMU signatures, guard evidence | RevisionControlsTest and feature routes |
| Laundry | linen-only form, Borrower/Head digital signatures, worker wet-signature evidence | RevisionControlsTest and service rules |
| Consolidated official packet | DocumentService multi-page packet with conditional page suppression | RevisionControlsTest |
| Returns and incidents | return/custody/incident models and services | theft/evidence/RSLDDP workflow test |
| Overdue, tariff, sanctions, restriction | scheduled deadline command, OverdueCase/Penalty/Restriction | overdue billing/payment/closeout test |
| Billing, external OR, settlement/waiver | Borrower receipt upload; SPMU original inspection/OR encoding; Billing/Payment models | overdue settlement test and rendered accountability pages |
| Notifications | system inbox, SMTP, SMS webhook, delivery evidence | workflow records and role-page smoke tests |
| Reports and KPIs | ReportController, CSV exports, KPI observations | export/page smoke tests |
| ICTU deployment/security/backup | Docker stack, scheduler, headers, throttling, local backup and operating guide | migration, tests, Pint, Composer validation |

The source requirements intentionally mark tariffs, SMS provider, official RSLDDP/report layouts, backup schedule, and final inventory corrections as open. Their configuration records and dependency gates exist; institutional values must be supplied during client/ICTU acceptance.
