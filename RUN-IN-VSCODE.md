# Run SPMU-ACPMP in Visual Studio Code

These steps use the manuscript-approved tools. Laragon, XAMPP, and WAMP are not required.

## A. Run the system now using the included local development runtime

1. Open **Visual Studio Code**.
2. Select **File > Open Folder**.
3. Open:

   `C:\Users\Admin\Documents\Codex\2026-08-11\ai\outputs\spmu-acpmp`

4. Select **Terminal > Run Task**.
5. Select **SPMU: Prepare local database**.
6. Wait until the migration and seeding task finishes.
7. Select **Terminal > Run Task** again.
8. Select **SPMU: Start local Laravel server**.
9. Keep that VS Code terminal running.
10. Select **Terminal > Run Task** again.
11. Select **SPMU: Open local site**.
12. Microsoft Edge, Google Chrome, or Mozilla Firefox will open:

    `http://127.0.0.1:8001`

13. Sign in with one of the local demonstration accounts.

| Workspace | Email | Password |
|---|---|---|
| Borrower | `borrower@spmu.test` | `SPMU-Demo-2026!` |
| SPMU Action Officer / Borrower | `spmu@spmu.test` | `SPMU-Demo-2026!` |
| SPMU Head | `spmu-head@spmu.test` | `SPMU-Demo-2026!` |
| GSU Head | `gsu@spmu.test` | `SPMU-Demo-2026!` |
| VPAF Head | `vpaf@spmu.test` | `SPMU-Demo-2026!` |
| ICTU Maintainer / Borrower | `ictu@spmu.test` | `SPMU-Demo-2026!` |

14. To stop the local server, open its VS Code terminal and press **Ctrl+C**.

The included development runtime uses SQLite for quick local testing. The application code remains PHP/Laravel and the interface uses HTML, CSS, JavaScript, and locally served Bootstrap.

## B. Run the complete manuscript-aligned Docker and MariaDB environment

1. Install **Docker Desktop for Windows** if it is not installed.
2. Open Docker Desktop.
3. Wait until Docker Desktop reports that its engine is running.
4. Open the project folder in Visual Studio Code.
5. Select **Terminal > Run Task**.
6. Select **SPMU: Prepare Docker environment**.
7. This creates `.env.docker` with local generated application and database secrets.
8. Select **Terminal > Run Task** again.
9. Select **SPMU: Start Docker + MariaDB**.
10. The first build can take several minutes because Docker downloads PHP, Apache, Composer, and MariaDB images.
11. Wait until the `database`, `app`, and `scheduler` containers are running.
12. Open Microsoft Edge, Google Chrome, or Mozilla Firefox.
13. Visit:

    `http://127.0.0.1:8080`

14. Use the same local demonstration accounts shown above.
15. To stop the Docker environment, select **Terminal > Run Task > SPMU: Stop Docker**.

Do not delete Docker volumes unless you intentionally want to erase the Docker/MariaDB database.

## C. Run verification in Visual Studio Code

1. Select **Terminal > Run Task**.
2. Select **SPMU: Run automated tests**.
3. Confirm that all tests pass.
4. Optionally open **Extensions** and install the project recommendations:
   - PHP Intelephense
   - Laravel Blade support
   - Docker extension

## D. Use Git for team collaboration

1. Open the **Source Control** panel in Visual Studio Code.
2. Initialize or clone the team Git repository if your team has not done so.
3. Review changed files before committing.
4. Never commit `.env`, `.env.docker`, passwords, uploaded evidence, the SQLite database, or files under `storage`.
5. Commit only reviewed source code, migrations, documentation, and configuration examples.

## E. Important project folders in Visual Studio Code

1. Open `app/Http/Controllers` for page and action controllers.
2. Open `app/Services` for borrowing, inventory, documents, signatures, custody, and notification rules.
3. Open `resources/views` for the role-specific screens.
4. Open `public/css/app.css` for the original SPMU visual design layered on Bootstrap.
5. Open `routes/web.php` for protected web routes.
6. Open `database/migrations` for the MySQL/MariaDB-compatible database structure.
7. Open `tests/Feature` for workflow and access-control verification.

## F. Applications from the manuscript

1. **Visual Studio Code** - primary development environment.
2. **Git** - version control and team collaboration.
3. **Docker Desktop** - consistent application deployment.
4. **MariaDB** - Docker database for the complete environment.
5. **PHP/Laravel** - backend application framework.
6. **HTML, CSS, JavaScript, and Bootstrap** - responsive user interface.
7. **Microsoft Edge, Google Chrome, or Mozilla Firefox** - system operation and compatibility testing.
