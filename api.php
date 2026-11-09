<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'database.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    daluyan_session_start();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string) ($_GET['action'] ?? 'session');

    if ($method === 'GET') {
        if ($action === 'session') {
            $user = daluyan_current_user();
            daluyan_json(['csrf' => $_SESSION['csrf'], 'user' => $user]);
        }
        if ($action === 'workspace') {
            $user = daluyan_current_user();
            if ($user === null) {
                daluyan_json(['error' => 'Authentication required.'], 401);
            }
            $days = filter_var($_GET['days'] ?? 30, FILTER_VALIDATE_INT);
            daluyan_json(['workspace' => daluyan_workspace_data($user, is_int($days) ? $days : 30)]);
        }
        if ($action === 'directory') {
            daluyan_json(['officers' => daluyan_get_directory()]);
        }
        if ($action === 'dashboard') {
            $user = daluyan_require_permission('reports.view_all');
            $days = filter_var($_GET['days'] ?? 7, FILTER_VALIDATE_INT);
            daluyan_json(['stats' => daluyan_dashboard_stats(is_int($days) ? $days : 7, $user)]);
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
        error_log('DALUYAN login attempt received.');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@g.batstate-u.edu.ph') || strlen($password) > 72) {
            error_log('DALUYAN login rejected: invalid university email format or password length.');
            daluyan_json(['error' => 'Email or password is incorrect.'], 401);
        }

        $key = hash('sha256', $email . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $user = daluyan_find_user_by_email($email);
        if ($user === null) {
            error_log('DALUYAN login rejected: account not found.');
        } elseif (empty($user['active'])) {
            error_log('DALUYAN login rejected: account inactive.');
        } elseif (!password_verify($password, (string) ($user['passwordHash'] ?? ''))) {
            error_log('DALUYAN login rejected: password verification failed.');
        }
        if ($user === null || empty($user['active']) || !password_verify($password, (string) ($user['passwordHash'] ?? ''))) {
            daluyan_record_login_attempt($key);
            daluyan_json(['error' => 'Email or password is incorrect.'], 401);
        }

        error_log('DALUYAN login succeeded.');
        daluyan_clear_login_attempts($key);
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
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
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

    if ($action === 'attendance-review') {
        $reviewer = daluyan_require_permission('attendance.approve');
        $requestId = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
        $decision = (string) ($body['decision'] ?? '');
        if (!is_int($requestId) || $requestId < 1 || !in_array($decision, ['approved', 'rejected'], true)) {
            daluyan_json(['error' => 'Attendance request or decision is invalid.'], 422);
        }
        try {
            $result = daluyan_review_attendance_entry($reviewer, $requestId, $decision);
        } catch (DomainException $error) {
            daluyan_json(['error' => $error->getMessage()], 422);
        }
        daluyan_json(['request' => $result]);
    }

    if ($action === 'dtn') {
        $user = daluyan_require_permission('dtn.submit_self');
        $subject = daluyan_required_string($body, 'subject', 200);
        $committee = daluyan_required_string($body, 'committee', 40);
        $requesterEmail = strtolower(daluyan_required_string($body, 'email', 254));
        $description = daluyan_required_string($body, 'description', 2000);
        if (!filter_var($requesterEmail, FILTER_VALIDATE_EMAIL) || !str_ends_with($requesterEmail, '@g.batstate-u.edu.ph') || !in_array($committee, ['CommAIRS', 'CommCREL', 'CommFIMA', 'CommPROL'], true)) {
            daluyan_json(['error' => 'Enter a valid university requesting email.'], 422);
        }
        $requester = daluyan_find_user_by_email($requesterEmail);
        if ($requester === null || empty($requester['active'])) {
            daluyan_json(['error' => 'The requesting account is not active.'], 422);
        }
        if (strcasecmp($requesterEmail, $user['email']) !== 0 && !daluyan_user_can($user, 'dtn.submit_any')) {
            daluyan_json(['error' => 'Officers may submit requests only for their own account.'], 403);
        }
        if ($user['role'] !== 'adviser'
            && (!daluyan_user_has_assignment($user, $committee) || !daluyan_user_has_assignment($requester, $committee))) {
            daluyan_json(['error' => 'You may submit requests only for your assigned committee.'], 403);
        }
        try {
            $result = daluyan_create_dtn_request($user, $subject, $committee, $requesterEmail, $description);
        } catch (DomainException $error) {
            daluyan_json(['error' => $error->getMessage()], 422);
        }
        daluyan_json(['request' => $result], 201);
    }

    if ($action === 'duty') {
        $user = daluyan_require_permission('attendance.submit_self');
        $officerEmail = strtolower(daluyan_required_string($body, 'officerEmail', 254));
        $event = daluyan_required_string($body, 'event', 200);
        $venue = daluyan_required_string($body, 'venue', 200);
        $status = daluyan_required_string($body, 'status', 20);
        if (!filter_var($officerEmail, FILTER_VALIDATE_EMAIL) || !in_array($status, ['check_in', 'check_out', 'on_call'], true)) {
            daluyan_json(['error' => 'Officer or duty status is invalid.'], 422);
        }
        $officer = daluyan_find_user_by_email($officerEmail);
        if ($officer === null || empty($officer['active'])) {
            daluyan_json(['error' => 'Officer was not found in the council directory.'], 422);
        }
        if (strcasecmp($officerEmail, $user['email']) !== 0) {
            $manageableCommittees = array_intersect($user['assignments'] ?? [], $officer['assignments'] ?? []);
            $canManage = $user['role'] === 'adviser'
                || (($user['role'] === 'executive' || daluyan_user_has_assignment($user, 'CommAIRS'))
                    && $manageableCommittees !== []);
            if (!daluyan_user_can($user, 'attendance.submit_any') && !$canManage) {
                daluyan_json(['error' => 'You may log attendance only for your own account or an assigned CommAIRS member.'], 403);
            }
            if ($user['role'] === 'executive' && $manageableCommittees === []) {
                daluyan_json(['error' => 'You may log attendance only for your assigned committee.'], 403);
            }
        }
        try {
            $result = daluyan_submit_attendance_entry($user, $officer, $event, $venue, $status);
        } catch (DomainException $error) {
            daluyan_json(['error' => $error->getMessage()], 422);
        }
        daluyan_json(['request' => $result], 201);
    }

    if ($action === 'leave') {
        $user = daluyan_require_permission('leave.submit_self');
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
        $officer = daluyan_find_user_by_email($officerEmail);
        if ($officer === null || empty($officer['active'])) {
            daluyan_json(['error' => 'Officer was not found in the council account list.'], 422);
        }
        $committeeKey = $committee === 'Higher Executives' ? 'Executives' : $committee;
        if (!daluyan_user_has_assignment($officer, $committeeKey)) {
            daluyan_json(['error' => 'The selected officer is not assigned to that committee.'], 422);
        }
        if (strcasecmp($officerEmail, $user['email']) !== 0) {
            $manageableCommittees = array_intersect($user['assignments'] ?? [], $officer['assignments'] ?? []);
            $canManage = $user['role'] === 'adviser'
                || (daluyan_user_has_assignment($user, 'CommAIRS') && in_array('CommAIRS', $manageableCommittees, true))
                || ($user['role'] === 'executive' && $manageableCommittees !== []);
            if (!daluyan_user_can($user, 'leave.submit_any') && !$canManage) {
                daluyan_json(['error' => 'You may file leave only for your own account or an assigned CommAIRS member.'], 403);
            }
            if ($user['role'] === 'executive' && $manageableCommittees === []) {
                daluyan_json(['error' => 'You may file leave only for your assigned committee.'], 403);
            }
        } elseif (!daluyan_user_can($user, 'leave.submit_any') && !daluyan_user_has_assignment($user, $committeeKey)) {
            daluyan_json(['error' => 'You may file leave only for your assigned committee.'], 403);
        }
        $request = daluyan_create_leave_request($user, $officer, $committeeKey, $startDate, $endDate, $reason);
        daluyan_json(['request' => $request], 201);
    }

    daluyan_json(['error' => 'Unknown action.'], 404);
} catch (Throwable $error) {
    error_log('DALUYAN API error: ' . $error->getMessage());
    daluyan_json(['error' => 'The server could not complete the request.'], 500);
}
