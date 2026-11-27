<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$requestedRole = $_POST['session_role'] ?? '';
if (!is_string($requestedRole)
    || !in_array($requestedRole, ['admin', 'teacher'], true)
    || !isset($_SESSION['role'])
    || $_SESSION['role'] !== $requestedRole) {
    http_response_code(403);
    exit('The requested account session is not valid.');
}

verify_csrf();
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => 'Lax',
    ]);
}

session_destroy();
header('Location: login.php');
exit;
