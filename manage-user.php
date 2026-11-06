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
$allowedRoles = ['adviser', 'executive', 'officer'];

function usage(): never
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  DALUYAN_USER_PASSWORD=<password> php manage-user.php add <email> <name> <adviser|executive|officer>\n");
    fwrite(STDERR, "  DALUYAN_USER_PASSWORD=<password> php manage-user.php reset-password <email>\n");
    fwrite(STDERR, "  php manage-user.php disable <email>\n");
    exit(2);
}

if (!in_array($command, ['add', 'reset-password', 'disable'], true) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@g.batstate-u.edu.ph')) {
    usage();
}

try {
    if ($command === 'disable') {
        $found = daluyan_update(static function (array &$data) use ($email): bool {
            foreach ($data['users'] as &$user) {
                if (strcasecmp((string) $user['email'], $email) === 0) {
                    $user['active'] = false;
                    unset($user);
                    return true;
                }
            }
            unset($user);
            return false;
        });
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
    if ($command === 'add' && (!in_array($role, $allowedRoles, true) || $name === '' || strlen($name) > 160)) {
        usage();
    }

    $result = daluyan_update(static function (array &$data) use ($command, $email, $name, $role, $password): string {
        foreach ($data['users'] as &$user) {
            if (strcasecmp((string) $user['email'], $email) === 0) {
                if ($command === 'add') {
                    unset($user);
                    return 'exists';
                }
                $user['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
                $user['active'] = true;
                unset($user);
                return 'updated';
            }
        }
        unset($user);
        if ($command === 'reset-password') {
            return 'missing';
        }
        $data['users'][] = [
            'id' => daluyan_uuid(),
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
            'active' => true,
            'createdAt' => gmdate(DATE_ATOM)
        ];
        return 'created';
    });

    if ($result === 'exists') {
        fwrite(STDERR, "Account already exists; use reset-password if needed.\n");
        exit(1);
    }
    if ($result === 'missing') {
        fwrite(STDERR, "Account not found.\n");
        exit(1);
    }
    fwrite(STDOUT, $result === 'created' ? "Account created.\n" : "Password reset.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "User management failed: " . $error->getMessage() . "\n");
    exit(1);
}
