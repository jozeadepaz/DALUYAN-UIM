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

The JSON store is created on first API request at `../daluyan-private/database.json`, outside the project web root. The council directory is initialized from `data/seed.json`; attendance, DTN requests, leave requests, and accounts start empty. Set `DALUYAN_DATA_FILE` on the server to choose another private path. Keep backups of this file and restrict its filesystem permissions.

The file store uses OS file locks and is intended for a small, single-server council portal. It is not a replacement for a transactional database under substantial concurrent use; migrate to PostgreSQL/MySQL if the workload grows.

The dashboard keeps the original Week 8 numbers under “Sample report reference · demo data only.” They are never included in live totals. Live compliance percentage is intentionally not calculated because expected attendance counts have not been defined.

## User Accounts

There are no default users or passwords. Provision accounts from a trusted server terminal. Roles are `adviser`, `executive`, and `officer`; only the first two can access the executive dashboard. Any active role can use mechanism forms. Accounts must use a `@g.batstate-u.edu.ph` email address.

Set the password in the environment rather than putting it in the command line. In PowerShell:

```powershell
$secure = Read-Host "Account password" -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
$env:DALUYAN_USER_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
php manage-user.php add "24-71097@g.batstate-u.edu.ph" "Officer Name" executive
Remove-Item Env:DALUYAN_USER_PASSWORD
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
```

Use role `adviser` for the adviser account and `executive` for executive accounts. Passwords must be 12-72 bytes. To reset an existing password, run `manage-user.php reset-password <email>` with `DALUYAN_USER_PASSWORD` set. To revoke access, run `manage-user.php disable <email>`.

## API Coverage

- Login/logout use PHP sessions, HTTP-only SameSite cookies, CSRF tokens, university-email validation, and per-account/IP throttling.
- The committee directory is read from the JSON store.
- DTN requests and leave applications are persisted with submitter and timestamp.
- Attendance check-ins and on-call duty are persisted. A check-out closes the officer's most recent open check-in and calculates elapsed hours.
- Dashboard statistics and attendance reports require an authenticated `adviser` or `executive` role.
- Officers can submit records only for their own account; advisers and executives can submit on behalf of directory members.

## Current Limitations

- This workspace does not include PHP, so the PHP runtime checks could not be run here. Verify with `php -l api.php`, `php -l database.php`, `php -l manage-user.php`, and `php -l router.php` after installing PHP.
- Account provisioning is CLI-only; there is no password-reset email or admin UI.
- Attendance is based on server timestamps. Compliance needs a configured schedule or expected-duty model before it can be calculated accurately.
