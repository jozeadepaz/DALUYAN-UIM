<?php
declare(strict_types=1);

function daluyan_data_path(): string
{
    $configured = getenv('DALUYAN_DATA_FILE');
    $path = $configured !== false && $configured !== ''
        ? $configured
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'daluyan-private' . DIRECTORY_SEPARATOR . 'database.json';
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the private data directory.');
    }
    return $path;
}

function daluyan_initial_data(): array
{
    $seedPath = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'seed.json';
    $directory = is_file($seedPath) ? json_decode((string) file_get_contents($seedPath), true) : [];
    if (!is_array($directory)) {
        throw new RuntimeException('The directory seed file is invalid JSON.');
    }

    return [
        'users' => [],
        'directory' => $directory,
        'attendance' => [],
        'dtnRequests' => [],
        'leaveRequests' => [],
        'loginAttempts' => []
    ];
}

function daluyan_normalize_data(array $data): array
{
    foreach (['users', 'directory', 'attendance', 'dtnRequests', 'leaveRequests', 'loginAttempts'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }
    return $data;
}

function daluyan_update(callable $operation): mixed
{
    $path = daluyan_data_path();
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the private data store.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock the private data store.');
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        $data = $contents === false || trim($contents) === ''
            ? daluyan_initial_data()
            : json_decode($contents, true);
        if (!is_array($data)) {
            throw new RuntimeException('The private data store contains invalid JSON.');
        }
        $data = daluyan_normalize_data($data);
        $result = $operation($data);
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode the private data store.');
        }
        rewind($handle);
        $contents = $encoded . PHP_EOL;
        if (!ftruncate($handle, 0)) {
            throw new RuntimeException('Unable to save the private data store.');
        }
        $written = 0;
        while ($written < strlen($contents)) {
            $count = fwrite($handle, substr($contents, $written));
            if ($count === false || $count === 0) {
                throw new RuntimeException('Unable to save the private data store.');
            }
            $written += $count;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('Unable to flush the private data store.');
        }
        flock($handle, LOCK_UN);
        return $result;
    } finally {
        fclose($handle);
    }
}

function daluyan_read(): array
{
    $path = daluyan_data_path();
    if (!is_file($path) || filesize($path) === 0) {
        daluyan_update(static function (array &$data): void {});
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to read the private data store.');
    }
    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException('Unable to lock the private data store.');
        }
        $contents = stream_get_contents($handle);
        $data = $contents === false ? null : json_decode($contents, true);
        flock($handle, LOCK_UN);
        if (!is_array($data)) {
            throw new RuntimeException('The private data store contains invalid JSON.');
        }
        return daluyan_normalize_data($data);
    } finally {
        fclose($handle);
    }
}

function daluyan_find_user(array $data, string $email): ?array
{
    foreach ($data['users'] as $user) {
        if (isset($user['email']) && hash_equals(strtolower((string) $user['email']), strtolower($email))) {
            return $user;
        }
    }
    return null;
}

function daluyan_safe_user(array $user): array
{
    return [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role']
    ];
}

function daluyan_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('daluyan_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function daluyan_current_user(): ?array
{
    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser) || !isset($sessionUser['email'])) {
        return null;
    }
    $storedUser = daluyan_find_user(daluyan_read(), (string) $sessionUser['email']);
    if ($storedUser === null || empty($storedUser['active'])) {
        unset($_SESSION['user']);
        return null;
    }
    $_SESSION['user'] = daluyan_safe_user($storedUser);
    return $_SESSION['user'];
}

function daluyan_require_user(array $roles = ['adviser', 'executive', 'officer']): array
{
    $user = daluyan_current_user();
    if ($user === null || !in_array($user['role'] ?? '', $roles, true)) {
        daluyan_json(['error' => 'Authentication required.'], 401);
    }
    return $user;
}

function daluyan_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function daluyan_read_json_body(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 65536) {
        daluyan_json(['error' => 'Request is too large.'], 413);
    }
    $raw = file_get_contents('php://input');
    $body = $raw === false || $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($body)) {
        daluyan_json(['error' => 'Invalid JSON body.'], 400);
    }
    return $body;
}

function daluyan_verify_request(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://';
        $requestUrl = $scheme . ($_SERVER['HTTP_HOST'] ?? '');
        $requestHost = parse_url($requestUrl, PHP_URL_HOST);
        $requestPort = parse_url($requestUrl, PHP_URL_PORT);
        if (!is_string($originHost) || !is_string($requestHost) || strcasecmp($originHost, $requestHost) !== 0 || $originPort !== $requestPort) {
            daluyan_json(['error' => 'Cross-origin request rejected.'], 403);
        }
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
        daluyan_json(['error' => 'Invalid security token. Refresh and try again.'], 403);
    }
}

function daluyan_required_string(array $body, string $key, int $maxLength = 500): string
{
    $value = trim((string) ($body[$key] ?? ''));
    if ($value === '' || strlen($value) > $maxLength) {
        daluyan_json(['error' => 'A required field is missing or too long.'], 422);
    }
    return $value;
}

function daluyan_uuid(): string
{
    return bin2hex(random_bytes(16));
}

function daluyan_dashboard_stats(array $data, int $days = 7): array
{
    $since = new DateTimeImmutable('-' . max(1, min(365, $days)) . ' days', new DateTimeZone('UTC'));
    $byCommittee = [];
    $officers = [];
    $totalHours = 0.0;
    $attendanceCount = 0;
    foreach ($data['attendance'] as $entry) {
        $time = new DateTimeImmutable((string) ($entry['checkInAt'] ?? $entry['createdAt'] ?? 'now'));
        if ($time < $since) {
            continue;
        }
        $committee = (string) ($entry['committee'] ?? 'Unassigned');
        if (!isset($byCommittee[$committee])) {
            $byCommittee[$committee] = ['committee' => $committee, 'entries' => 0, 'hours' => 0.0, 'officers' => []];
        }
        $byCommittee[$committee]['entries']++;
        $attendanceCount++;
        $email = strtolower((string) ($entry['officerEmail'] ?? ''));
        if ($email !== '') {
            $officers[$email] = true;
            $byCommittee[$committee]['officers'][$email] = true;
        }
        $hours = max(0, (float) ($entry['hours'] ?? 0));
        $totalHours += $hours;
        $byCommittee[$committee]['hours'] += $hours;
    }
    foreach ($byCommittee as &$committee) {
        $committee['officers'] = count($committee['officers']);
        $committee['hours'] = round($committee['hours'], 2);
    }
    unset($committee);
    $pendingLeaves = count(array_filter($data['leaveRequests'], static fn(array $request): bool => ($request['status'] ?? '') === 'pending'));
    $activity = [];
    foreach ($data['attendance'] as $entry) {
        $activity[] = [
            'type' => 'Attendance',
            'subject' => (string) ($entry['officerName'] ?? $entry['officerEmail'] ?? 'Unknown officer'),
            'detail' => (string) ($entry['event'] ?? ''),
            'committee' => (string) ($entry['committee'] ?? ''),
            'status' => (string) ($entry['status'] ?? ''),
            'createdAt' => (string) ($entry['checkOutAt'] ?? $entry['checkInAt'] ?? '')
        ];
    }
    foreach ($data['dtnRequests'] as $request) {
        $activity[] = [
            'type' => 'DTN request',
            'subject' => (string) ($request['code'] ?? ''),
            'detail' => (string) ($request['subject'] ?? ''),
            'committee' => (string) ($request['committee'] ?? ''),
            'status' => (string) ($request['status'] ?? ''),
            'createdAt' => (string) ($request['createdAt'] ?? '')
        ];
    }
    foreach ($data['leaveRequests'] as $request) {
        $activity[] = [
            'type' => 'Leave request',
            'subject' => (string) ($request['officerEmail'] ?? ''),
            'detail' => (string) ($request['startDate'] ?? '') . ' to ' . (string) ($request['endDate'] ?? ''),
            'committee' => (string) ($request['committee'] ?? ''),
            'status' => (string) ($request['status'] ?? ''),
            'createdAt' => (string) ($request['createdAt'] ?? '')
        ];
    }
    usort($activity, static fn(array $first, array $second): int => strcmp($second['createdAt'], $first['createdAt']));

    return [
        'period' => ['from' => $since->format(DATE_ATOM), 'to' => gmdate(DATE_ATOM)],
        'attendanceEntries' => $attendanceCount,
        'activeOfficers' => count($officers),
        'dutyHours' => round($totalHours, 2),
        'pendingLeaves' => $pendingLeaves,
        'dtnRequests' => count($data['dtnRequests']),
        'byCommittee' => array_values($byCommittee),
        'recentActivity' => array_slice($activity, 0, 20)
    ];
}
