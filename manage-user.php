<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$command = $argv[1] ?? '';
$email = strtolower(trim($argv[2] ?? ''));
$name = trim($argv[3] ?? '');
$role = strtolower(trim($argv[4] ?? ''));
$committee = trim($argv[5] ?? '');
$allowedRoles = ['adviser', 'executive', 'officer'];
$allowedCommittees = ['Executives', 'CommAIRS', 'CommCREL', 'CommFIMA', 'CommPROL', 'Adviser'];

function usage(): never
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  DALUYAN_USER_PASSWORD=<password> php manage-user.php add <email> <name> <adviser|executive|officer> [committee]\n");
    fwrite(STDERR, "  DALUYAN_USER_PASSWORD=<password> php manage-user.php set-password <seeded-email>\n");
    fwrite(STDERR, "  DALUYAN_USER_PASSWORD=<password> php manage-user.php reset-password <email>\n");
    fwrite(STDERR, "  php manage-user.php disable <email>\n");
    exit(2);
}

if (!in_array($command, ['add', 'set-password', 'reset-password', 'disable'], true) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@g.batstate-u.edu.ph')) {
    usage();
}

try {
    if ($command === 'disable') {
        $found = daluyan_disable_user($email);
        if (!$found) {
            fwrite(STDERR, "Account not found.\n");
            exit(1);
        }
        fwrite(STDOUT, "Account disabled.\n");
        exit(0);
    }

    $password = getenv('DALUYAN_USER_PASSWORD');
    if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
        fwrite(STDERR, "Set DALUYAN_USER_PASSWORD to a password between 12 and 72 bytes.\n");
        exit(2);
    }
    if ($command === 'add' && (!in_array($role, $allowedRoles, true) || $name === '' || strlen($name) > 160 || ($role === 'officer' && !in_array($committee, $allowedCommittees, true)))) {
        usage();
    }

    if ($command === 'add') {
        try {
            daluyan_add_user($email, $name, $role, $password, in_array($committee, $allowedCommittees, true) ? $committee : null);
            $result = 'created';
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                fwrite(STDERR, "Account already exists; use reset-password if needed.\n");
                exit(1);
            }
            throw $error;
        }
    } else {
        $result = daluyan_set_user_password($email, $password, $command === 'set-password');
    }

    if ($result === 'exists') {
        fwrite(STDERR, "Account already exists; use reset-password if needed.\n");
        exit(1);
    }
    if ($result === 'missing') {
        fwrite(STDERR, "Account not found.\n");
        exit(1);
    }
    if ($result === 'already_active') {
        fwrite(STDERR, "Account already has credentials; use reset-password if needed.\n");
        exit(1);
    }
    $messages = ['created' => 'Account created.', 'activated' => 'Seeded account activated.', 'updated' => 'Password reset.'];
    fwrite(STDOUT, ($messages[$result] ?? 'Account updated.') . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, "User management failed: " . $error->getMessage() . "\n");
    exit(1);
}
