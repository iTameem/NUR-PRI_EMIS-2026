<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/promotion.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

verify_csrf();

$sourceSessionId = filter_var($_POST['source_session_id'] ?? null, FILTER_VALIDATE_INT);
$targetSessionId = filter_var($_POST['target_session_id'] ?? null, FILTER_VALIDATE_INT);
$transactionStarted = false;
if (!is_int($sourceSessionId) || $sourceSessionId < 1
    || !is_int($targetSessionId) || $targetSessionId < 1) {
    set_flash('error', 'Select a valid source and destination session.');
} else {
    try {
        mysqli_begin_transaction($conn);
        $transactionStarted = true;

        $context = get_student_promotion_context($conn, $sourceSessionId, $targetSessionId);
        $pendingCandidates = array_values(array_filter(
            $context['candidates'],
            static fn (array $candidate): bool => $candidate['existing_class_id'] === null
        ));
        if (!$pendingCandidates) {
            throw new InvalidArgumentException('No unallocated students currently meet the promotion requirements.');
        }

        $adminId = (int) $_SESSION['user_id'];
        $allocationInsert = mysqli_prepare(
            $conn,
            'INSERT INTO student_class_allocations
                (student_id, class_id, academic_session_id, allocated_by)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($pendingCandidates as $candidate) {
            $studentId = (int) $candidate['student_id'];
            $targetClassId = (int) $candidate['target_class_id'];
            mysqli_stmt_bind_param(
                $allocationInsert,
                'iiii',
                $studentId,
                $targetClassId,
                $targetSessionId,
                $adminId
            );
            mysqli_stmt_execute($allocationInsert);
        }
        mysqli_stmt_close($allocationInsert);

        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash(
            'success',
            count($pendingCandidates) . ' students promoted to ' . $context['target_session']['session_name'] . '.'
        );
    } catch (InvalidArgumentException $exception) {
        if ($transactionStarted) {
            mysqli_rollback($conn);
            $transactionStarted = false;
        }
        set_flash('error', $exception->getMessage());
    } catch (mysqli_sql_exception $exception) {
        if ($transactionStarted) {
            try {
                mysqli_rollback($conn);
            } catch (mysqli_sql_exception $rollbackException) {
                error_log('Student promotion rollback failed: ' . $rollbackException->getMessage());
            }
        }
        error_log('Student promotion failed: ' . $exception->getMessage());
        set_flash('error', 'No students were promoted. Reload the page and try again.');
    }
}

$redirectParams = ['page' => 'promotion'];
if (is_int($targetSessionId) && $targetSessionId > 0) {
    $redirectParams['target_session_id'] = $targetSessionId;
}
header('Location: dashboard.php?' . http_build_query($redirectParams));
exit;
