# Configuration Register

SPMU/ICTU manage these values from **Administration → Configuration**. Every change requires a reason and records before/after values.

| Key | Initial value | Owner | Operational effect |
|---|---:|---|---|
| `approved_letter_download_time` | `23:59` | SPMU | Same-day Asia/Manila deadline for the fully approved letter |
| `overdue_grace_hours` | `24` | SPMU/VPAF policy | Grace period before Overdue, restriction, sanction, and tariff |
| `daily_overdue_tariff` | unset | Client/SPMU/VPAF | Daily amount; billing remains blocked until finalized |
| `sms_provider` | unset | ICTU | Provider name; webhook and token are environment secrets |
| `due_soon_hours` | `24` | SPMU | Due-soon reminder window |
| `rslddp_template_status` | `PROVISIONAL` | Client/SPMU | Set to `APPROVED` only after official content/layout approval; then incident output is generated |
| `max_upload_mb` | `5` | ICTU/SPMU | Maximum protected signature/evidence upload size |
| `backup_schedule` | `NOT_FINALIZED` | ICTU | Documentary record of the approved backup schedule |

## Values requiring client confirmation

- Opening inventory reconciliation, including the provisional Barricade quantity of six.
- Penalty/tariff amounts and category-specific rules.
- Official RSLDDP content, acronym wording, appraisal fields, signatories, and layout.
- Approved SMS provider, sender identity, delivery contract, and privacy terms.
- Production backup frequency, retention, encryption, off-site copy, and restore-test schedule.
- Final institutional report layouts and SLDDRP/RSLDDP naming/content.

The system intentionally does not invent these values. Unset dependencies produce a visible pending/failed state without rolling back valid business transactions.
