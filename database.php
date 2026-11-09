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

function daluyan_workspace_scope(array $user, string $resource): array
{
    if (($user['role'] ?? '') === 'adviser') {
        return ['type' => 'all', 'committees' => []];
    }

    $assignments = array_values(array_unique(array_filter($user['assignments'] ?? [], 'is_string')));
    $isCommAirsMember = in_array('CommAIRS', $assignments, true);
    if ($resource === 'dtn' && $isCommAirsMember) {
        return ['type' => 'all', 'committees' => []];
    }

    $isCommitteeLead = preg_match('/\b(?:co[- ]?head|head)\b/i', (string) ($user['position'] ?? '')) === 1;
    $hasCommitteeScope = ($user['role'] ?? '') === 'executive'
        || $isCommitteeLead
        || ($isCommAirsMember && in_array($resource, ['attendance', 'leave'], true));
    if ($hasCommitteeScope && $assignments !== []) {
        return ['type' => 'committee', 'committees' => $assignments];
    }

    return ['type' => 'self', 'committees' => []];
}

function daluyan_scope_predicate(array $user, string $resource, string $recordUserId): array
{
    $scope = daluyan_workspace_scope($user, $resource);
    if ($scope['type'] === 'all') {
        return ['sql' => '1 = 1', 'params' => []];
    }
    if ($scope['type'] === 'committee') {
        $placeholders = [];
        $params = [];
        foreach ($scope['committees'] as $index => $committee) {
            $key = 'scope_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $committee;
        }
        return [
            'sql' => 'EXISTS (SELECT 1 FROM user_committees scope_uc WHERE scope_uc.user_id = ' . $recordUserId
                . ' AND scope_uc.committee_key IN (' . implode(', ', $placeholders) . '))',
            'params' => $params
        ];
    }
    return ['sql' => $recordUserId . ' = :scope_user_id', 'params' => ['scope_user_id' => (int) $user['id']]];
}

function daluyan_workspace_data(array $user, int $days = 30): array
{
    $days = max(1, min(365, $days));
    $since = (new DateTimeImmutable('-' . $days . ' days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $connection = daluyan_db();

    $attendanceScope = daluyan_scope_predicate($user, 'attendance', 'a.officer_id');
    $statement = $connection->prepare(
        'SELECT COUNT(*) AS entries, COALESCE(SUM(a.hours), 0) AS hours
         FROM attendance_records a
         WHERE a.check_in_at >= :since AND ' . $attendanceScope['sql']
    );
    $statement->execute(['since' => $since] + $attendanceScope['params']);
    $summary = $statement->fetch() ?: ['entries' => 0, 'hours' => 0];

    $statement = $connection->prepare(
        'SELECT a.id, u.name, u.position, a.event_name AS event, a.venue, a.status, a.check_in_at AS checkIn,
                a.check_out_at AS checkOut, a.hours
         FROM attendance_records a JOIN users u ON u.id = a.officer_id
         WHERE a.check_in_at >= :since AND ' . $attendanceScope['sql'] . '
         ORDER BY a.check_in_at DESC, a.id DESC LIMIT 100'
    );
    $statement->execute(['since' => $since] + $attendanceScope['params']);
    $attendance = $statement->fetchAll();

    $dtnScope = daluyan_scope_predicate($user, 'dtn', 'd.requester_id');
    $statement = $connection->prepare(
        'SELECT d.id, d.request_code AS code, d.subject, d.committee_key AS committee,
                d.requester_email AS requesterEmail, d.status, d.created_at AS createdAt
         FROM dtn_requests d
         WHERE ' . $dtnScope['sql'] . '
         ORDER BY d.created_at DESC, d.id DESC'
    );
    $statement->execute($dtnScope['params']);
    $dtnRequests = $statement->fetchAll();

    $canViewLeave = ($user['role'] ?? '') === 'adviser' || daluyan_user_has_assignment($user, 'CommAIRS');
    $leaveRequests = [];
    if ($canViewLeave) {
        $leaveScope = daluyan_scope_predicate($user, 'leave', 'l.officer_id');
        $statement = $connection->prepare(
            'SELECT l.id, u.name, u.email, l.committee_key AS committee, l.start_date AS startDate,
                    l.end_date AS endDate, l.reason, l.status, l.created_at AS createdAt
             FROM leave_requests l JOIN users u ON u.id = l.officer_id
             WHERE ' . $leaveScope['sql'] . '
             ORDER BY l.created_at DESC, l.id DESC LIMIT 100'
        );
        $statement->execute($leaveScope['params']);
        $leaveRequests = $statement->fetchAll();
    }

    $members = [];
    if (daluyan_user_has_assignment($user, 'CommAIRS')) {
        $statement = $connection->prepare(
            'SELECT u.name, u.email, u.position
             FROM users u JOIN user_committees uc ON uc.user_id = u.id
             WHERE uc.committee_key = :committee AND u.active = 1
             GROUP BY u.id, u.name, u.email, u.position
             ORDER BY u.name'
        );
        $statement->execute(['committee' => 'CommAIRS']);
        $members = $statement->fetchAll();
    }

    $attendanceScopeInfo = daluyan_workspace_scope($user, 'attendance');
    return [
        'scope' => [
            'attendance' => $attendanceScopeInfo['type'],
            'dtn' => daluyan_workspace_scope($user, 'dtn')['type'],
            'leave' => daluyan_workspace_scope($user, 'leave')['type'],
            'committees' => $attendanceScopeInfo['committees'],
            'canManageCommAirs' => daluyan_user_has_assignment($user, 'CommAIRS')
        ],
        'periodDays' => $days,
        'attendanceSummary' => [
            'entries' => (int) $summary['entries'],
            'hours' => round((float) $summary['hours'], 2)
        ],
        'attendance' => $attendance,
        'dtnRequests' => $dtnRequests,
        'leaveRequests' => $leaveRequests,
        'commAirsMembers' => $members
    ];
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

function daluyan_submit_attendance_entry(array $user, array $officer, string $event, string $venue, string $status): array
{
    $statement = daluyan_db()->prepare(
        "INSERT INTO attendance_entry_requests (officer_id, event_name, venue, duty_action, submitted_by, created_at)
         VALUES (:officer_id, :event_name, :venue, :duty_action, :submitted_by, UTC_TIMESTAMP())"
    );
    $statement->execute([
        'officer_id' => $officer['id'],
        'event_name' => $event,
        'venue' => $venue,
        'duty_action' => $status,
        'submitted_by' => $user['id']
    ]);
    return ['id' => (int) daluyan_db()->lastInsertId(), 'officerName' => $officer['name'], 'officerEmail' => $officer['email'], 'status' => 'pending'];
}

function daluyan_review_attendance_entry(array $reviewer, int $requestId, string $decision): array
{
    $connection = daluyan_db();
    $scope = daluyan_scope_predicate($reviewer, 'attendance', 'r.officer_id');
    $connection->beginTransaction();
    try {
        $statement = $connection->prepare(
            'SELECT r.id, r.officer_id, r.event_name, r.venue, r.duty_action, r.status, r.created_at
             FROM attendance_entry_requests r
             WHERE r.id = :id AND ' . $scope['sql'] . ' FOR UPDATE'
        );
        $statement->execute(['id' => $requestId] + $scope['params']);
        $request = $statement->fetch();
        if (!is_array($request)) {
            throw new DomainException('Attendance entry was not found in your review scope.');
        }
        if ($request['status'] !== 'pending') {
            throw new DomainException('This attendance entry has already been reviewed.');
        }

        if ($decision === 'approved') {
            $officerStatement = $connection->prepare('SELECT id, name, email FROM users WHERE id = :id AND active = 1');
            $officerStatement->execute(['id' => $request['officer_id']]);
            $officer = $officerStatement->fetch();
            if (!is_array($officer)) {
                throw new DomainException('The officer account is no longer active.');
            }

            if ($request['duty_action'] === 'check_out') {
                $statement = $connection->prepare(
                    "SELECT id, check_in_at FROM attendance_records
                     WHERE officer_id = :officer_id AND status = 'checked_in' AND check_out_at IS NULL
                     ORDER BY check_in_at DESC, id DESC LIMIT 1 FOR UPDATE"
                );
                $statement->execute(['officer_id' => $request['officer_id']]);
                $openEntry = $statement->fetch();
                if (!is_array($openEntry)) {
                    throw new DomainException('No open check-in was found for this officer when the request was approved.');
                }
                $checkOut = new DateTimeImmutable((string) $request['created_at'], new DateTimeZone('UTC'));
                $checkIn = new DateTimeImmutable((string) $openEntry['check_in_at'], new DateTimeZone('UTC'));
                if ($checkOut < $checkIn) {
                    throw new DomainException('The check-out request predates the officer\'s open check-in.');
                }
                $hours = round(($checkOut->getTimestamp() - $checkIn->getTimestamp()) / 3600, 2);
                $statement = $connection->prepare(
                    "UPDATE attendance_records SET check_out_at = :check_out, status = 'complete', hours = :hours WHERE id = :id"
                );
                $statement->execute(['check_out' => $checkOut->format('Y-m-d H:i:s'), 'hours' => $hours, 'id' => $openEntry['id']]);
            } else {
                $storedStatus = $request['duty_action'] === 'check_in' ? 'checked_in' : 'on_call';
                $statement = $connection->prepare(
                    'INSERT INTO attendance_records (officer_id, event_name, venue, status, check_in_at, logged_by)
                     VALUES (:officer_id, :event_name, :venue, :status, :check_in_at, :logged_by)'
                );
                $statement->execute([
                    'officer_id' => $request['officer_id'],
                    'event_name' => $request['event_name'],
                    'venue' => $request['venue'],
                    'status' => $storedStatus,
                    'check_in_at' => $request['created_at'],
                    'logged_by' => $reviewer['id']
                ]);
            }
        }

        $statement = $connection->prepare(
            'UPDATE attendance_entry_requests
             SET status = :status, reviewed_by = :reviewed_by, reviewed_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = \'pending\''
        );
        $statement->execute(['status' => $decision, 'reviewed_by' => $reviewer['id'], 'id' => $requestId]);
        $connection->commit();
        return ['id' => $requestId, 'status' => $decision];
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
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

function daluyan_dashboard_stats(int $days, array $user): array
{
    $days = max(1, min(365, $days));
    $connection = daluyan_db();
    $since = (new DateTimeImmutable('-' . $days . ' days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $attendanceScope = daluyan_scope_predicate($user, 'attendance', 'a.officer_id');
    $statement = $connection->prepare(
        'SELECT COUNT(*) AS entries, COUNT(DISTINCT a.officer_id) AS officers, COALESCE(SUM(a.hours), 0) AS hours
         FROM attendance_records a
         WHERE a.check_in_at >= :since AND ' . $attendanceScope['sql']
    );
    $statement->execute(['since' => $since] + $attendanceScope['params']);
    $summary = $statement->fetch() ?: ['entries' => 0, 'officers' => 0, 'hours' => 0];

    $committees = daluyan_workspace_scope($user, 'attendance')['committees'];
    $committeeFilter = '';
    $committeeParams = [];
    if (daluyan_workspace_scope($user, 'attendance')['type'] !== 'all') {
        $committeePlaceholders = [];
        foreach ($committees as $index => $committee) {
            $key = 'committee_' . $index;
            $committeePlaceholders[] = ':' . $key;
            $committeeParams[$key] = $committee;
        }
        $committeeFilter = $committeePlaceholders === []
            ? ' AND 1 = 0'
            : ' AND c.committee_key IN (' . implode(', ', $committeePlaceholders) . ')';
    }
    $statement = $connection->prepare(
        'SELECT c.committee_key AS committee, COUNT(a.id) AS entries, COUNT(DISTINCT a.officer_id) AS officers, COALESCE(SUM(a.hours), 0) AS hours
         FROM committees c
         LEFT JOIN user_committees uc ON uc.committee_key = c.committee_key
         LEFT JOIN attendance_records a ON a.officer_id = uc.user_id AND a.check_in_at >= :since
         WHERE c.committee_key IN (\'Executives\', \'CommAIRS\', \'CommCREL\', \'CommFIMA\', \'CommPROL\')
         ' . $committeeFilter . '
         GROUP BY c.committee_key ORDER BY c.committee_key'
    );
    $statement->execute(['since' => $since] + $committeeParams);
    $byCommittee = array_map(static fn(array $row): array => [
        'committee' => (string) $row['committee'],
        'entries' => (int) $row['entries'],
        'officers' => (int) $row['officers'],
        'hours' => round((float) $row['hours'], 2)
    ], $statement->fetchAll());

    $leaveScope = daluyan_scope_predicate($user, 'leave', 'l.officer_id');
    $statement = $connection->prepare("SELECT COUNT(*) FROM leave_requests l WHERE l.status = 'pending' AND " . $leaveScope['sql']);
    $statement->execute($leaveScope['params']);
    $pending = (int) $statement->fetchColumn();
    $dtnScope = daluyan_scope_predicate($user, 'dtn', 'd.requester_id');
    $statement = $connection->prepare(
        'SELECT d.id, d.request_code AS code, d.subject, d.committee_key AS committee,
                d.requester_email AS requesterEmail, d.description, d.status, d.created_at AS createdAt
         FROM dtn_requests d
         WHERE ' . $dtnScope['sql'] . '
         ORDER BY d.created_at DESC, d.id DESC LIMIT 100'
    );
    $statement->execute($dtnScope['params']);
    $dtnRequests = $statement->fetchAll();

    $attendanceApprovalScope = daluyan_scope_predicate($user, 'attendance', 'r.officer_id');
    $statement = $connection->prepare(
        "SELECT r.id, u.name, u.email, r.event_name AS event, r.venue, r.duty_action AS dutyAction,
                r.created_at AS createdAt, COALESCE(c.full_title, uc.committee_key, '') AS committee
         FROM attendance_entry_requests r
         JOIN users u ON u.id = r.officer_id
         LEFT JOIN user_committees uc ON uc.user_id = u.id AND uc.is_primary = 1
         LEFT JOIN committees c ON c.committee_key = uc.committee_key
         WHERE r.status = 'pending' AND " . $attendanceApprovalScope['sql'] . "
         ORDER BY r.created_at ASC, r.id ASC LIMIT 100"
    );
    $statement->execute($attendanceApprovalScope['params']);
    $attendanceApprovals = $statement->fetchAll();
    foreach ($attendanceApprovals as &$request) {
        $request['id'] = (int) $request['id'];
    }
    unset($request);

    $activity = [];
    $activityQueries = [
        [
            "SELECT 'Attendance' AS type, COALESCE(u.name, u.email) AS subject, a.event_name AS detail, c.committee_key AS committee, a.status, COALESCE(a.check_out_at, a.check_in_at) AS created_at
             FROM attendance_records a JOIN users u ON u.id = a.officer_id LEFT JOIN user_committees uc ON uc.user_id = u.id AND uc.is_primary = 1 LEFT JOIN committees c ON c.committee_key = uc.committee_key
             WHERE " . $attendanceScope['sql'] . ' ORDER BY created_at DESC LIMIT 100',
            $attendanceScope['params']
        ],
        [
            "SELECT 'DTN request' AS type, d.request_code AS subject, d.subject AS detail, d.committee_key AS committee, d.status, d.created_at FROM dtn_requests d
             WHERE " . daluyan_scope_predicate($user, 'dtn', 'd.requester_id')['sql'] . ' ORDER BY d.created_at DESC LIMIT 100',
            daluyan_scope_predicate($user, 'dtn', 'd.requester_id')['params']
        ],
        [
            "SELECT 'Leave request' AS type, u.email AS subject, CONCAT(l.start_date, ' to ', l.end_date) AS detail, l.committee_key AS committee, l.status, l.created_at FROM leave_requests l JOIN users u ON u.id = l.officer_id
             WHERE " . $leaveScope['sql'] . ' ORDER BY l.created_at DESC LIMIT 100',
            $leaveScope['params']
        ]
    ];
    foreach ($activityQueries as [$query, $params]) {
        $statement = $connection->prepare($query);
        $statement->execute($params);
        foreach ($statement->fetchAll() as $row) {
            $activity[] = [
                'type' => (string) $row['type'], 'subject' => (string) $row['subject'],
                'detail' => (string) $row['detail'], 'committee' => (string) ($row['committee'] ?? ''),
                'status' => (string) $row['status'],
                'createdAt' => (new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')))->format(DATE_ATOM)
            ];
        }
    }
    usort($activity, static fn(array $first, array $second): int => strcmp($second['createdAt'], $first['createdAt']));

    $statement = $connection->prepare('SELECT COUNT(*) FROM dtn_requests d WHERE ' . $dtnScope['sql']);
    $statement->execute($dtnScope['params']);
    return [
        'period' => ['from' => (new DateTimeImmutable($since, new DateTimeZone('UTC')))->format(DATE_ATOM), 'to' => gmdate(DATE_ATOM)],
        'attendanceEntries' => (int) $summary['entries'], 'activeOfficers' => (int) $summary['officers'],
        'dutyHours' => round((float) $summary['hours'], 2), 'pendingLeaves' => $pending,
        'dtnRequests' => (int) $statement->fetchColumn(),
        'dtnRequestList' => $dtnRequests, 'attendanceApprovals' => $attendanceApprovals,
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
