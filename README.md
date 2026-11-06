# DALUYAN MySQL Database

## Requirements

- PHP 8.1 or later with PDO and `pdo_mysql` enabled.
- MySQL 8.0+ or a compatible MariaDB server.
- Serve the project through PHP. Do not open the HTML files directly with `file://` when using login or database-backed features.

## Database Setup

In phpMyAdmin, select the SQL tab and run the contents of [schema.sql](schema.sql). It creates the `daluyan_uim` database, normalized tables, committee records, role definitions, and role permissions. The import needs permission to create the database; alternatively, create `daluyan_uim` first and remove the `CREATE DATABASE` and `USE` statements before importing.

Make sure the PHP configuration used by the web server loads `pdo_mysql` (for example, enable `extension=pdo_mysql` in `php.ini`). In phpMyAdmin, create a dedicated app user with a unique password and only data privileges on this database:

```sql
CREATE USER 'daluyan_app'@'localhost' IDENTIFIED BY '<set-a-unique-password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON daluyan_uim.* TO 'daluyan_app'@'localhost';
```

Set the connection values in the server environment. Do not commit the password or put it in a public PHP file. For local PowerShell testing:

```powershell
$env:DALUYAN_DSN = 'mysql:host=127.0.0.1;dbname=daluyan_uim;charset=utf8mb4'
$env:DALUYAN_DB_USER = 'daluyan_app'
$env:DALUYAN_DB_PASSWORD = '<your database password>'
php seed-database.php
php -S 127.0.0.1:8000 router.php
```

Then visit `http://127.0.0.1:8000/`. The PHP development server is for local testing only; deploy behind an HTTPS-enabled web server for shared use.

The seed command creates inactive accounts with no passwords. Run it again safely if needed; it does not overwrite existing accounts or credentials. Password hashes, attendance, DTN requests, and leave requests start empty.

The dashboard keeps the original Week 8 numbers under “Sample report reference · demo data only.” They are never included in live totals. Live compliance percentage is intentionally not calculated because expected attendance counts have not been defined.

## User Accounts

The document roster creates pending accounts for the adviser, three higher executives, and the listed committee officers. No account is active until an administrator sets its password. All accounts use their document-listed `@g.batstate-u.edu.ph` email. The `adviser` and `executive` roles can view all-committee dashboard reports and submit records for other officers; the `officer` role can submit only for its own account and assigned committee and cannot access the executive dashboard. Facilitating officer/head/member titles and committee assignments are retained on each user record; those titles do not grant broader permissions unless the role policy is deliberately changed.

Set the password in the environment rather than putting it in the command line. In PowerShell:

```powershell
$secure = Read-Host "Account password" -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
$env:DALUYAN_USER_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
php manage-user.php set-password "24-71097@g.batstate-u.edu.ph"
Remove-Item Env:DALUYAN_USER_PASSWORD
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
```

Repeat the secure PowerShell prompt for each pending account. Passwords must be 12-72 bytes. `set-password` activates a document-seeded account without changing its role. To reset an existing password, run `manage-user.php reset-password <email>` with `DALUYAN_USER_PASSWORD` set. To add a new adviser or executive, use `manage-user.php add <email> <name> <adviser|executive>`. A new officer must also have a committee assignment: `manage-user.php add <email> <name> officer <CommAIRS|CommCREL|CommFIMA|CommPROL|Executives>`. To revoke access, run `manage-user.php disable <email>`.

## API Coverage

- Login/logout use PHP sessions, HTTP-only SameSite cookies, CSRF tokens, university-email validation, and per-account/IP throttling.
- The committee directory, users, committee assignments, and role permissions are stored in normalized MySQL tables.
- DTN requests and leave applications are persisted with submitter and timestamp.
- Attendance check-ins and on-call duty are persisted. A check-out closes the officer's most recent open check-in and calculates elapsed hours.
- Dashboard statistics and attendance reports require an authenticated `adviser` or `executive` role.
- Officers can submit records only for their own account; advisers and executives can submit on behalf of directory members.

## Current Limitations

- PHP 8.4.27 syntax checks passed for the API, database helper, router, root entry point, and account manager. PDO MySQL must be enabled in the PHP configuration used by the server.
- The MySQL server used by phpMyAdmin was not available in the workspace for a live PDO connection test. Import the schema and test credentials in a staging database before production.
- Account provisioning is CLI-only; there is no password-reset email or admin UI.
- Attendance is based on server timestamps. Compliance needs a configured schedule or expected-duty model before it can be calculated accurately.
