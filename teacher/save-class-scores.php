<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_role('teacher');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

verify_csrf();

$classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
$subjectId = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT);
$termId = filter_var($_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
$scoresInput = $_POST['scores'] ?? null;
$teacherId = (int) $_SESSION['user_id'];
$transactionStarted = false;

try {
    if (!$classId || $classId < 1 || !$subjectId || $subjectId < 1
        || !$termId || $termId < 1 || !is_array($scoresInput)) {
        throw new InvalidArgumentException('Choose a class, subject, term, and enter scores for every student.');
    }

    mysqli_begin_transaction($conn);
    $transactionStarted = true;

    $rosterQuery = mysqli_prepare(
        $conn,
        'SELECT sc.student_id, sc.class_id, sc.academic_session_id,
                c.class_name, sub.subject_name, t.term_name, a.session_name
         FROM student_class_allocations sc
         INNER JOIN teacher_class_allocations tc
             ON tc.class_id = sc.class_id
             AND tc.academic_session_id = sc.academic_session_id
             AND tc.teacher_id = ?
             AND tc.subject_id = ?
             AND tc.term_id = ?
         INNER JOIN academic_sessions a
             ON a.id = sc.academic_session_id AND a.is_active = 1
         INNER JOIN academic_terms t
             ON t.academic_session_id = sc.academic_session_id
             AND t.id = tc.term_id AND t.is_active = 1
         INNER JOIN classes c ON c.id = sc.class_id
         INNER JOIN subjects sub ON sub.id = tc.subject_id
         WHERE sc.class_id = ?
         ORDER BY sc.student_id
         FOR UPDATE'
    );
    mysqli_stmt_bind_param(
        $rosterQuery,
        'iiii',
        $teacherId,
        $subjectId,
        $termId,
        $classId
    );
    mysqli_stmt_execute($rosterQuery);
    $roster = mysqli_fetch_all(mysqli_stmt_get_result($rosterQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($rosterQuery);

    if (!$roster) {
        throw new InvalidArgumentException('This class is not assigned to you for an active session and term.');
    }

    $approvalInsert = mysqli_prepare(
        $conn,
        'INSERT IGNORE INTO teacher_score_edit_approvals
            (teacher_id, class_id, academic_session_id, subject_id, term_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    $activeSessionIdForApproval = (int) $roster[0]['academic_session_id'];
    mysqli_stmt_bind_param(
        $approvalInsert,
        'iiiii',
        $teacherId,
        $classId,
        $activeSessionIdForApproval,
        $subjectId,
        $termId
    );
    mysqli_stmt_execute($approvalInsert);
    mysqli_stmt_close($approvalInsert);

    $approvalQuery = mysqli_prepare(
        $conn,
        'SELECT edit_approved
         FROM teacher_score_edit_approvals
         WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
             AND subject_id = ? AND term_id = ?
         FOR UPDATE'
    );
    mysqli_stmt_bind_param(
        $approvalQuery,
        'iiiii',
        $teacherId,
        $classId,
        $activeSessionIdForApproval,
        $subjectId,
        $termId
    );
    mysqli_stmt_execute($approvalQuery);
    $approval = mysqli_fetch_assoc(mysqli_stmt_get_result($approvalQuery));
    mysqli_stmt_close($approvalQuery);

    $existingResultsQuery = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS result_count
         FROM results
         WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
             AND subject_id = ? AND term_id = ?'
    );
    mysqli_stmt_bind_param(
        $existingResultsQuery,
        'iiiii',
        $teacherId,
        $classId,
        $activeSessionIdForApproval,
        $subjectId,
        $termId
    );
    mysqli_stmt_execute($existingResultsQuery);
    $existingResults = mysqli_fetch_assoc(mysqli_stmt_get_result($existingResultsQuery));
    mysqli_stmt_close($existingResultsQuery);
    $existingResultCount = (int) $existingResults['result_count'];
    $editApproved = (int) $approval['edit_approved'] === 1;
    if ($existingResultCount > 0 && !$editApproved) {
        throw new InvalidArgumentException(
            'Scores have already been submitted for this assignment. Ask an administrator to approve one edit.'
        );
    }

    $expectedStudentIds = [];
    foreach ($roster as $student) {
        $expectedStudentIds[(int) $student['student_id']] = true;
    }

    if (count($scoresInput) !== count($expectedStudentIds)) {
        throw new InvalidArgumentException('The submitted scores do not match the class roster. Reload the page and try again.');
    }
    foreach (array_keys($scoresInput) as $submittedStudentId) {
        $validatedStudentId = filter_var($submittedStudentId, FILTER_VALIDATE_INT);
        if (!$validatedStudentId || !isset($expectedStudentIds[$validatedStudentId])) {
            throw new InvalidArgumentException('A submitted student is not in your assigned class.');
        }
    }

    $insert = mysqli_prepare(
        $conn,
        'INSERT INTO results
            (student_id, class_id, academic_session_id, subject_id, term_id, teacher_id,
             first_test, second_test, exam, score)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            class_id = VALUES(class_id),
            academic_session_id = VALUES(academic_session_id),
            teacher_id = VALUES(teacher_id),
            first_test = VALUES(first_test),
            second_test = VALUES(second_test),
            exam = VALUES(exam),
            score = VALUES(score)'
    );

    foreach ($roster as $student) {
        $studentId = (int) $student['student_id'];
        $studentScores = $scoresInput[$studentId] ?? null;
        if (!is_array($studentScores)) {
            throw new InvalidArgumentException('Enter all three scores for every student.');
        }

        $scoreValues = [];
        $componentLimits = [
            'first_test' => ['label' => '1st CA', 'maximum' => 20],
            'second_test' => ['label' => '2nd CA', 'maximum' => 20],
            'exam' => ['label' => 'Exam', 'maximum' => 60],
        ];
        foreach ($componentLimits as $component => $details) {
            $input = $studentScores[$component] ?? null;
            $value = is_string($input) ? trim($input) : '';
            if (preg_match('/\A\d{1,3}(?:\.\d{1,2})?\z/', $value) !== 1
                || (float) $value > $details['maximum']) {
                throw new InvalidArgumentException(
                    $details['label'] . ' score must be between 0 and ' . $details['maximum'] . '.'
                );
            }
            $scoreValues[$component] = $value;
        }

        $classIdForRow = (int) $student['class_id'];
        $sessionIdForRow = (int) $student['academic_session_id'];
        $firstTest = $scoreValues['first_test'];
        $secondTest = $scoreValues['second_test'];
        $exam = $scoreValues['exam'];
        $assessmentAverage = (float) $firstTest + (float) $secondTest;
        $overallScore = number_format(
            round($assessmentAverage + (float) $exam, 2),
            2,
            '.',
            ''
        );

        mysqli_stmt_bind_param(
            $insert,
            'iiiiiissss',
            $studentId,
            $classIdForRow,
            $sessionIdForRow,
            $subjectId,
            $termId,
            $teacherId,
            $firstTest,
            $secondTest,
            $exam,
            $overallScore
        );
        mysqli_stmt_execute($insert);
    }

    mysqli_stmt_close($insert);
    if ($existingResultCount > 0) {
        $approvalUpdate = mysqli_prepare(
            $conn,
            'UPDATE teacher_score_edit_approvals
             SET edit_approved = 0
             WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
                 AND subject_id = ? AND term_id = ?'
        );
    } else {
        $approvalUpdate = mysqli_prepare(
            $conn,
            'UPDATE teacher_score_edit_approvals
             SET scores_submitted = 1
             WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
                 AND subject_id = ? AND term_id = ?'
        );
    }
    mysqli_stmt_bind_param(
        $approvalUpdate,
        'iiiii',
        $teacherId,
        $classId,
        $activeSessionIdForApproval,
        $subjectId,
        $termId
    );
    mysqli_stmt_execute($approvalUpdate);
    mysqli_stmt_close($approvalUpdate);

    $uploadContext = $roster[0];
    record_teacher_activity(
        $conn,
        $teacherId,
        (string) ($_SESSION['name'] ?? ''),
        'score_upload',
        (int) $classId,
        (int) $subjectId,
        (int) $termId,
        (string) $uploadContext['class_name'],
        (string) $uploadContext['subject_name'],
        (string) $uploadContext['term_name'],
        (string) $uploadContext['session_name'],
        count($roster)
    );
    mysqli_commit($conn);
    $transactionStarted = false;
    set_flash('success', 'Class scores uploaded successfully.');
} catch (InvalidArgumentException $exception) {
    if ($transactionStarted) {
        mysqli_rollback($conn);
    }
    set_flash('error', $exception->getMessage());
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        try {
            mysqli_rollback($conn);
        } catch (mysqli_sql_exception $rollbackException) {
            error_log('Class score rollback failed: ' . $rollbackException->getMessage());
        }
    }
    error_log('Saving class scores failed: ' . $exception->getMessage());
    set_flash('error', 'The class scores could not be saved. Please reload and try again.');
}

$redirectParams = [];
if ($classId && $classId > 0) {
    $redirectParams['class_id'] = $classId;
}
if ($subjectId && $subjectId > 0) {
    $redirectParams['subject_id'] = $subjectId;
}
if ($termId && $termId > 0) {
    $redirectParams['term_id'] = $termId;
}

header('Location: dashboard.php?' . http_build_query($redirectParams));
exit;
