<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'database.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
daluyan_session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'session');

try {
    if ($method === 'GET') {
        if ($action === 'session') {
            $user = daluyan_current_user();
            daluyan_json(['csrf' => $_SESSION['csrf'], 'user' => $user]);
        }
        if ($action === 'directory') {
            $data = daluyan_read();
            daluyan_json(['officers' => $data['directory']]);
        }
        if ($action === 'dashboard') {
            daluyan_require_user(['adviser', 'executive']);
            $days = filter_var($_GET['days'] ?? 7, FILTER_VALIDATE_INT);
            daluyan_json(['stats' => daluyan_dashboard_stats(daluyan_read(), is_int($days) ? $days : 7)]);
        }
        daluyan_json(['error' => 'Unknown action.'], 404);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        daluyan_json(['error' => 'Method not allowed.'], 405);
    }

    daluyan_verify_request();
    $body = daluyan_read_json_body();

    if ($action === 'login') {
        $email = strtolower(daluyan_required_string($body, 'email', 254));
        $password = (string) ($body['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@g.batstate-u.edu.ph') || strlen($password) > 72) {
            daluyan_json(['error' => 'Email or password is incorrect.'], 401);
        }
        $key = hash('sha256', $email . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $data = daluyan_read();
        $recentAttempts = array_values(array_filter(
            $data['loginAttempts'][$key] ?? [],
            static fn(int $attempt): bool => $attempt > time() - 900
        ));
        if (count($recentAttempts) >= 5) {
            daluyan_json(['error' => 'Too many sign-in attempts. Try again in 15 minutes.'], 429);
        }
        $user = daluyan_find_user($data, $email);
        if ($user === null || empty($user['active']) || !password_verify($password, (string) ($user['passwordHash'] ?? ''))) {
            daluyan_update(static function (array &$store) use ($key, $recentAttempts): void {
                $recentAttempts[] = time();
                $store['loginAttempts'][$key] = $recentAttempts;
            });
            daluyan_json(['error' => 'Email or password is incorrect.'], 401);
        }
        daluyan_update(static function (array &$store) use ($key): void {
            unset($store['loginAttempts'][$key]);
        });
        session_regenerate_id(true);
        $_SESSION['user'] = daluyan_safe_user($user);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        daluyan_json(['user' => $_SESSION['user'], 'csrf' => $_SESSION['csrf']]);
    }

    if ($action === 'logout') {
        daluyan_require_user();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, [
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Strict'
            ]);
        }
        session_destroy();
        daluyan_json(['ok' => true]);
    }

    if ($action === 'dtn') {
        $user = daluyan_require_user();
        $subject = daluyan_required_string($body, 'subject', 200);
        $committee = daluyan_required_string($body, 'committee', 40);
        $requesterEmail = strtolower(daluyan_required_string($body, 'email', 254));
        $description = daluyan_required_string($body, 'description', 2000);
        if (!filter_var($requesterEmail, FILTER_VALIDATE_EMAIL) || !str_ends_with($requesterEmail, '@g.batstate-u.edu.ph') || !in_array($committee, ['CommAIRS', 'CommCREL', 'CommFIMA', 'CommPROL'], true)) {
            daluyan_json(['error' => 'Enter a valid university requesting email.'], 422);
        }
        if ($user['role'] === 'officer' && strcasecmp($requesterEmail, $user['email']) !== 0) {
            daluyan_json(['error' => 'Officers may submit requests only for their own account.'], 403);
        }
        $result = daluyan_update(static function (array &$data) use ($user, $subject, $committee, $requesterEmail, $description): array {
            $number = (int) ($data['nextDtnNumber'] ?? 1);
            $data['nextDtnNumber'] = $number + 1;
            $record = [
                'id' => daluyan_uuid(),
                'code' => sprintf('SSC-UIM-%s-%04d', gmdate('Y'), $number),
                'subject' => $subject,
                'committee' => $committee,
                'requesterEmail' => $requesterEmail,
                'description' => $description,
                'submittedBy' => $user['email'],
                'status' => 'submitted',
                'createdAt' => gmdate(DATE_ATOM)
            ];
            $data['dtnRequests'][] = $record;
            return $record;
        });
        daluyan_json(['request' => $result], 201);
    }

    if ($action === 'duty') {
        $user = daluyan_require_user();
        $officerEmail = strtolower(daluyan_required_string($body, 'officerEmail', 254));
        $event = daluyan_required_string($body, 'event', 200);
        $venue = daluyan_required_string($body, 'venue', 200);
        $status = daluyan_required_string($body, 'status', 20);
        if (!filter_var($officerEmail, FILTER_VALIDATE_EMAIL) || !in_array($status, ['check_in', 'check_out', 'on_call'], true)) {
            daluyan_json(['error' => 'Officer or duty status is invalid.'], 422);
        }
        if ($user['role'] === 'officer' && strcasecmp($officerEmail, $user['email']) !== 0) {
            daluyan_json(['error' => 'Officers may log attendance only for their own account.'], 403);
        }
        $data = daluyan_read();
        $officer = null;
        foreach ($data['directory'] as $entry) {
            if (strcasecmp((string) ($entry['email'] ?? ''), $officerEmail) === 0) {
                $officer = $entry;
                break;
            }
        }
        if ($officer === null) {
            daluyan_json(['error' => 'Officer was not found in the council directory.'], 422);
        }
        $result = daluyan_update(static function (array &$store) use ($user, $officer, $officerEmail, $event, $venue, $status): array {
            $now = gmdate(DATE_ATOM);
            if ($status === 'check_out') {
                for ($index = count($store['attendance']) - 1; $index >= 0; $index--) {
                    $record = &$store['attendance'][$index];
                    if (strcasecmp((string) $record['officerEmail'], $officerEmail) === 0 && ($record['status'] ?? '') === 'checked_in' && empty($record['checkOutAt'])) {
                        $record['checkOutAt'] = $now;
                        $record['status'] = 'complete';
                        $record['hours'] = round(max(0, (strtotime($now) - strtotime($record['checkInAt'])) / 3600), 2);
                        $record['checkedOutBy'] = $user['email'];
                        return $record;
                    }
                }
                return ['error' => 'No open check-in was found for this officer.'];
            }
            $record = [
                'id' => daluyan_uuid(),
                'officerName' => $officer['name'],
                'officerEmail' => $officerEmail,
                'committee' => $officer['category'],
                'event' => $event,
                'venue' => $venue,
                'status' => $status === 'check_in' ? 'checked_in' : 'on_call',
                'checkInAt' => $now,
                'checkOutAt' => null,
                'hours' => 0.0,
                'loggedBy' => $user['email']
            ];
            $store['attendance'][] = $record;
            return $record;
        });
        if (isset($result['error'])) {
            daluyan_json(['error' => $result['error']], 422);
        }
        daluyan_json(['attendance' => $result], 201);
    }

    if ($action === 'leave') {
        $user = daluyan_require_user();
        $officerEmail = strtolower(daluyan_required_string($body, 'email', 254));
        $committee = daluyan_required_string($body, 'committee', 40);
        $startDate = daluyan_required_string($body, 'start', 10);
        $endDate = daluyan_required_string($body, 'end', 10);
        $reason = daluyan_required_string($body, 'reason', 2000);
        $validStartDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) === 1 && checkdate((int) substr($startDate, 5, 2), (int) substr($startDate, 8, 2), (int) substr($startDate, 0, 4));
        $validEndDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) === 1 && checkdate((int) substr($endDate, 5, 2), (int) substr($endDate, 8, 2), (int) substr($endDate, 0, 4));
        if (!filter_var($officerEmail, FILTER_VALIDATE_EMAIL) || !str_ends_with($officerEmail, '@g.batstate-u.edu.ph') || !in_array($committee, ['CommAIRS', 'CommCREL', 'CommFIMA', 'CommPROL', 'Higher Executives'], true) || !$validStartDate || !$validEndDate || $startDate > $endDate) {
            daluyan_json(['error' => 'Email or leave dates are invalid.'], 422);
        }
        if ($user['role'] === 'officer' && strcasecmp($officerEmail, $user['email']) !== 0) {
            daluyan_json(['error' => 'Officers may file leave only for their own account.'], 403);
        }
        $request = [
            'id' => daluyan_uuid(),
            'officerEmail' => $officerEmail,
            'committee' => $committee,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'reason' => $reason,
            'submittedBy' => $user['email'],
            'status' => 'pending',
            'createdAt' => gmdate(DATE_ATOM)
        ];
        daluyan_update(static function (array &$data) use ($request): void {
            $data['leaveRequests'][] = $request;
        });
        daluyan_json(['request' => $request], 201);
    }

    daluyan_json(['error' => 'Unknown action.'], 404);
} catch (Throwable $error) {
    error_log('DALUYAN API error: ' . $error->getMessage());
    daluyan_json(['error' => 'The server could not complete the request.'], 500);
}
