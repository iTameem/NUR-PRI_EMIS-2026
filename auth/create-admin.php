<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$adminCheck = mysqli_prepare($conn, "SELECT id FROM users WHERE role = 'admin' LIMIT 1");
mysqli_stmt_execute($adminCheck);
$adminExists = mysqli_fetch_assoc(mysqli_stmt_get_result($adminCheck)) !== null;
mysqli_stmt_close($adminCheck);

$setupKey = getenv('ADMIN_SETUP_KEY');
$setupKeyConfigured = is_string($setupKey) && $setupKey !== '';
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$setupAllowed = $isLocalRequest || $setupKeyConfigured;
$error = '';
$adminCreated = false;
$transactionStarted = false;
$lockAcquired = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$adminExists) {
    verify_csrf();

    $providedKey = $_POST['setup_key'] ?? '';
    $fullnameInput = $_POST['fullname'] ?? '';
    $phoneInput = $_POST['phone_No'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $providedKey = is_string($providedKey) ? $providedKey : '';
    $fullname = is_string($fullnameInput) ? trim($fullnameInput) : '';
    $phone = is_string($phoneInput) ? trim($phoneInput) : '';

    if (!$setupAllowed) {
        $error = 'Admin setup is only available from this server unless ADMIN_SETUP_KEY is configured.';
    } elseif ($setupKeyConfigured && !hash_equals($setupKey, $providedKey)) {
        $error = 'The setup key is invalid.';
    } elseif ($fullname === '' || strlen($fullname) > 150
        || $phone === '' || strlen($phone) > 30) {
        $error = 'Enter a name and a valid phone number.';
    } elseif (!is_string($password) || !password_meets_policy($password)) {
        $error = 'Password must be at least 8 characters and include uppercase and lowercase letters, a number, and a special character.';
    } elseif (!is_string($confirmPassword) || !hash_equals($password, $confirmPassword)) {
        $error = 'The password confirmation does not match.';
    } else {
        try {
            $lockName = 'gmmc_result_db_first_admin';
            $lockQuery = mysqli_prepare($conn, 'SELECT GET_LOCK(?, 10) AS lock_acquired');
            mysqli_stmt_bind_param($lockQuery, 's', $lockName);
            mysqli_stmt_execute($lockQuery);
            $lockResult = mysqli_fetch_assoc(mysqli_stmt_get_result($lockQuery));
            mysqli_stmt_close($lockQuery);

            if (!$lockResult || (int) $lockResult['lock_acquired'] !== 1) {
                $error = 'Admin setup is busy. Please try again in a moment.';
            } else {
                $lockAcquired = true;
                mysqli_begin_transaction($conn);
                $transactionStarted = true;

                $adminCheck = mysqli_prepare($conn, "SELECT id FROM users WHERE role = 'admin' LIMIT 1");
                mysqli_stmt_execute($adminCheck);
                $adminExists = mysqli_fetch_assoc(mysqli_stmt_get_result($adminCheck)) !== null;
                mysqli_stmt_close($adminCheck);

                if ($adminExists) {
                    mysqli_rollback($conn);
                    $transactionStarted = false;
                    $error = 'An administrator already exists. Admin setup is closed.';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $role = 'admin';
                    $insert = mysqli_prepare(
                        $conn,
                        'INSERT INTO users (fullname, phone_No, password_hash, role)
                         VALUES (?, ?, ?, ?)'
                    );
                    mysqli_stmt_bind_param($insert, 'ssss', $fullname, $phone, $passwordHash, $role);
                    mysqli_stmt_execute($insert);
                    mysqli_stmt_close($insert);
                    mysqli_commit($conn);
                    $transactionStarted = false;
                    $adminCreated = true;
                }
            }
        } catch (mysqli_sql_exception $exception) {
            if ($transactionStarted) {
                try {
                    mysqli_rollback($conn);
                    $transactionStarted = false;
                } catch (mysqli_sql_exception $rollbackException) {
                    error_log('Initial admin setup rollback failed: ' . $rollbackException->getMessage());
                }
            }

            if ($exception->getCode() === 1062) {
                $error = 'That phone number is already in use.';
            } else {
                error_log('Initial admin setup failed: ' . $exception->getMessage());
                $error = 'The administrator account could not be created. Please try again or check the server logs.';
            }
        } finally {
            if ($lockAcquired) {
                try {
                    $releaseQuery = mysqli_prepare($conn, 'SELECT RELEASE_LOCK(?)');
                    mysqli_stmt_bind_param($releaseQuery, 's', $lockName);
                    mysqli_stmt_execute($releaseQuery);
                    mysqli_stmt_close($releaseQuery);
                } catch (mysqli_sql_exception $releaseException) {
                    error_log('Initial admin setup lock release failed: ' . $releaseException->getMessage());
                }
            }
        }
    }

    if ($adminCreated) {
        set_flash('success', 'Administrator created. Sign in with your phone number and password.');
        header('Location: login.php');
        exit;
    }
}

$flash = take_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create first administrator | Result Management</title>
    <link href="../css/src/output.css" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">
    <main class="flex min-h-screen items-center justify-center px-4 py-10">
        <section class="w-full max-w-lg rounded-2xl bg-white p-8 shadow-lg">
            <div class="mx-auto mb-5 flex h-24 w-24 items-center justify-center overflow-hidden rounded-full border-4 border-indigo-100 bg-white p-1 shadow-sm">
                <img src="../assets/gmmc-logo1.png" alt="General Murtala Mohammed College Yola crest"
                     class="h-full w-full rounded-full object-contain">
            </div>
            <p class="text-center text-sm font-semibold uppercase tracking-widest text-indigo-600">Result Management</p>
            <h1 class="mt-3 text-center text-2xl font-bold">Create first administrator</h1>

            <?php if ($adminExists): ?>
                <p class="mt-5 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-800">
                    Admin setup is closed because an administrator account already exists.
                </p>
                <a href="login.php" class="mt-5 inline-block font-semibold text-indigo-600 hover:text-indigo-700">Go to sign in</a>
            <?php elseif (!$setupAllowed): ?>
                <p role="alert" class="mt-5 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                    Admin setup is available only from localhost. Configure <code>ADMIN_SETUP_KEY</code> on the server to enable secure setup from another address.
                </p>
                <a href="login.php" class="mt-5 inline-block font-semibold text-indigo-600 hover:text-indigo-700">Go to sign in</a>
            <?php else: ?>
                <p class="mt-2 text-sm text-slate-600">This one-time form is available only until the first administrator is created.</p>
                <?php if (!$setupKeyConfigured): ?>
                    <p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                        Local setup mode: open this page through <code>localhost</code> on the server.
                    </p>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div role="alert" class="mt-5 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"><?= e($error) ?></div>
                <?php endif; ?>

                <form id="admin-setup-form" method="post" action="create-admin.php" class="mt-6 space-y-4">
                    <?= csrf_field() ?>
                    <?php if ($setupKeyConfigured): ?>
                        <div>
                            <label for="setup_key" class="mb-1 block text-sm font-medium">One-time setup key</label>
                            <input id="setup_key" name="setup_key" type="password" autocomplete="off" required
                                   class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
                        </div>
                    <?php endif; ?>
                    <div>
                        <label for="fullname" class="mb-1 block text-sm font-medium">Full name</label>
                        <input id="fullname" name="fullname" maxlength="150" required
                               value="<?= e(is_string($_POST['fullname'] ?? null) ? trim($_POST['fullname']) : '') ?>"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
                    </div>
                    <div>
                        <label for="phone_No" class="mb-1 block text-sm font-medium">Phone number</label>
                        <input id="phone_No" name="phone_No" type="tel" maxlength="30" autocomplete="tel" required
                               value="<?= e(is_string($_POST['phone_No'] ?? null) ? 
                               trim($_POST['phone_No']) : '') ?>"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
                    </div>
                    <div>
                        <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                        <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required
                               class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
                        <p class="mt-2 text-xs text-slate-600">
                            At least 8 characters with uppercase and lowercase letters, a number, and a special character.
                        </p>
                    </div>
                    <div>
                        <label for="confirm_password" class="mb-1 block text-sm font-medium">Confirm password</label>
                        <input id="confirm_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required
                               class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
                    </div>
                    <button type="submit"
                            class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-semibold text-white hover:bg-indigo-700">
                        Create administrator
                    </button>
                </form>
            <?php endif; ?>
        </section>
    </main>
    <script src="../assets/sweetalert2.all.js"></script>
    <?php render_flash_script($flash); ?>
    <script>
        const setupForm = document.getElementById('admin-setup-form');
        if (setupForm) {
            setupForm.addEventListener('submit', function (event) {
                const password = document.getElementById('password').value;
                const confirmation = document.getElementById('confirm_password').value;
                const strongEnough = password.length >= 8
                    && /[a-z]/.test(password)
                    && /[A-Z]/.test(password)
                    && /[0-9]/.test(password)
                    && /[^A-Za-z0-9\s]/.test(password);

                if (!strongEnough || password !== confirmation) {
                    event.preventDefault();
                    Swal.fire({
                        icon: 'warning',
                        text: !strongEnough
                            ? 'Use at least 8 characters with uppercase and lowercase letters, a number, and a special character.'
                            : 'The password confirmation does not match.'
                    });
                }
            });
        }
    </script>
</body>
</html>
