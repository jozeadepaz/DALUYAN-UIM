# DALUYAN Setup and Deployment

## Requirements

- PHP 8.1+ with PDO, `pdo_mysql`, JSON, and sessions.
- MySQL 8+ or compatible MariaDB.
- Serve through PHP; database-backed features do not work when opening HTML files with `file://`.

## Database and local setup

Import [schema.sql](schema.sql) using phpMyAdmin or the MySQL CLI. It creates the database, tables, committees, roles, and permissions. If the database already exists, remove the `CREATE DATABASE` and `USE` statements before importing. Re-import the updated schema on an existing installation to create the attendance approval queue and grant its permissions. Ensure the PHP configuration used by the web server enables `pdo_mysql`.

Create a dedicated database user with a unique password:

```sql
CREATE USER 'daluyan_app'@'localhost' IDENTIFIED BY '<set-a-unique-strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON daluyan_uim.* TO 'daluyan_app'@'localhost';
```

Set the connection values in the server environment, not in source files:

```powershell
$env:DALUYAN_DSN = 'mysql:host=127.0.0.1;dbname=daluyan_uim;charset=utf8mb4'
$env:DALUYAN_DB_USER = 'daluyan_app'
$env:DALUYAN_DB_PASSWORD="your_secure_password_here"
php seed-database.php
php -S 127.0.0.1:8000 router.php
```

Visit `http://127.0.0.1:8000/`. The built-in PHP server is for local testing only; use an HTTPS-enabled web server for deployment.

The seed command creates pending accounts without passwords and can be safely rerun; it does not overwrite existing accounts or credentials. Attendance, DTN, and leave records start empty.

## Accounts and access

Accounts use the roster’s `@g.batstate-u.edu.ph` addresses and remain inactive until provisioned. Set a pending account’s password in PowerShell without placing it in command history:

```powershell
$secure = Read-Host "Account password" -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
$env:DALUYAN_USER_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
php manage-user.php set-password "user@g.batstate-u.edu.ph"
Remove-Item Env:DALUYAN_USER_PASSWORD
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
```

Passwords must be 12–72 bytes. Use `manage-user.php reset-password <email>` to reset an account, `manage-user.php add <email> <name> <adviser|executive>` to add an adviser or executive, or `manage-user.php add <email> <name> officer <committee>` to add an officer (`CommAIRS`, `CommCREL`, `CommFIMA`, `CommPROL`, or `Executives`). Use `manage-user.php disable <email>` to revoke access. Provisioning is CLI-only; password reset email and an admin UI are not available.

After login, users land on `my-dashboard.php`:

- Members see their own attendance and DTN requests.
- Heads, co-heads, and executives see records for their assigned committee(s).
- The adviser sees all records.
- CommAIRS accounts can review all DTN requests and CommAIRS duty/leave records, and submit duty or leave requests for active CommAIRS members, including facilitating officers. Duty entries remain pending until an adviser or executive approves them from the executive dashboard; rejected entries are not added to attendance records.

These scopes are enforced by the API. Members submit only their own records; on-behalf actions are limited to the assigned committees. Login uses university-email validation, PHP sessions, and CSRF protection. Failed attempts are recorded, but login throttling is disabled. Council services and the dashboard require an active signed-in account; the landing page and directory are public.

## Deployment

The included `.htaccess` is an Apache 2.4 baseline: it disables directory listings, blocks sensitive files and paths, rejects `TRACE`, and adds security headers. Apache deployments need `mod_rewrite`, `mod_headers`, and suitable `AllowOverride` settings. Nginx and managed PHP hosts ignore `.htaccess`; configure and verify equivalent restrictions and headers there.

1. Deploy without `.git`. Set the document root and deny rules so repository metadata, seed data, schema, documentation, and CLI scripts cannot be downloaded.
2. Import the schema, create the restricted database user, and configure `DALUYAN_DSN`, `DALUYAN_DB_USER`, and `DALUYAN_DB_PASSWORD` as server environment variables.
3. Run `php seed-database.php` and provision accounts with `manage-user.php` on the server.
4. Require HTTPS, disable PHP error display, and enable server-side error logging. Configure PHP to recognize HTTPS so secure session cookies work. Enable HSTS only after HTTPS is fully working for the domain and included subdomains.
5. On staging, verify protected-page redirects, role-specific record visibility, CommAIRS submission scopes and duty confirmation, CSRF rejection, and database backup/restore before accepting real records.

## Notes and validation

The sample Week 8 report data is demo-only and excluded from live totals. Live compliance is not calculated until attendance expectations are defined.

The current workspace has PHP 8.4.27 but no `pdo_mysql` extension or configured database, so database integration and authenticated flows must be tested against staging. The hosting provider is not yet selected. Attendance uses server timestamps.
