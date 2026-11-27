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

function valid_academic_date(string $date): bool
{
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
        return false;
    }

    $parts = array_map('intval', explode('-', $date));
    return checkdate($parts[1], $parts[2], $parts[0]);
}

$actionInput = $_POST['action'] ?? '';
$action = is_string($actionInput) ? $actionInput : '';
$returnPages = [
    'create_session' => 'academic',
    'update_session' => 'academic',
    'delete_session' => 'academic',
    'activate_session' => 'academic',
    'create_term' => 'academic',
    'create_class' => 'classes',
    'create_subject' => 'subjects',
    'update_subject' => 'subjects',
    'create_student' => 'student-records',
    'allocate_teacher' => 'teacher-allocation',
    'allocate_class_teacher' => 'class-teachers',
];
$returnPage = $returnPages[$action] ?? 'academic';
$adminId = (int) $_SESSION['user_id'];
$transactionStarted = false;

try {
    if ($action === 'create_session') {
        $nameInput = $_POST['session_name'] ?? '';
        $startInput = $_POST['starts_on'] ?? '';
        $endInput = $_POST['ends_on'] ?? '';
        $name = is_string($nameInput) ? trim($nameInput) : '';
        $startsOn = is_string($startInput) ? $startInput : '';
        $endsOn = is_string($endInput) ? $endInput : '';

        if ($name === '' || strlen($name) > 50
            || !valid_academic_date($startsOn)
            || !valid_academic_date($endsOn)
            || $startsOn > $endsOn) {
            throw new InvalidArgumentException('Enter a session name and a valid date range.');
        }

        $statement = mysqli_prepare(
            $conn,
            'INSERT INTO academic_sessions (session_name, starts_on, ends_on) VALUES (?, ?, ?)'
        );
        mysqli_stmt_bind_param($statement, 'sss', $name, $startsOn, $endsOn);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        set_flash('success', 'Academic session created. Activate it when ready to use.');
    } elseif ($action === 'update_session') {
        $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
        $nameInput = $_POST['session_name'] ?? '';
        $startInput = $_POST['starts_on'] ?? '';
        $endInput = $_POST['ends_on'] ?? '';
        $name = is_string($nameInput) ? trim($nameInput) : '';
        $startsOn = is_string($startInput) ? $startInput : '';
        $endsOn = is_string($endInput) ? $endInput : '';

        if (!$sessionId || $sessionId < 1 || $name === '' || strlen($name) > 50
            || !valid_academic_date($startsOn)
            || !valid_academic_date($endsOn)
            || $startsOn > $endsOn) {
            throw new InvalidArgumentException('Enter a session name and a valid date range.');
        }

        mysqli_begin_transaction($conn);
        $transactionStarted = true;
        $sessionQuery = mysqli_prepare(
            $conn,
            'SELECT id FROM academic_sessions WHERE id = ? FOR UPDATE'
        );
        mysqli_stmt_bind_param($sessionQuery, 'i', $sessionId);
        mysqli_stmt_execute($sessionQuery);
        $sessionExists = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionQuery));
        mysqli_stmt_close($sessionQuery);
        if (!$sessionExists) {
            throw new InvalidArgumentException('The selected academic session was not found.');
        }

        $termDatesQuery = mysqli_prepare(
            $conn,
            'SELECT COUNT(*) AS total
             FROM academic_terms
             WHERE academic_session_id = ?
               AND (starts_on < ? OR ends_on > ?)'
        );
        mysqli_stmt_bind_param($termDatesQuery, 'iss', $sessionId, $startsOn, $endsOn);
        mysqli_stmt_execute($termDatesQuery);
        $termDates = mysqli_fetch_assoc(mysqli_stmt_get_result($termDatesQuery));
        mysqli_stmt_close($termDatesQuery);
        if ((int) $termDates['total'] > 0) {
            throw new InvalidArgumentException('The new session dates must contain all existing term dates.');
        }

        $update = mysqli_prepare(
            $conn,
            'UPDATE academic_sessions
             SET session_name = ?, starts_on = ?, ends_on = ?
             WHERE id = ?'
        );
        mysqli_stmt_bind_param($update, 'sssi', $name, $startsOn, $endsOn, $sessionId);
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', 'Academic session updated.');
    } elseif ($action === 'delete_session') {
        $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$sessionId || $sessionId < 1) {
            throw new InvalidArgumentException('Select a valid academic session.');
        }

        mysqli_begin_transaction($conn);
        $transactionStarted = true;
        $sessionQuery = mysqli_prepare(
            $conn,
            'SELECT id FROM academic_sessions WHERE id = ? FOR UPDATE'
        );
        mysqli_stmt_bind_param($sessionQuery, 'i', $sessionId);
        mysqli_stmt_execute($sessionQuery);
        $sessionExists = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionQuery));
        mysqli_stmt_close($sessionQuery);
        if (!$sessionExists) {
            throw new InvalidArgumentException('The selected academic session was not found.');
        }

        $referencesQuery = mysqli_prepare(
            $conn,
            'SELECT
                (SELECT COUNT(*) FROM academic_terms WHERE academic_session_id = ?) AS terms_count,
                (SELECT COUNT(*) FROM student_class_allocations WHERE academic_session_id = ?) AS students_count,
                (SELECT COUNT(*) FROM teacher_class_allocations WHERE academic_session_id = ?) AS teacher_allocations_count,
                (SELECT COUNT(*) FROM class_teacher_allocations WHERE academic_session_id = ?) AS class_teacher_allocations_count,
                (SELECT COUNT(*) FROM results WHERE academic_session_id = ?) AS results_count'
        );
        mysqli_stmt_bind_param(
            $referencesQuery,
            'iiiii',
            $sessionId,
            $sessionId,
            $sessionId,
            $sessionId,
            $sessionId
        );
        mysqli_stmt_execute($referencesQuery);
        $references = mysqli_fetch_assoc(mysqli_stmt_get_result($referencesQuery));
        mysqli_stmt_close($referencesQuery);
        if ((int) $references['terms_count'] > 0
            || (int) $references['students_count'] > 0
            || (int) $references['teacher_allocations_count'] > 0
            || (int) $references['class_teacher_allocations_count'] > 0
            || (int) $references['results_count'] > 0) {
            throw new InvalidArgumentException(
                'This session is in use. Remove its terms, student enrollments, teacher and class teacher allocations, and results before deleting it.'
            );
        }

        $delete = mysqli_prepare($conn, 'DELETE FROM academic_sessions WHERE id = ?');
        mysqli_stmt_bind_param($delete, 'i', $sessionId);
        mysqli_stmt_execute($delete);
        mysqli_stmt_close($delete);
        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', 'Academic session deleted.');
    } elseif ($action === 'activate_session') {
        $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$sessionId || $sessionId < 1) {
            throw new InvalidArgumentException('Select a valid academic session.');
        }

        mysqli_begin_transaction($conn);
        $transactionStarted = true;
        $existsQuery = mysqli_prepare($conn, 'SELECT id FROM academic_sessions WHERE id = ?');
        mysqli_stmt_bind_param($existsQuery, 'i', $sessionId);
        mysqli_stmt_execute($existsQuery);
        $sessionExists = mysqli_fetch_assoc(mysqli_stmt_get_result($existsQuery));
        mysqli_stmt_close($existsQuery);

        if (!$sessionExists) {
            mysqli_rollback($conn);
            $transactionStarted = false;
            throw new InvalidArgumentException('The selected academic session was not found.');
        }

        $deactivate = mysqli_prepare($conn, 'UPDATE academic_sessions SET is_active = 0');
        mysqli_stmt_execute($deactivate);
        mysqli_stmt_close($deactivate);

        $activate = mysqli_prepare($conn, 'UPDATE academic_sessions SET is_active = 1 WHERE id = ?');
        mysqli_stmt_bind_param($activate, 'i', $sessionId);
        mysqli_stmt_execute($activate);
        mysqli_stmt_close($activate);
        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', 'The academic session is now active.');
    } elseif ($action === 'create_class') {
        $classInput = $_POST['class_name'] ?? '';
        $className = is_string($classInput) ? trim($classInput) : '';
        if ($className === '' || strlen($className) > 100) {
            throw new InvalidArgumentException('Enter a class name of up to 100 characters.');
        }

        $statement = mysqli_prepare($conn, 'INSERT INTO classes (class_name) VALUES (?)');
        mysqli_stmt_bind_param($statement, 's', $className);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        set_flash('success', 'Class created.');
    } elseif ($action === 'create_subject') {
        $subjectInput = $_POST['subject_name'] ?? '';
        $subjectName = is_string($subjectInput) ? trim($subjectInput) : '';
        if ($subjectName === '' || strlen($subjectName) > 150) {
            throw new InvalidArgumentException('Enter a subject name of up to 150 characters.');
        }

        $statement = mysqli_prepare($conn, 'INSERT INTO subjects (subject_name) VALUES (?)');
        mysqli_stmt_bind_param($statement, 's', $subjectName);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        set_flash('success', 'Subject created.');
    } elseif ($action === 'update_subject') {
        $subjectId = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT);
        $subjectInput = $_POST['subject_name'] ?? '';
        $subjectName = is_string($subjectInput) ? trim($subjectInput) : '';
        if (!is_int($subjectId) || $subjectId < 1
            || $subjectName === '' || strlen($subjectName) > 150) {
            throw new InvalidArgumentException('Enter a subject name of up to 150 characters.');
        }

        $existsQuery = mysqli_prepare($conn, 'SELECT id FROM subjects WHERE id = ?');
        mysqli_stmt_bind_param($existsQuery, 'i', $subjectId);
        mysqli_stmt_execute($existsQuery);
        $subjectExists = mysqli_fetch_assoc(mysqli_stmt_get_result($existsQuery));
        mysqli_stmt_close($existsQuery);
        if (!$subjectExists) {
            throw new InvalidArgumentException('The selected subject was not found.');
        }

        $statement = mysqli_prepare($conn, 'UPDATE subjects SET subject_name = ? WHERE id = ?');
        mysqli_stmt_bind_param($statement, 'si', $subjectName, $subjectId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        set_flash('success', 'Subject updated.');
    } elseif ($action === 'create_student') {
        $fullnameInput = $_POST['fullname'] ?? '';
        $fullname = is_string($fullnameInput) ? trim($fullnameInput) : '';
        $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
        $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
        if ($fullname === '' || strlen($fullname) > 200
            || !$classId || $classId < 1 || !$sessionId || $sessionId < 1) {
            throw new InvalidArgumentException('Enter the student name and select a class and academic session.');
        }

        mysqli_begin_transaction($conn);
        $transactionStarted = true;
        $studentInsert = mysqli_prepare($conn, 'INSERT INTO students (fullname) VALUES (?)');
        mysqli_stmt_bind_param($studentInsert, 's', $fullname);
        mysqli_stmt_execute($studentInsert);
        $studentId = mysqli_insert_id($conn);
        mysqli_stmt_close($studentInsert);

        $allocationInsert = mysqli_prepare(
            $conn,
            'INSERT INTO student_class_allocations
                (student_id, class_id, academic_session_id, allocated_by)
             VALUES (?, ?, ?, ?)'
        );
        mysqli_stmt_bind_param($allocationInsert, 'iiii', $studentId, $classId, $sessionId, $adminId);
        mysqli_stmt_execute($allocationInsert);
        mysqli_stmt_close($allocationInsert);
        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', 'Student added and assigned to the selected class and session.');
    } elseif ($action === 'allocate_teacher') {
        $teacherId = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT);
        $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
        $subjectId = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT);
        $termId = filter_var($_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$teacherId || $teacherId < 1 || !$classId || $classId < 1
            || !$subjectId || $subjectId < 1 || !$termId || $termId < 1) {
            throw new InvalidArgumentException('Select a teacher, class, subject, and academic term.');
        }

        mysqli_begin_transaction($conn);
        $transactionStarted = true;
        $teacherCheck = mysqli_prepare(
            $conn,
            "SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1 FOR UPDATE"
        );
        mysqli_stmt_bind_param($teacherCheck, 'i', $teacherId);
        mysqli_stmt_execute($teacherCheck);
        $teacherExists = mysqli_fetch_assoc(mysqli_stmt_get_result($teacherCheck));
        mysqli_stmt_close($teacherCheck);
        if (!$teacherExists) {
            throw new InvalidArgumentException('The selected teacher account is not active.');
        }

        $termCheck = mysqli_prepare(
            $conn,
            'SELECT academic_session_id FROM academic_terms WHERE id = ?'
        );
        mysqli_stmt_bind_param($termCheck, 'i', $termId);
        mysqli_stmt_execute($termCheck);
        $term = mysqli_fetch_assoc(mysqli_stmt_get_result($termCheck));
        mysqli_stmt_close($termCheck);
        if (!$term) {
            throw new InvalidArgumentException('The selected academic term was not found.');
        }
        $sessionId = (int) $term['academic_session_id'];

        $existingAllocationQuery = mysqli_prepare(
            $conn,
            'SELECT teacher_id
             FROM teacher_class_allocations
             WHERE class_id = ? AND academic_session_id = ?
               AND subject_id = ? AND term_id = ?
             LIMIT 1'
        );
        mysqli_stmt_bind_param(
            $existingAllocationQuery,
            'iiii',
            $classId,
            $sessionId,
            $subjectId,
            $termId
        );
        mysqli_stmt_execute($existingAllocationQuery);
        $existingAllocation = mysqli_fetch_assoc(mysqli_stmt_get_result($existingAllocationQuery));
        mysqli_stmt_close($existingAllocationQuery);
        if ($existingAllocation) {
            throw new InvalidArgumentException(
                'A teacher is already allocated to this class and subject for the selected term.'
            );
        }

        $statement = mysqli_prepare(
            $conn,
            'INSERT INTO teacher_class_allocations
                (teacher_id, class_id, academic_session_id, subject_id, term_id, allocated_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        mysqli_stmt_bind_param(
            $statement,
            'iiiiii',
            $teacherId,
            $classId,
            $sessionId,
            $subjectId,
            $termId,
            $adminId
        );
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        mysqli_commit($conn);
        $transactionStarted = false;
        set_flash('success', 'Teacher allocated to the selected class, subject, and term.');
    } elseif ($action === 'allocate_class_teacher') {
        $teacherId = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT);
        $classId = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT);
        $termId = filter_var($_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$teacherId || $teacherId < 1 || !$classId || $classId < 1
            || !$termId || $termId < 1) {
            throw new InvalidArgumentException('Select a teacher, class, and academic term.');
        }

        $teacherCheck = mysqli_prepare(
            $conn,
            "SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1"
        );
        mysqli_stmt_bind_param($teacherCheck, 'i', $teacherId);
        mysqli_stmt_execute($teacherCheck);
        $teacherExists = mysqli_fetch_assoc(mysqli_stmt_get_result($teacherCheck));
        mysqli_stmt_close($teacherCheck);
        if (!$teacherExists) {
            throw new InvalidArgumentException('The selected teacher account is not active.');
        }

        $termCheck = mysqli_prepare(
            $conn,
            'SELECT academic_session_id FROM academic_terms WHERE id = ?'
        );
        mysqli_stmt_bind_param($termCheck, 'i', $termId);
        mysqli_stmt_execute($termCheck);
        $term = mysqli_fetch_assoc(mysqli_stmt_get_result($termCheck));
        mysqli_stmt_close($termCheck);
        if (!$term) {
            throw new InvalidArgumentException('The selected academic term was not found.');
        }
        $sessionId = (int) $term['academic_session_id'];

        $existingAssignmentQuery = mysqli_prepare(
            $conn,
            'SELECT teacher_id
             FROM class_teacher_allocations
             WHERE class_id = ? AND academic_session_id = ? AND term_id = ?
             LIMIT 1'
        );
        mysqli_stmt_bind_param(
            $existingAssignmentQuery,
            'iii',
            $classId,
            $sessionId,
            $termId
        );
        mysqli_stmt_execute($existingAssignmentQuery);
        $existingAssignment = mysqli_fetch_assoc(mysqli_stmt_get_result($existingAssignmentQuery));
        mysqli_stmt_close($existingAssignmentQuery);
        if ($existingAssignment) {
            throw new InvalidArgumentException(
                'This class already has a class teacher assigned for the selected term.'
            );
        }

        $statement = mysqli_prepare(
            $conn,
            'INSERT INTO class_teacher_allocations
                (teacher_id, class_id, academic_session_id, term_id, allocated_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        mysqli_stmt_bind_param(
            $statement,
            'iiiii',
            $teacherId,
            $classId,
            $sessionId,
            $termId,
            $adminId
        );
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        set_flash('success', 'Class teacher assigned to the selected class and term.');
    } elseif ($action === 'create_term') {
        $termInput = $_POST['term_name'] ?? '';
        $termName = is_string($termInput) ? trim($termInput) : '';
        $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
        $startInput = $_POST['starts_on'] ?? '';
        $endInput = $_POST['ends_on'] ?? '';
        $startsOn = is_string($startInput) ? $startInput : '';
        $endsOn = is_string($endInput) ? $endInput : '';

        if ($termName === '' || strlen($termName) > 100 || !$sessionId || $sessionId < 1
            || !valid_academic_date($startsOn) || !valid_academic_date($endsOn)
            || $startsOn > $endsOn) {
            throw new InvalidArgumentException('Enter a term name, select a session, and provide a valid date range.');
        }

        $sessionQuery = mysqli_prepare(
            $conn,
            'SELECT starts_on, ends_on FROM academic_sessions WHERE id = ?'
        );
        mysqli_stmt_bind_param($sessionQuery, 'i', $sessionId);
        mysqli_stmt_execute($sessionQuery);
        $sessionDates = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionQuery));
        mysqli_stmt_close($sessionQuery);
        if (!$sessionDates
            || $startsOn < $sessionDates['starts_on']
            || $endsOn > $sessionDates['ends_on']) {
            throw new InvalidArgumentException('The term dates must fall within the selected academic session.');
        }

        $statement = mysqli_prepare(
            $conn,
            'INSERT INTO academic_terms (academic_session_id, term_name, starts_on, ends_on)
             VALUES (?, ?, ?, ?)'
        );
        mysqli_stmt_bind_param($statement, 'isss', $sessionId, $termName, $startsOn, $endsOn);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        set_flash('success', 'Academic term created for the selected session.');
    } else {
        throw new InvalidArgumentException('Unknown academic management action.');
    }
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
            $transactionStarted = false;
        } catch (mysqli_sql_exception $rollbackException) {
            error_log('Academic management rollback failed: ' . $rollbackException->getMessage());
        }
    }

    if ($exception->getCode() === 1062) {
        $duplicateMessage = match ($action) {
            'allocate_teacher' => 'A teacher is already allocated to this class and subject for the selected term.',
            'allocate_class_teacher' => 'This class already has a class teacher assigned for the selected term.',
            default => 'That record or allocation already exists.',
        };
        set_flash('error', $duplicateMessage);
    } elseif ($exception->getCode() === 1451) {
        set_flash('error', 'The session still has linked records and cannot be deleted.');
    } else {
        error_log('Academic management request failed: ' . $exception->getMessage());
        set_flash('error', 'The requested change could not be saved.');
    }
}

header('Location: dashboard.php?page=' . rawurlencode($returnPage));
exit;
