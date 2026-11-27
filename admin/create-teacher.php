<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

verify_csrf();

$fullnameInput = $_POST['fullname'] ?? '';
$phoneInput = $_POST['phone_No'] ?? '';
$password = $_POST['password'] ?? '';
$fullname = is_string($fullnameInput) ? trim($fullnameInput) : '';
$phone = is_string($phoneInput) ? trim($phoneInput) : '';
$validPhone = preg_match('/\A\+?[0-9][0-9 ()-]{5,28}\z/', $phone) === 1;

if ($fullname === '' || strlen($fullname) > 150) {
    set_flash('error', 'Enter a teacher name of up to 150 characters.');
} elseif (!$validPhone) {
    set_flash('error', 'Enter a valid phone number using digits and optional +, spaces, parentheses, or hyphens.');
} elseif (!is_string($password) || !password_meets_policy($password)) {
    set_flash('error', 'Password must be at least 8 characters and include uppercase and lowercase letters, a number, and a special character.');
} else {
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $role = 'teacher';
    $statement = mysqli_prepare(
        $conn,
        'INSERT INTO users (fullname, phone_No, password_hash, role)
         VALUES (?, ?, ?, ?)'
    );
    mysqli_stmt_bind_param($statement, 'ssss', $fullname, $phone, $passwordHash, $role);

    try {
        mysqli_stmt_execute($statement);
        set_flash('success', 'Teacher account created successfully.');
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062) {
            set_flash('error', 'That phone number is already in use.');
        } else {
            error_log('Creating teacher account failed: ' . $exception->getMessage());
            set_flash('error', 'The teacher account could not be created. Please try again or check the server logs.');
        }
    }

    mysqli_stmt_close($statement);
}

header('Location: dashboard.php?page=teachers');
exit;
