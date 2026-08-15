# SPMU-ACPMP Interface Design

## Design direction

The interface uses the SPHERE manuscript only as a usability reference. It adopts a familiar blue role menu, a quiet white workspace, compact summary cards, clear task lists, readable tables, and plain-language actions. It does not copy SPHERE's rental, facility, payment, attendance, plan, or public-registration functions.

SPMU-ACPMP remains based on the approved CSPC property-borrowing process: Borrower request, SPMU approval, GSU approval, VPAF approval and allocation, approved-letter download, Borrower Slip, release, item-level return, conditional forms, accountability, reports, and ICTU administration.

## Shared usability rules

1. Show only the tools authorized for the assigned portal.
2. Use familiar words such as `Create new request`, `View details`, `Check availability`, and `Return deadline`.
3. Keep the current role visible near the top of every page.
4. Put the most common task first and avoid presenting every possible action at once.
5. Use large form controls, clear labels, visible status tags, and explicit empty-state messages.
6. Never rely on color alone; every status includes readable text.
7. Keep advanced information available on detail pages instead of crowding dashboards.
8. Preserve the same navigation order and page pattern throughout a portal.

## Portal menus

### Borrower

- Dashboard
- Available Items
- Borrowing Calendar
- My Requests
- My Borrowings
- Accountability

### SPMU

- Dashboard
- Approval Queue
- All Requests
- Inventory
- Borrowing Calendar
- Release and Return
- Accountability
- Reports
- Configuration

### GSU Approver

- Dashboard
- Approval Queue
- Request Records
- Inventory View
- Borrowing Calendar

### VPAF Approver

- Dashboard
- Approval Queue
- Request Records
- Inventory View
- Borrowing Calendar
- Reports

### ICTU

- Dashboard
- User Accounts
- Delegated Approvers
- System Settings
- Audit Trail
- Delivery Records

ICTU does not receive a borrowing calendar or general approval queue in its administration portal. ICTU Maintainers cannot borrow; formal temporary delegation remains limited to the applicable approval action.

## Page pattern

1. Compact top bar: page name, assigned portal, notifications, profile, and sign out.
2. Page heading: short category label, plain-language title, one supporting sentence, and at most a few relevant actions.
3. Dashboard: three summary cards, one attention list, common-task shortcuts, and an upcoming-deadline preview when relevant.
4. Record lists: focused filters, readable status labels, and a `View details` action.
5. Forms: visible labels above controls, one clear primary action, and explanatory text only where it prevents a mistake.
6. Mobile view: collapsible role menu, single-column cards, horizontally scrollable data tables, and touch-friendly controls.

## Protected process rules

Interface simplification must never remove or bypass sequential approvals, self-approval restrictions, signature attribution, same-day approved-letter download, allocation controls, item-level custody status, Gate Pass and Laundry Form conditions, return inspection, accountability evidence, or audit records.
