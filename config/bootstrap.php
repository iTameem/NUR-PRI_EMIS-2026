<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

$scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$logoutRole = $_POST['session_role'] ?? '';
if (preg_match('~(?:^|/)admin(?:/|$)~', $scriptPath) === 1) {
    $sessionCookieName = 'GMMC_ADMINSESSID';
} elseif (preg_match('~(?:^|/)teacher(?:/|$)~', $scriptPath) === 1) {
    $sessionCookieName = 'GMMC_TEACHERSESSID';
} elseif (preg_match('~/auth/logout\.php$~', $scriptPath) === 1
    && is_string($logoutRole) && $logoutRole === 'admin') {
    $sessionCookieName = 'GMMC_ADMINSESSID';
} elseif (preg_match('~/auth/logout\.php$~', $scriptPath) === 1
    && is_string($logoutRole) && $logoutRole === 'teacher') {
    $sessionCookieName = 'GMMC_TEACHERSESSID';
} else {
    $sessionCookieName = 'GMMC_AUTHSESSID';
}

session_name($sessionCookieName);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/database.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function record_teacher_activity(
    mysqli $connection,
    int $teacherId,
    string $teacherName,
    string $eventType,
    ?int $classId = null,
    ?int $subjectId = null,
    ?int $termId = null,
    ?string $className = null,
    ?string $subjectName = null,
    ?string $termName = null,
    ?string $sessionName = null,
    ?int $studentEntryCount = null
): void {
    if (!in_array($eventType, ['login', 'score_upload'], true)) {
        throw new InvalidArgumentException('Invalid teacher activity type.');
    }

    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $ipAddress = is_string($remoteAddress)
        && filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false
        ? $remoteAddress
        : null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $userAgent = is_string($userAgent) ? substr($userAgent, 0, 512) : '';

    $statement = mysqli_prepare(
        $connection,
        'INSERT INTO teacher_activity_logs
            (teacher_id, teacher_name, event_type, ip_address, user_agent,
             class_id, subject_id, term_id, class_name, subject_name,
             term_name, session_name, student_entry_count)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    mysqli_stmt_bind_param(
        $statement,
        'issssiiissssi',
        $teacherId,
        $teacherName,
        $eventType,
        $ipAddress,
        $userAgent,
        $classId,
        $subjectId,
        $termId,
        $className,
        $subjectName,
        $termName,
        $sessionName,
        $studentEntryCount
    );
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

function password_meets_policy(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1
        && preg_match('/[^A-Za-z0-9\s]/', $password) === 1;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals(csrf_token(), $submittedToken)) {
        http_response_code(403);
        exit('Your session expired or the request was invalid. Please go back and try again.');
    }
}

function start_role_session(string $role, int $userId, string $fullname): void
{
    if (!in_array($role, ['admin', 'teacher'], true)) {
        throw new InvalidArgumentException('Cannot start a session for this user role.');
    }

    $authCookieName = session_name();
    $cookie = session_get_cookie_params();
    $_SESSION = [];

    if (!session_write_close()) {
        http_response_code(500);
        exit('Unable to close the sign-in session.');
    }

    if (ini_get('session.use_cookies')) {
        setcookie($authCookieName, '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }

    session_id('');
    session_name($role === 'admin' ? 'GMMC_ADMINSESSID' : 'GMMC_TEACHERSESSID');
    if (!session_start()) {
        http_response_code(500);
        exit('Unable to start the account session.');
    }

    session_regenerate_id(true);
    $_SESSION = [
        'user_id' => $userId,
        'name' => $fullname,
        'role' => $role,
    ];
}

function require_role(string $role): void
{
    if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
        header('Location: ../auth/login.php');
        exit;
    }

    $userId = (int) $_SESSION['user_id'];
    $statement = mysqli_prepare(
        $GLOBALS['conn'],
        'SELECT role FROM users WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($statement);

    if (!$user || $user['role'] !== $_SESSION['role']) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: ../auth/login.php');
        exit;
    }

    if ($user['role'] !== $role) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function render_flash_script(?array $flash): void
{
    if ($flash === null) {
        return;
    }

    $type = in_array($flash['type'] ?? '', ['success', 'error', 'info'], true)
        ? $flash['type']
        : 'info';
    $message = (string) ($flash['message'] ?? '');
    $payload = json_encode(
        ['icon' => $type, 'text' => $message],
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    echo '<script>Swal.fire(' . $payload . ');</script>';
}
