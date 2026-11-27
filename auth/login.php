<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin'
        ? '../admin/dashboard.php'
        : '../teacher/dashboard.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $phoneInput = $_POST['phone_No'] ?? '';
    $phone = is_string($phoneInput) ? trim($phoneInput) : '';
    $password = $_POST['password'] ?? '';

    if ($phone === '' || strlen($phone) > 30 || !is_string($password) || $password === '') {
        $error = 'Enter your phone number and password.';
    } else {
        $statement = mysqli_prepare(
            $conn,
            'SELECT id, fullname, phone_No, password_hash, role
             FROM users
             WHERE phone_No = ? AND is_active = 1
             LIMIT 1'
        );
        mysqli_stmt_bind_param($statement, 's', $phone);
        mysqli_stmt_execute($statement);
        $result = mysqli_stmt_get_result($statement);
        $user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($statement);

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['role'] === 'teacher') {
                try {
                    record_teacher_activity(
                        $conn,
                        (int) $user['id'],
                        (string) $user['fullname'],
                        'login'
                    );
                } catch (mysqli_sql_exception $exception) {
                    error_log('Recording teacher login failed: ' . $exception->getMessage());
                    $error = 'Sign-in could not be completed. Please try again later.';
                }
            }

            if ($error === '') {
                start_role_session(
                    (string) $user['role'],
                    (int) $user['id'],
                    (string) $user['fullname']
                );

                header('Location: ' . ($user['role'] === 'admin'
                    ? '../admin/dashboard.php'
                    : '../teacher/dashboard.php'));
                exit;
            }
        }

        if ($error === '') {
            $error = 'The phone number or password is incorrect.';
        }
    }
}

$flash = take_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | GMMC-NUR-PRI EMIS</title>
    <link href="../css/src/output.css" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">
    <main class="flex min-h-screen items-center justify-center px-4 py-12">
        <section class="w-full max-w-md rounded-2xl bg-white p-8 shadow-lg">
            <div class="mx-auto mb-5 flex h-24 w-24 items-center justify-center overflow-hidden rounded-full border-4 border-green-600 bg-white p-1 shadow-sm">
                <img src="../assets/gmmc-logo1.png" alt="General Murtala Mohammed College Yola crest"
                     class="h-full w-full scale-105 rounded-full object-contain object-center">
            </div>
            <p class="text-center text-sm font-semibold uppercase tracking-widest text-green-600">GMMC-NUR-PRI EMIS</p>
            <h1 class="mt-3 text-center text-2xl font-bold">Sign in</h1>

            <?php if ($error !== ''): ?>
                <div role="alert" class="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" class="mt-6 space-y-5">
                <?= csrf_field() ?>
                <div>
                    <label for="phone_No" class="mb-1 block text-sm font-medium">Phone number</label>
                    <input id="phone_No" name="phone_No" type="tel" autocomplete="username" required
                           value="<?= e(is_string($_POST['phone_No'] ?? null) ?
                            trim($_POST['phone_No']) : '') ?>"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-500">
                </div>
                <div>
                    <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required
                           class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-500">
                </div>
                <button type="submit"
                        class="w-full rounded-lg bg-green-700 px-4 py-2.5 font-semibold text-white hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                    Sign in
                </button>
            </form>
            <p class="mt-5 text-center text-sm text-slate-600">
                <!-- First-time setup?
                <a href="create-admin.php" class="font-semibold text-emerald-700 hover:text-emerald-800">Create the first administrator</a> -->
            </p>
        </section>
    </main>
    <script src="../assets/sweetalert2.all.js"></script>
    <?php render_flash_script($flash); ?>
</body>
</html>
