<?php
declare(strict_types=1);

function daluyan_db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }
    $dsn = getenv('DALUYAN_DSN');
    $username = getenv('DALUYAN_DB_USER');
    $password = getenv('DALUYAN_DB_PASSWORD');
    if (!is_string($dsn) || $dsn === '' || !is_string($username) || $username === '') {
        throw new RuntimeException('Set DALUYAN_DSN and DALUYAN_DB_USER before starting the application.');
    }
    $connection = new PDO($dsn, $username, is_string($password) ? $password : '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false
    ]);
    return $connection;
}

function daluyan_find_user_by_email(string $email): ?array
{
    $statement = daluyan_db()->prepare(
        'SELECT u.id, u.seed_key, u.name, u.email, u.role_key AS role, u.position, u.password_hash AS passwordHash, u.active, r.dashboard_access AS dashboardAccess
         FROM users u JOIN roles r ON r.role_key = u.role_key WHERE u.email = :email LIMIT 1'
    );
    $statement->execute(['email' => strtolower($email)]);
    $user = $statement->fetch();
    if (!is_array($user)) {
        return null;
    }
    $assignments = daluyan_db()->prepare('SELECT committee_key FROM user_committees WHERE user_id = :user_id ORDER BY is_primary DESC, committee_key');
    $assignments->execute(['user_id' => $user['id']]);
    $user['assignments'] = array_column($assignments->fetchAll(), 'committee_key');
    $user['active'] = (bool) $user['active'];
    $user['dashboardAccess'] = (bool) $user['dashboardAccess'];
    return $user;
}

function daluyan_safe_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'position' => (string) ($user['position'] ?? ''),
        'assignments' => $user['assignments'] ?? []
    ];
}

function daluyan_get_directory(): array
{
    $statement = daluyan_db()->query(
        'SELECT u.seed_key AS id, u.name, u.position AS role, u.directory_category AS category,
                COALESCE(c.full_title, u.position) AS committee, u.email, u.tag_color AS tagColor, u.icon
         FROM users u
         LEFT JOIN user_committees uc ON uc.user_id = u.id AND uc.is_primary = 1
         LEFT JOIN committees c ON c.committee_key = uc.committee_key
         WHERE u.directory_visible = 1 ORDER BY u.id'
    );
    return $statement->fetchAll();
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
    $user = daluyan_find_user_by_email((string) $sessionUser['email']);
    if ($user === null || !$user['active']) {
        unset($_SESSION['user']);
        return null;
    }
    $_SESSION['user'] = daluyan_safe_user($user);
    return $_SESSION['user'];
}

function daluyan_require_user(array $roles = ['adviser', 'executive', 'officer']): array
{
    $user = daluyan_current_user();
    if ($user === null) {
        daluyan_json(['error' => 'Authentication required.'], 401);
    }
    if (!in_array($user['role'], $roles, true)) {
        daluyan_json(['error' => 'You do not have permission to perform this action.'], 403);
    }
    return $user;
}

function daluyan_user_can(array $user, string $permission): bool
{
    $statement = daluyan_db()->prepare('SELECT 1 FROM role_permissions WHERE role_key = :role_key AND permission_key = :permission_key LIMIT 1');
    $statement->execute(['role_key' => $user['role'], 'permission_key' => $permission]);
    return $statement->fetchColumn() !== false;
}

function daluyan_user_has_assignment(array $user, string $committee): bool
{
    return in_array($committee, $user['assignments'] ?? [], true);
}

function daluyan_require_permission(string $permission): array
{
    $user = daluyan_current_user();
    if ($user === null) {
        daluyan_json(['error' => 'Authentication required.'], 401);
    }
    if (!daluyan_user_can($user, $permission)) {
        daluyan_json(['error' => 'You do not have permission to perform this action.'], 403);
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

function daluyan_login_attempt_count(string $key): int
{
    $statement = daluyan_db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempt_key = :attempt_key AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE');
    $statement->execute(['attempt_key' => $key]);
    return (int) $statement->fetchColumn();
}

function daluyan_record_login_attempt(string $key): void
{
    $statement = daluyan_db()->prepare('INSERT INTO login_attempts (attempt_key, attempted_at) VALUES (:attempt_key, UTC_TIMESTAMP())');
    $statement->execute(['attempt_key' => $key]);
    daluyan_db()->exec('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
}

function daluyan_clear_login_attempts(string $key): void
{
    $statement = daluyan_db()->prepare('DELETE FROM login_attempts WHERE attempt_key = :attempt_key');
    $statement->execute(['attempt_key' => $key]);
}

function daluyan_create_dtn_request(array $user, string $subject, string $committee, string $requesterEmail, string $description): array
{
    $connection = daluyan_db();
    $requester = daluyan_find_user_by_email($requesterEmail);
    if ($requester === null) {
        throw new DomainException('The requesting email is not in the council account list.');
    }
    $connection->beginTransaction();
    try {
        $year = (int) gmdate('Y');
        $statement = $connection->prepare('INSERT IGNORE INTO dtn_sequences (sequence_year, next_number) VALUES (:year, 1)');
        $statement->execute(['year' => $year]);
        $statement = $connection->prepare('SELECT next_number FROM dtn_sequences WHERE sequence_year = :year FOR UPDATE');
        $statement->execute(['year' => $year]);
        $number = (int) $statement->fetchColumn();
        $statement = $connection->prepare('UPDATE dtn_sequences SET next_number = :next_number WHERE sequence_year = :year');
        $statement->execute(['next_number' => $number + 1, 'year' => $year]);
        $code = sprintf('SSC-UIM-%d-%04d', $year, $number);
        $statement = $connection->prepare(
            'INSERT INTO dtn_requests (request_code, subject, committee_key, requester_id, requester_email, description, submitted_by)
             VALUES (:code, :subject, :committee, :requester_id, :requester_email, :description, :submitted_by)'
        );
        $statement->execute([
            'code' => $code,
            'subject' => $subject,
            'committee' => $committee,
            'requester_id' => $requester['id'],
            'requester_email' => $requesterEmail,
            'description' => $description,
            'submitted_by' => $user['id']
        ]);
        $requestId = (int) $connection->lastInsertId();
        $connection->commit();
        return ['id' => $requestId, 'code' => $code, 'subject' => $subject, 'committee' => $committee, 'status' => 'submitted', 'createdAt' => gmdate(DATE_ATOM)];
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function daluyan_record_attendance(array $user, array $officer, string $event, string $venue, string $status): array
{
    $connection = daluyan_db();
    if ($status === 'check_out') {
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare(
                "SELECT id, check_in_at FROM attendance_records WHERE officer_id = :officer_id AND status = 'checked_in' AND check_out_at IS NULL ORDER BY check_in_at DESC, id DESC LIMIT 1 FOR UPDATE"
            );
            $statement->execute(['officer_id' => $officer['id']]);
            $openEntry = $statement->fetch();
            if (!is_array($openEntry)) {
                $connection->rollBack();
                throw new DomainException('No open check-in was found for this officer.');
            }
            $checkOut = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $checkIn = new DateTimeImmutable((string) $openEntry['check_in_at'], new DateTimeZone('UTC'));
            $hours = round(max(0, ($checkOut->getTimestamp() - $checkIn->getTimestamp()) / 3600), 2);
            $statement = $connection->prepare("UPDATE attendance_records SET check_out_at = :check_out, status = 'complete', hours = :hours WHERE id = :id");
            $statement->execute(['check_out' => $checkOut->format('Y-m-d H:i:s'), 'hours' => $hours, 'id' => $openEntry['id']]);
            $connection->commit();
            return ['id' => (int) $openEntry['id'], 'officerName' => $officer['name'], 'officerEmail' => $officer['email'], 'status' => 'complete', 'hours' => $hours];
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }
    $storedStatus = $status === 'check_in' ? 'checked_in' : 'on_call';
    $statement = $connection->prepare(
        'INSERT INTO attendance_records (officer_id, event_name, venue, status, check_in_at, logged_by)
         VALUES (:officer_id, :event_name, :venue, :status, UTC_TIMESTAMP(), :logged_by)'
    );
    $statement->execute(['officer_id' => $officer['id'], 'event_name' => $event, 'venue' => $venue, 'status' => $storedStatus, 'logged_by' => $user['id']]);
    return ['id' => (int) $connection->lastInsertId(), 'officerName' => $officer['name'], 'officerEmail' => $officer['email'], 'status' => $storedStatus, 'hours' => 0.0];
}

function daluyan_create_leave_request(array $user, array $officer, string $committee, string $startDate, string $endDate, string $reason): array
{
    $statement = daluyan_db()->prepare(
        "INSERT INTO leave_requests (officer_id, committee_key, start_date, end_date, reason, submitted_by, status)
         VALUES (:officer_id, :committee, :start_date, :end_date, :reason, :submitted_by, 'pending')"
    );
    $statement->execute(['officer_id' => $officer['id'], 'committee' => $committee, 'start_date' => $startDate, 'end_date' => $endDate, 'reason' => $reason, 'submitted_by' => $user['id']]);
    return ['id' => (int) daluyan_db()->lastInsertId(), 'officerEmail' => $officer['email'], 'committee' => $committee, 'startDate' => $startDate, 'endDate' => $endDate, 'status' => 'pending'];
}

function daluyan_dashboard_stats(int $days = 7): array
{
    $days = max(1, min(365, $days));
    $connection = daluyan_db();
    $since = (new DateTimeImmutable('-' . $days . ' days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $statement = $connection->prepare('SELECT COUNT(*) AS entries, COUNT(DISTINCT officer_id) AS officers, COALESCE(SUM(hours), 0) AS hours FROM attendance_records WHERE check_in_at >= :since');
    $statement->execute(['since' => $since]);
    $summary = $statement->fetch() ?: ['entries' => 0, 'officers' => 0, 'hours' => 0];

    $statement = $connection->prepare(
        'SELECT c.committee_key AS committee, COUNT(a.id) AS entries, COUNT(DISTINCT a.officer_id) AS officers, COALESCE(SUM(a.hours), 0) AS hours
         FROM committees c
         LEFT JOIN user_committees uc ON uc.committee_key = c.committee_key AND uc.is_primary = 1
         LEFT JOIN attendance_records a ON a.officer_id = uc.user_id AND a.check_in_at >= :since
         WHERE c.committee_key IN (\'Executives\', \'CommAIRS\', \'CommCREL\', \'CommFIMA\', \'CommPROL\')
         GROUP BY c.committee_key ORDER BY c.committee_key'
    );
    $statement->execute(['since' => $since]);
    $byCommittee = array_map(static fn(array $row): array => [
        'committee' => (string) $row['committee'],
        'entries' => (int) $row['entries'],
        'officers' => (int) $row['officers'],
        'hours' => round((float) $row['hours'], 2)
    ], $statement->fetchAll());

    $pending = (int) $connection->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
    $activity = [];
    $queries = [
        "SELECT 'Attendance' AS type, COALESCE(u.name, u.email) AS subject, a.event_name AS detail, c.committee_key AS committee, a.status, COALESCE(a.check_out_at, a.check_in_at) AS created_at
         FROM attendance_records a JOIN users u ON u.id = a.officer_id LEFT JOIN user_committees uc ON uc.user_id = u.id AND uc.is_primary = 1 LEFT JOIN committees c ON c.committee_key = uc.committee_key",
        "SELECT 'DTN request' AS type, d.request_code AS subject, d.subject AS detail, d.committee_key AS committee, d.status, d.created_at FROM dtn_requests d",
        "SELECT 'Leave request' AS type, u.email AS subject, CONCAT(l.start_date, ' to ', l.end_date) AS detail, l.committee_key AS committee, l.status, l.created_at FROM leave_requests l JOIN users u ON u.id = l.officer_id"
    ];
    foreach ($queries as $query) {
        foreach ($connection->query($query)->fetchAll() as $row) {
            $activity[] = [
                'type' => (string) $row['type'], 'subject' => (string) $row['subject'],
                'detail' => (string) $row['detail'], 'committee' => (string) ($row['committee'] ?? ''),
                'status' => (string) $row['status'],
                'createdAt' => (new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')))->format(DATE_ATOM)
            ];
        }
    }
    usort($activity, static fn(array $first, array $second): int => strcmp($second['createdAt'], $first['createdAt']));

    return [
        'period' => ['from' => (new DateTimeImmutable($since, new DateTimeZone('UTC')))->format(DATE_ATOM), 'to' => gmdate(DATE_ATOM)],
        'attendanceEntries' => (int) $summary['entries'], 'activeOfficers' => (int) $summary['officers'],
        'dutyHours' => round((float) $summary['hours'], 2), 'pendingLeaves' => $pending,
        'dtnRequests' => (int) $connection->query('SELECT COUNT(*) FROM dtn_requests')->fetchColumn(),
        'byCommittee' => $byCommittee, 'recentActivity' => array_slice($activity, 0, 20)
    ];
}

function daluyan_set_user_password(string $email, string $password, bool $onlyPending): string
{
    $user = daluyan_find_user_by_email($email);
    if ($user === null) {
        return 'missing';
    }
    if ($onlyPending && !empty($user['passwordHash'])) {
        return 'already_active';
    }
    $statement = daluyan_db()->prepare('UPDATE users SET password_hash = :password_hash, active = 1 WHERE id = :id');
    $statement->execute(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
    return $onlyPending ? 'activated' : 'updated';
}

function daluyan_disable_user(string $email): bool
{
    $statement = daluyan_db()->prepare('UPDATE users SET active = 0 WHERE email = :email');
    $statement->execute(['email' => strtolower($email)]);
    return $statement->rowCount() > 0;
}

function daluyan_add_user(string $email, string $name, string $role, string $password, ?string $committee): bool
{
    $connection = daluyan_db();
    $connection->beginTransaction();
    try {
    $statement = daluyan_db()->prepare('INSERT INTO users (name, email, role_key, position, directory_category, directory_visible, password_hash, active) VALUES (:name, :email, :role, :position, :category, 0, :password_hash, 1)');
    $statement->execute(['name' => $name, 'email' => strtolower($email), 'role' => $role, 'position' => ucfirst($role), 'category' => ucfirst($role), 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $connection->lastInsertId();
        if ($committee !== null) {
            $assignment = $connection->prepare('INSERT INTO user_committees (user_id, committee_key, is_primary) VALUES (:user_id, :committee, 1)');
            $assignment->execute(['user_id' => $userId, 'committee' => $committee]);
        }
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    return true;
}
