# Start SPMU-ACPMP on Windows

Use Visual Studio Code to open and operate the project, and Microsoft Edge, Google Chrome, or Mozilla Firefox to use the system. The full manuscript-aligned environment uses Docker Desktop and MariaDB; the bundled local preview uses SQLite so the system can still be demonstrated before Docker Desktop is installed. Laragon, XAMPP, and WAMP are not required.

For the complete Visual Studio Code and Docker procedure, follow [RUN-IN-VSCODE.md](RUN-IN-VSCODE.md).

## 1. Open the correct project folder

In PowerShell, enter:

```powershell
cd "C:\Users\Admin\Documents\Codex\2026-08-11\ai\outputs\spmu-acpmp"
```

Your prompt must end in `spmu-acpmp>`, not `spmu-acpmp\tools>`.

## 2. Prepare the local database

Run:

```powershell
.\tools\artisan.cmd migrate --seed
```

This safely applies missing database changes and loads reference data. Do not use `migrate:fresh` unless you deliberately want to erase all local requests, users, and transactions.

## 3. Start the site

Run:

```powershell
.\tools\artisan.cmd serve --host=127.0.0.1 --port=8001
```

Keep that PowerShell window open. In Edge or Chrome, open:

```text
http://127.0.0.1:8001
```

## 4. Sign in as each role

The local demo password is `SPMU-Demo-2026!`.

| Role | Email | Main work |
|---|---|---|
| Borrower | `borrower@spmu.test` | Inventory, calendar, requests, approved-letter download, slip acknowledgement |
| SPMU Action Officer | `spmu@spmu.test` | Borrower/SPMU choice; inventory, final quantities, release/return, evidence, accountability |
| SPMU Head | `spmu-head@spmu.test` | First approval and required Head signatures; cannot borrow |
| GSU Head | `gsu@spmu.test` | Second approval; cannot borrow |
| VPAF Head | `vpaf@spmu.test` | Final approval/allocation and reports; cannot borrow |
| ICTU Maintainer | `ictu@spmu.test` | Borrower/ICTU choice; accounts, classifications, delegations, audit, technical operations |

Sign out before testing another role. These are local demonstration identities, not production accounts.

## 5. Test the main workflow

1. Borrower: open **Profile** and confirm an e-signature exists.
2. Borrower: check **Inventory** and **Calendar**, create a request, then certify and submit it.
3. SPMU Head: approve it under **Approval Queue**.
4. GSU: approve it.
5. VPAF: give final approval; only now is stock allocated.
6. Borrower: download the exact fully approved letter on the same day. This unlocks **Custody** and the Borrower's Slip.
7. SPMU Action Officer: record any lower final issued quantities and verify preparation.
8. Borrower: e-sign the quantity acknowledgement.
9. For off-campus Barricades: SPMU Action Officer e-signs **Verified By** and SPMU Head e-signs **Approved By**. For linen: Borrower and SPMU Head digitally sign the Laundry Form.
10. SPMU Action Officer: complete physical release. Only now do the final issued quantities become Borrowed.
11. SPMU Action Officer: record each physical return and condition. Complete guard/laundry evidence, incident, or billing work only when applicable.

## Stop and restart

To stop the site, return to PowerShell and press **Ctrl+C**. To restart later, repeat steps 1 and 3. Data remains in `database\database.sqlite`.

## Common errors

- **“The module tools could not be loaded”**: you were inside the `tools` folder and typed `tools\...`. Run `cd ..`, then use `.\tools\artisan.cmd ...`; or, while already inside `tools`, use `.\artisan.cmd ...`.
- **Employee number already exists**: every employee number is unique. Use the existing account's email/employee number or create a genuinely different employee record.
- **An error shows an older `2026-08-10` folder**: stop that older server with **Ctrl+C**, then use port 8001 exactly as shown above.
- **Port 8001 is already in use**: stop the other server or replace `8001` with `8002` in both the command and browser address.
- **SMS shows Failed**: this is expected until ICTU configures the approved provider and webhook. In-system and email attempts are still recorded.

## Run the automated checks

```powershell
.\tools\artisan.cmd test
.\tools\php.cmd vendor\bin\pint --test
```

## Open the source code

Open the whole `spmu-acpmp` folder in Visual Studio Code. Do not double-click individual PHP files to run the system, and do not manually edit `vendor`, `database\database.sqlite`, `.env` secrets, or generated files under `storage`.
