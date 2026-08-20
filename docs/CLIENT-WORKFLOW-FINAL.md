# Client Workflow Final — 20 August 2026

This document is the current implementation baseline for the `client-workflow-update` branch.

## Active application access

- Borrower — only classification allowed to borrow.
- SPMU Admin / Head — SPMU verification/decision authority and oversight.
- SPMU Action Officer — pickup, exact preparation, release/return and operations; SPMU decision only with valid delegation.
- Laundry Worker — linen-only work queue and accomplished Laundry Form upload.
- ICTU Maintainer — accounts, configuration, audit and technical operations.

## GSU / VPAF treatment

GSU and VPAF are **not application roles**. They do not receive portal access, approval queues, user-account assignments or electronic-signature actions. Their names remain only where needed for:

1. physical Borrowing Request Letter signatory blocks; and
2. historical database compatibility for older records.

Old GSU/VPAF account records are deactivated and hidden from active User Administration instead of being deleted, preserving audit/foreign-key history.

## Borrowing request

1. Borrower saves a Draft.
2. System generates printable Draft Borrowing Request Letter.
3. Borrower prints it and obtains the required handwritten/wet signatures.
4. Borrower scans and uploads the fully signed letter; Permission to Conduct is also uploaded for applicable student activity/organization requests.
5. Borrower submits to SPMU.
6. SPMU verifies the uploaded scan and request details in one system stage.
7. SPMU Approve reserves exact quantity; Return for Revision/Reject creates no reservation.

## Pickup / release

- Custody/pickup record is created immediately after SPMU approval/reservation.
- SPMU Action Officer schedules pickup and prepares the exact approved quantity.
- System generates Borrower Slip and only applicable Gate Pass/Laundry Form.
- Signatures on these forms are physical handwritten/wet signatures.
- SPMU records physical release; no online e-signature is required.

## Linen / Laundry Worker

When released custody contains a laundry-required linen item:

1. a Laundry Job appears in the Laundry Worker portal;
2. borrower brings used linen and the physical Laundry Form to Laundry after use;
3. Laundry Worker processes the linen and uploads the accomplished wet-signed form;
4. Laundry Worker releases the clean linen to the borrower;
5. SPMU verifies/encodes the form and performs final physical return inspection;
6. linen returns to Available only after final SPMU completion.

## Compatibility

Legacy enum/status/schema fields remain where removing them would break historical records. They are not exposed as current portals or workflow stages.
