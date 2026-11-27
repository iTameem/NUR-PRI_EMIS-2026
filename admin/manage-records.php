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

function record_post_id(string $key): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($value) || $value < 1) {
        throw new InvalidArgumentException('Select a valid record.');
    }

    return $value;
}

function record_post_text(string $key, int $maxLength, string $label): string
{
    $input = $_POST[$key] ?? '';
    $value = is_string($input) ? trim($input) : '';
    if ($value === '' || strlen($value) > $maxLength) {
        throw new InvalidArgumentException('Enter a valid ' . $label . '.');
    }

    return $value;
}

function record_exists(mysqli $connection, string $sql, int $value): bool
{
    $statement = mysqli_prepare($connection, $sql);
    mysqli_stmt_bind_param($statement, 'i', $value);
    mysqli_stmt_execute($statement);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);

    return $row !== null;
}

$actionInput = $_POST['action'] ?? '';
$action = is_string($actionInput) ? $actionInput : '';
$returnPages = [
    'update_teacher' => 'teachers',
    'delete_teacher' => 'teachers',
    'approve_score_reset' => 'score-resets',
    'update_student' => 'student-records',
    'delete_student_allocation' => 'student-records',
    'import_students' => 'student-records',
    'update_teacher_allocation' => 'allocations',
    'delete_teacher_allocation' => 'allocations',
    'update_class_teacher' => 'class-teachers',
    'delete_class_teacher' => 'class-teachers',
];
$returnPage = $returnPages[$action] ?? 'overview';
$adminId = (int) $_SESSION['user_id'];
$transactionStarted = false;

try {
    if ($action === 'update_teacher') {
        $teacherId = record_post_id('teacher_id');
        $fullname = record_post_text('fullname', 150, 'teacher name');
        $phone = record_post_text('phone_No', 30, 'phone number');
        $passwordInput = $_POST['password'] ?? '';
        if (!is_string($passwordInput)) {
            throw new InvalidArgumentException('Enter a valid password.');
        }
        $password = $passwordInput;
        if (preg_match('/\A\+?[0-9][0-9 ()-]{5,28}\z/', $phone) !== 1) {
            throw new InvalidArgumentException('Enter a valid phone number.');
        }
        if ($password !== '' && !password_meets_policy($password)) {
            throw new InvalidArgumentException(
                'Password must be at least 8 characters and include uppercase and lowercase letters, a number, and a special character.'
            );
        }

        if (!record_exists(
            $conn,
            "SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1",
            $teacherId
        )) {
            throw new InvalidArgumentException('The active teacher account was not found.');
        }

        if ($password === '') {
            $statement = mysqli_prepare(
                $conn,
                "UPDATE users SET fullname = ?, phone_No = ? WHERE id = ? AND role = 'teacher'"
            );
            mysqli_stmt_bind_param($statement, 'ssi', $fullname, $phone, $teacherId);
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $statement = mysqli_prepare(
                $conn,
                "UPDATE users SET fullname = ?, phone_No = ?, password_hash = ?
                 WHERE id = ? AND role = 'teacher'"
            );
            mysqli_stmt_bind_param($statement, 'sssi', $fullname, $phone, $passwordHash, $teacherId);
        }
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        set_flash(
            'success',
            $password === '' ? 'Teacher details updated.' : 'Teacher details and password updated.'
        );
    } elseif ($action === 'approve_score_reset') {
        $teacherId = record_post_id('teacher_id');
        $classId = record_post_id('class_id');
        $sessionId = record_post_id('session_id');
        $subjectId = record_post_id('subject_id');
        $termId = record_post_id('term_id');

        mysqli_begin_transaction($conn);
        $transactionStarted = true;

        $allocationQuery = mysqli_prepare(
            $conn,
            'SELECT teacher_id
             FROM teacher_class_allocations
             WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
                 AND subject_id = ? AND term_id = ?
             FOR UPDATE'
        );
        mysqli_stmt_bind_param(
            $allocationQuery,
            'iiiii',
            $teacherId,
            $classId,
            $sessionId,
            $subjectId,
            $termId
        );
        mysqli_stmt_execute($allocationQuery);
        $allocation = mysqli_fetch_assoc(mysqli_stmt_get_result($allocationQuery));
        mysqli_stmt_close($allocationQuery);
        if (!$allocation) {
            throw new InvalidArgumentException('The teacher assignment was not found.');
        }

        $resultsQuery = mysqli_prepare(
            $conn,
            'SELECT COUNT(*) AS result_count
             FROM results
             WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
                 AND subject_id = ? AND term_id = ?'
        );
        mysqli_stmt_bind_param(
            $resultsQuery,
            'iiiii',
            $teacherId,
            $classId,
            $sessionId,
            $subjectId,
            $termId
        );
        mysqli_stmt_execute($resultsQuery);
        $resultCount = mysqli_fetch_assoc(mysqli_stmt_get_result($resultsQuery));
        mysqli_stmt_close($resultsQuery);
        if ((int) $resultCount['result_count'] === 0) {
            throw new InvalidArgumentException('This assignment has no submitted scores to reset.');
        }

        $approvalInsert = mysqli_prepare(
            $conn,
            'INSERT IGNORE INTO teacher_score_edit_approvals
                (teacher_id, class_id, academic_session_id, subject_id, term_id, scores_submitted)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        mysqli_stmt_bind_param(
            $approvalInsert,
            'iiiii',
            $teacherId,
            $classId,
            $sessionId,
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
            $sessionId,
            $subjectId,
            $termId
        );
        mysqli_stmt_execute($approvalQuery);
        $approval = mysqli_fetch_assoc(mysqli_stmt_get_result($approvalQuery));
        mysqli_stmt_close($approvalQuery);
        if ((int) $approval['edit_approved'] === 1) {
            throw new InvalidArgumentException('An edit is already approved for this assignment.');
        }

        $approvalUpdate = mysqli_prepare(
            $conn,
            'UPDATE teacher_score_edit_approvals
             SET scores_submitted = 1, edit_approved = 1,
                 approved_by = ?, approved_at = CURRENT_TIMESTAMP
             WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
                 AND subject_id = ? AND term_id = ?'
        );
        mysqli_stmt_bind_param(
            $approvalUpdate,
            'iiiiii',
            $adminId,
            $teacherId,
            $classId,
            $sessionId,
            $subjectId,
            $termId
        );
        mysqli_stmt_execute($approvalUpdate);
        mysqli_stmt_close($approvalUpdate);

        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', 'One score edit approved for the teacher.');
    } elseif ($action === 'delete_teacher') {
        $teacherId = record_post_id('teacher_id');
        mysqli_begin_transaction($conn);
        $transactionStarted = true;

        $teacherLock = mysqli_prepare(
            $conn,
            "SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1 FOR UPDATE"
        );
        mysqli_stmt_bind_param($teacherLock, 'i', $teacherId);
        mysqli_stmt_execute($teacherLock);
        $teacher = mysqli_fetch_assoc(mysqli_stmt_get_result($teacherLock));
        mysqli_stmt_close($teacherLock);
        if (!$teacher) {
            throw new InvalidArgumentException('The active teacher account was not found.');
        }

        $referencesQuery = mysqli_prepare(
            $conn,
            'SELECT
                (SELECT COUNT(*) FROM teacher_class_allocations WHERE teacher_id = ?) AS subject_allocations,
                (SELECT COUNT(*) FROM class_teacher_allocations WHERE teacher_id = ?) AS class_allocations,
                (SELECT COUNT(*) FROM results WHERE teacher_id = ?) AS results_count,
                (SELECT COUNT(*) FROM student_class_allocations WHERE allocated_by = ?) AS student_records,
                (SELECT COUNT(*) FROM teacher_class_allocations WHERE allocated_by = ?) AS subject_records,
                (SELECT COUNT(*) FROM class_teacher_allocations WHERE allocated_by = ?) AS class_records'
        );
        mysqli_stmt_bind_param(
            $referencesQuery,
            'iiiiii',
            $teacherId,
            $teacherId,
            $teacherId,
            $teacherId,
            $teacherId,
            $teacherId
        );
        mysqli_stmt_execute($referencesQuery);
        $references = mysqli_fetch_assoc(mysqli_stmt_get_result($referencesQuery));
        mysqli_stmt_close($referencesQuery);
        $referenceCount = array_sum(array_map('intval', $references));
        if ($referenceCount > 0) {
            throw new InvalidArgumentException(
                'This teacher has linked allocations or results. Remove those records before deleting the account.'
            );
        }

        $delete = mysqli_prepare($conn, "DELETE FROM users WHERE id = ? AND role = 'teacher'");
        mysqli_stmt_bind_param($delete, 'i', $teacherId);
        mysqli_stmt_execute($delete);
        mysqli_stmt_close($delete);
        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', 'Teacher account deleted.');
    } elseif ($action === 'update_student') {
        $studentId = record_post_id('student_id');
        $oldClassId = record_post_id('old_class_id');
        $oldSessionId = record_post_id('old_session_id');
        $classId = record_post_id('class_id');
        $sessionId = record_post_id('session_id');
        $fullname = record_post_text('fullname', 200, 'student name');

        mysqli_begin_transaction($conn);
        $transactionStarted = true;
        $allocationLock = mysqli_prepare(
            $conn,
            'SELECT student_id FROM student_class_allocations
             WHERE student_id = ? AND class_id = ? AND academic_session_id = ? FOR UPDATE'
        );
        mysqli_stmt_bind_param($allocationLock, 'iii', $studentId, $oldClassId, $oldSessionId);
        mysqli_stmt_execute($allocationLock);
        $allocation = mysqli_fetch_assoc(mysqli_stmt_get_result($allocationLock));
        mysqli_stmt_close($allocationLock);
        if (!$allocation) {
            throw new InvalidArgumentException('The student enrollment was not found.');
        }

        $resultsQuery = mysqli_prepare(
            $conn,
            'SELECT COUNT(*) AS total FROM results
             WHERE student_id = ? AND class_id = ? AND academic_session_id = ?'
        );
        mysqli_stmt_bind_param($resultsQuery, 'iii', $studentId, $oldClassId, $oldSessionId);
        mysqli_stmt_execute($resultsQuery);
        $results = mysqli_fetch_assoc(mysqli_stmt_get_result($resultsQuery));
        mysqli_stmt_close($resultsQuery);
        $placementChanged = $classId !== $oldClassId || $sessionId !== $oldSessionId;
        if ($placementChanged && (int) $results['total'] > 0) {
            throw new InvalidArgumentException('This enrollment has results. Its class and session cannot be changed.');
        }

        if (!record_exists($conn, 'SELECT id FROM classes WHERE id = ?', $classId)
            || !record_exists($conn, 'SELECT id FROM academic_sessions WHERE id = ?', $sessionId)) {
            throw new InvalidArgumentException('Select an existing class and academic session.');
        }

        $updateStudent = mysqli_prepare($conn, 'UPDATE students SET fullname = ? WHERE id = ?');
        mysqli_stmt_bind_param($updateStudent, 'si', $fullname, $studentId);
        mysqli_stmt_execute($updateStudent);
        mysqli_stmt_close($updateStudent);

        if ($placementChanged) {
            $updateAllocation = mysqli_prepare(
                $conn,
                'UPDATE student_class_allocations
                 SET class_id = ?, academic_session_id = ?
                 WHERE student_id = ? AND class_id = ? AND academic_session_id = ?'
            );
            mysqli_stmt_bind_param(
                $updateAllocation,
                'iiiii',
                $classId,
                $sessionId,
                $studentId,
                $oldClassId,
                $oldSessionId
            );
            mysqli_stmt_execute($updateAllocation);
            mysqli_stmt_close($updateAllocation);
        }

        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', 'Student record updated.');
    } elseif ($action === 'delete_student_allocation') {
        $studentId = record_post_id('student_id');
        $classId = record_post_id('class_id');
        $sessionId = record_post_id('session_id');
        mysqli_begin_transaction($conn);
        $transactionStarted = true;

        $allocationLock = mysqli_prepare(
            $conn,
            'SELECT student_id FROM student_class_allocations
             WHERE student_id = ? AND class_id = ? AND academic_session_id = ? FOR UPDATE'
        );
        mysqli_stmt_bind_param($allocationLock, 'iii', $studentId, $classId, $sessionId);
        mysqli_stmt_execute($allocationLock);
        $allocation = mysqli_fetch_assoc(mysqli_stmt_get_result($allocationLock));
        mysqli_stmt_close($allocationLock);
        if (!$allocation) {
            throw new InvalidArgumentException('The student enrollment was not found.');
        }

        $resultsQuery = mysqli_prepare(
            $conn,
            'SELECT COUNT(*) AS total FROM results
             WHERE student_id = ? AND class_id = ? AND academic_session_id = ?'
        );
        mysqli_stmt_bind_param($resultsQuery, 'iii', $studentId, $classId, $sessionId);
        mysqli_stmt_execute($resultsQuery);
        $results = mysqli_fetch_assoc(mysqli_stmt_get_result($resultsQuery));
        mysqli_stmt_close($resultsQuery);
        if ((int) $results['total'] > 0) {
            throw new InvalidArgumentException('This enrollment has results and cannot be deleted.');
        }

        $deleteAllocation = mysqli_prepare(
            $conn,
            'DELETE FROM student_class_allocations
             WHERE student_id = ? AND class_id = ? AND academic_session_id = ?'
        );
        mysqli_stmt_bind_param($deleteAllocation, 'iii', $studentId, $classId, $sessionId);
        mysqli_stmt_execute($deleteAllocation);
        mysqli_stmt_close($deleteAllocation);

        $remainingQuery = mysqli_prepare(
            $conn,
            'SELECT COUNT(*) AS total FROM student_class_allocations WHERE student_id = ?'
        );
        mysqli_stmt_bind_param($remainingQuery, 'i', $studentId);
        mysqli_stmt_execute($remainingQuery);
        $remaining = mysqli_fetch_assoc(mysqli_stmt_get_result($remainingQuery));
        mysqli_stmt_close($remainingQuery);
        if ((int) $remaining['total'] === 0) {
            $deleteStudent = mysqli_prepare($conn, 'DELETE FROM students WHERE id = ?');
            mysqli_stmt_bind_param($deleteStudent, 'i', $studentId);
            mysqli_stmt_execute($deleteStudent);
            mysqli_stmt_close($deleteStudent);
        }

        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', 'Student enrollment deleted.');
    } elseif ($action === 'update_teacher_allocation') {
        $oldTeacherId = record_post_id('old_teacher_id');
        $oldClassId = record_post_id('old_class_id');
        $oldSessionId = record_post_id('old_session_id');
        $oldSubjectId = record_post_id('old_subject_id');
        $oldTermId = record_post_id('old_term_id');
        $teacherId = record_post_id('teacher_id');
        $classId = record_post_id('class_id');
        $subjectId = record_post_id('subject_id');
        $termId = record_post_id('term_id');

        mysqli_begin_transaction($conn);
        $transactionStarted = true;
        $allocationLock = mysqli_prepare(
            $conn,
            'SELECT teacher_id FROM teacher_class_allocations
             WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
               AND subject_id = ? AND term_id = ? FOR UPDATE'
        );
        mysqli_stmt_bind_param(
            $allocationLock,
            'iiiii',
            $oldTeacherId,
            $oldClassId,
            $oldSessionId,
            $oldSubjectId,
            $oldTermId
        );
        mysqli_stmt_execute($allocationLock);
        $allocation = mysqli_fetch_assoc(mysqli_stmt_get_result($allocationLock));
        mysqli_stmt_close($allocationLock);
        if (!$allocation) {
            throw new InvalidArgumentException('The teacher allocation was not found.');
        }

        if (!record_exists(
            $conn,
            "SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1",
            $teacherId
        )) {
            throw new InvalidArgumentException('Select an active teacher.');
        }
        if (!record_exists($conn, 'SELECT id FROM classes WHERE id = ?', $classId)
            || !record_exists($conn, 'SELECT id FROM subjects WHERE id = ?', $subjectId)) {
            throw new InvalidArgumentException('Select an existing class and subject.');
        }

        $termQuery = mysqli_prepare($conn, 'SELECT academic_session_id FROM academic_terms WHERE id = ?');
        mysqli_stmt_bind_param($termQuery, 'i', $termId);
        mysqli_stmt_execute($termQuery);
        $term = mysqli_fetch_assoc(mysqli_stmt_get_result($termQuery));
        mysqli_stmt_close($termQuery);
        if (!$term) {
            throw new InvalidArgumentException('Select an existing academic term.');
        }
        $sessionId = (int) $term['academic_session_id'];
        $placementChanged = $teacherId !== $oldTeacherId
            || $classId !== $oldClassId
            || $sessionId !== $oldSessionId
            || $subjectId !== $oldSubjectId
            || $termId !== $oldTermId;

        if ($placementChanged) {
            $resultsQuery = mysqli_prepare(
                $conn,
                'SELECT COUNT(*) AS total FROM results
                 WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
                   AND subject_id = ? AND term_id = ?'
            );
            mysqli_stmt_bind_param(
                $resultsQuery,
                'iiiii',
                $oldTeacherId,
                $oldClassId,
                $oldSessionId,
                $oldSubjectId,
                $oldTermId
            );
            mysqli_stmt_execute($resultsQuery);
            $results = mysqli_fetch_assoc(mysqli_stmt_get_result($resultsQuery));
            mysqli_stmt_close($resultsQuery);
            if ((int) $results['total'] > 0) {
                throw new InvalidArgumentException('This allocation has results and cannot be changed.');
            }
        }

        if ($placementChanged) {
            $duplicateAllocationQuery = mysqli_prepare(
                $conn,
                'SELECT teacher_id
                 FROM teacher_class_allocations
                 WHERE class_id = ? AND academic_session_id = ?
                   AND subject_id = ? AND term_id = ?
                   AND NOT (
                       teacher_id = ? AND class_id = ? AND academic_session_id = ?
                       AND subject_id = ? AND term_id = ?
                   )
                 LIMIT 1'
            );
            mysqli_stmt_bind_param(
                $duplicateAllocationQuery,
                'iiiiiiiii',
                $classId,
                $sessionId,
                $subjectId,
                $termId,
                $oldTeacherId,
                $oldClassId,
                $oldSessionId,
                $oldSubjectId,
                $oldTermId
            );
            mysqli_stmt_execute($duplicateAllocationQuery);
            $duplicateAllocation = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicateAllocationQuery));
            mysqli_stmt_close($duplicateAllocationQuery);
            if ($duplicateAllocation) {
                throw new InvalidArgumentException(
                    'A teacher is already allocated to this class and subject for the selected term.'
                );
            }
        }

        $update = mysqli_prepare(
            $conn,
            'UPDATE teacher_class_allocations
             SET teacher_id = ?, class_id = ?, academic_session_id = ?, subject_id = ?, term_id = ?
             WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
               AND subject_id = ? AND term_id = ?'
        );
        mysqli_stmt_bind_param(
            $update,
            'iiiiiiiiii',
            $teacherId,
            $classId,
            $sessionId,
            $subjectId,
            $termId,
            $oldTeacherId,
            $oldClassId,
            $oldSessionId,
            $oldSubjectId,
            $oldTermId
        );
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', 'Teacher allocation updated.');
    } elseif ($action === 'delete_teacher_allocation') {
        $teacherId = record_post_id('teacher_id');
        $classId = record_post_id('class_id');
        $sessionId = record_post_id('session_id');
        $subjectId = record_post_id('subject_id');
        $termId = record_post_id('term_id');
        mysqli_begin_transaction($conn);
        $transactionStarted = true;

        $resultsQuery = mysqli_prepare(
            $conn,
            'SELECT COUNT(*) AS total FROM results
             WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
               AND subject_id = ? AND term_id = ?'
        );
        mysqli_stmt_bind_param($resultsQuery, 'iiiii', $teacherId, $classId, $sessionId, $subjectId, $termId);
        mysqli_stmt_execute($resultsQuery);
        $results = mysqli_fetch_assoc(mysqli_stmt_get_result($resultsQuery));
        mysqli_stmt_close($resultsQuery);
        if ((int) $results['total'] > 0) {
            throw new InvalidArgumentException('This allocation has results and cannot be deleted.');
        }

        $delete = mysqli_prepare(
            $conn,
            'DELETE FROM teacher_class_allocations
             WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
               AND subject_id = ? AND term_id = ?'
        );
        mysqli_stmt_bind_param($delete, 'iiiii', $teacherId, $classId, $sessionId, $subjectId, $termId);
        mysqli_stmt_execute($delete);
        $deleted = mysqli_stmt_affected_rows($delete);
        mysqli_stmt_close($delete);
        if ($deleted !== 1) {
            throw new InvalidArgumentException('The teacher allocation was not found.');
        }

        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', 'Teacher allocation deleted.');
    } elseif ($action === 'update_class_teacher') {
        $oldTeacherId = record_post_id('old_teacher_id');
        $oldClassId = record_post_id('old_class_id');
        $oldSessionId = record_post_id('old_session_id');
        $oldTermId = record_post_id('old_term_id');
        $teacherId = record_post_id('teacher_id');
        $classId = record_post_id('class_id');
        $termId = record_post_id('term_id');

        mysqli_begin_transaction($conn);
        $transactionStarted = true;
        $allocationLock = mysqli_prepare(
            $conn,
            'SELECT teacher_id FROM class_teacher_allocations
             WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ? AND term_id = ? FOR UPDATE'
        );
        mysqli_stmt_bind_param($allocationLock, 'iiii', $oldTeacherId, $oldClassId, $oldSessionId, $oldTermId);
        mysqli_stmt_execute($allocationLock);
        $allocation = mysqli_fetch_assoc(mysqli_stmt_get_result($allocationLock));
        mysqli_stmt_close($allocationLock);
        if (!$allocation) {
            throw new InvalidArgumentException('The class teacher assignment was not found.');
        }

        if (!record_exists(
            $conn,
            "SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1",
            $teacherId
        ) || !record_exists($conn, 'SELECT id FROM classes WHERE id = ?', $classId)) {
            throw new InvalidArgumentException('Select an active teacher and an existing class.');
        }
        $termQuery = mysqli_prepare($conn, 'SELECT academic_session_id FROM academic_terms WHERE id = ?');
        mysqli_stmt_bind_param($termQuery, 'i', $termId);
        mysqli_stmt_execute($termQuery);
        $term = mysqli_fetch_assoc(mysqli_stmt_get_result($termQuery));
        mysqli_stmt_close($termQuery);
        if (!$term) {
            throw new InvalidArgumentException('Select an existing academic term.');
        }
        $sessionId = (int) $term['academic_session_id'];

        $duplicateAssignmentQuery = mysqli_prepare(
            $conn,
            'SELECT teacher_id
             FROM class_teacher_allocations
             WHERE class_id = ? AND academic_session_id = ? AND term_id = ?
               AND NOT (
                   class_id = ? AND academic_session_id = ? AND term_id = ?
               )
             LIMIT 1'
        );
        mysqli_stmt_bind_param(
            $duplicateAssignmentQuery,
            'iiiiii',
            $classId,
            $sessionId,
            $termId,
            $oldClassId,
            $oldSessionId,
            $oldTermId
        );
        mysqli_stmt_execute($duplicateAssignmentQuery);
        $duplicateAssignment = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicateAssignmentQuery));
        mysqli_stmt_close($duplicateAssignmentQuery);
        if ($duplicateAssignment) {
            throw new InvalidArgumentException(
                'This class already has a class teacher assigned for the selected term.'
            );
        }

        $update = mysqli_prepare(
            $conn,
            'UPDATE class_teacher_allocations
             SET teacher_id = ?, class_id = ?, academic_session_id = ?, term_id = ?
             WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ? AND term_id = ?'
        );
        mysqli_stmt_bind_param(
            $update,
            'iiiiiiii',
            $teacherId,
            $classId,
            $sessionId,
            $termId,
            $oldTeacherId,
            $oldClassId,
            $oldSessionId,
            $oldTermId
        );
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', 'Class teacher assignment updated.');
    } elseif ($action === 'delete_class_teacher') {
        $teacherId = record_post_id('teacher_id');
        $classId = record_post_id('class_id');
        $sessionId = record_post_id('session_id');
        $termId = record_post_id('term_id');
        $delete = mysqli_prepare(
            $conn,
            'DELETE FROM class_teacher_allocations
             WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ? AND term_id = ?'
        );
        mysqli_stmt_bind_param($delete, 'iiii', $teacherId, $classId, $sessionId, $termId);
        mysqli_stmt_execute($delete);
        $deleted = mysqli_stmt_affected_rows($delete);
        mysqli_stmt_close($delete);
        if ($deleted !== 1) {
            throw new InvalidArgumentException('The class teacher assignment was not found.');
        }
        set_flash('success', 'Class teacher assignment deleted.');
    } elseif ($action === 'import_students') {
        $classId = record_post_id('class_id');
        $sessionId = record_post_id('session_id');
        $upload = $_FILES['student_csv'] ?? null;
        if (!is_array($upload)) {
            throw new InvalidArgumentException('Choose a CSV file to import.');
        }
        $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $uploadMessage = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The CSV file is too large.',
                UPLOAD_ERR_NO_FILE => 'Choose a CSV file to import.',
                UPLOAD_ERR_PARTIAL => 'The CSV upload was incomplete. Please try again.',
                default => 'The CSV file could not be uploaded.',
            };
            throw new InvalidArgumentException($uploadMessage);
        }
        $filename = $upload['name'] ?? '';
        $temporaryPath = $upload['tmp_name'] ?? '';
        $fileSize = (int) ($upload['size'] ?? 0);
        if (!is_string($filename) || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv'
            || !is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
            throw new InvalidArgumentException('Choose a valid CSV upload.');
        }
        if ($fileSize < 1 || $fileSize > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('The CSV file must be smaller than 2 MB.');
        }

        $csv = fopen($temporaryPath, 'rb');
        if ($csv === false) {
            throw new InvalidArgumentException('The uploaded CSV file could not be read.');
        }
        $names = [];
        try {
            $header = fgetcsv($csv, 0, ',', '"', '\\');
            if (!is_array($header) || count($header) !== 1) {
                throw new InvalidArgumentException('The CSV must have a single fullname column.');
            }
            $headerName = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            if (strtolower(trim((string) $headerName)) !== 'fullname') {
                throw new InvalidArgumentException('The first CSV column must be named fullname.');
            }

            $lineNumber = 1;
            while (($row = fgetcsv($csv, 0, ',', '"', '\\')) !== false) {
                $lineNumber++;
                if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                    continue;
                }
                if (count($row) !== 1) {
                    throw new InvalidArgumentException('CSV row ' . $lineNumber . ' must contain one student name.');
                }
                $fullname = trim((string) $row[0]);
                if ($fullname === '' || strlen($fullname) > 200) {
                    throw new InvalidArgumentException('CSV row ' . $lineNumber . ' has an invalid student name.');
                }
                $names[] = $fullname;
                if (count($names) > 1000) {
                    throw new InvalidArgumentException('A CSV import can contain at most 1,000 students.');
                }
            }
        } finally {
            fclose($csv);
        }
        if ($names === []) {
            throw new InvalidArgumentException('The CSV contains no student names.');
        }
        if (!record_exists($conn, 'SELECT id FROM classes WHERE id = ?', $classId)
            || !record_exists($conn, 'SELECT id FROM academic_sessions WHERE id = ?', $sessionId)) {
            throw new InvalidArgumentException('Select an existing class and academic session.');
        }

        mysqli_begin_transaction($conn);
        $transactionStarted = true;
        $studentInsert = mysqli_prepare($conn, 'INSERT INTO students (fullname) VALUES (?)');
        $allocationInsert = mysqli_prepare(
            $conn,
            'INSERT INTO student_class_allocations
                (student_id, class_id, academic_session_id, allocated_by)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($names as $fullname) {
            mysqli_stmt_bind_param($studentInsert, 's', $fullname);
            mysqli_stmt_execute($studentInsert);
            $studentId = mysqli_insert_id($conn);
            mysqli_stmt_bind_param($allocationInsert, 'iiii', $studentId, $classId, $sessionId, $adminId);
            mysqli_stmt_execute($allocationInsert);
        }
        mysqli_stmt_close($studentInsert);
        mysqli_stmt_close($allocationInsert);
        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', number_format(count($names)) . ' students imported and allocated.');
    } else {
        throw new InvalidArgumentException('Unknown record management action.');
    }
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
            error_log('Record management rollback failed: ' . $rollbackException->getMessage());
        }
    }

    if ($exception->getCode() === 1062) {
        $duplicateMessage = match ($action) {
            'update_teacher_allocation' => 'A teacher is already allocated to this class and subject for the selected term.',
            'update_class_teacher' => 'This class already has a class teacher assigned for the selected term.',
            default => 'That phone number or allocation already exists.',
        };
        set_flash('error', $duplicateMessage);
    } elseif ($exception->getCode() === 1451) {
        set_flash('error', 'This record has linked results or history and cannot be deleted.');
    } else {
        error_log('Record management request failed: ' . $exception->getMessage());
        set_flash('error', 'The requested change could not be saved.');
    }
}

header('Location: dashboard.php?page=' . rawurlencode($returnPage));
exit;
