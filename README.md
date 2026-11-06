# DALUYAN PHP and JSON Data Store

## Requirements

- PHP 8.1 or later with JSON and session support.
- Serve the project through PHP. Do not open the HTML files directly with `file://` when using login or database-backed features.

For local development, open PowerShell in this folder and run:

```powershell
php -S 127.0.0.1:8000 router.php
```

Then visit `http://127.0.0.1:8000/`. The PHP development server is for local testing only; deploy behind an HTTPS-enabled web server for shared use.

## Data Store

The JSON store is created on first API request at `../daluyan-private/database.json`, outside the project web root. The council directory is initialized from `data/seed.json`; role definitions come from `data/roles.json`; and pending account records are seeded from the document roster. Password hashes, attendance, DTN requests, and leave requests start empty. Set `DALUYAN_DATA_FILE` on the server to choose another private path. Keep backups of this file and restrict its filesystem permissions.

The file store uses OS file locks and is intended for a small, single-server council portal. It is not a replacement for a transactional database under substantial concurrent use; migrate to PostgreSQL/MySQL if the workload grows.

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

Repeat the secure PowerShell prompt for each pending account. Passwords must be 12-72 bytes. `set-password` activates a document-seeded account without changing its role. To reset an existing password, run `manage-user.php reset-password <email>` with `DALUYAN_USER_PASSWORD` set. To add a new account not listed in the document, use `manage-user.php add <email> <name> <adviser|executive|officer>`. To revoke access, run `manage-user.php disable <email>`.

## API Coverage

- Login/logout use PHP sessions, HTTP-only SameSite cookies, CSRF tokens, university-email validation, and per-account/IP throttling.
- The committee directory is read from the JSON store.
- DTN requests and leave applications are persisted with submitter and timestamp.
- Attendance check-ins and on-call duty are persisted. A check-out closes the officer's most recent open check-in and calculates elapsed hours.
- Dashboard statistics and attendance reports require an authenticated `adviser` or `executive` role.
- Officers can submit records only for their own account; advisers and executives can submit on behalf of directory members.

## Current Limitations

- PHP 8.4.27 syntax checks passed for the API, database helper, router, root entry point, and account manager. An isolated PHP-server test verified seeded-account activation, officer dashboard denial, self-only attendance, executive dashboard access, DTN/leave persistence, and logout.
- Account provisioning is CLI-only; there is no password-reset email or admin UI.
- Attendance is based on server timestamps. Compliance needs a configured schedule or expected-duty model before it can be calculated accurately.
