<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/promotion.php';
require_role('admin');

function teacher_activity_browser_label(string $userAgent): string
{
    $browserPatterns = [
        'Microsoft Edge' => '/(?:Edg|Edge)\/([\d.]+)/',
        'Opera' => '/(?:OPR|Opera)\/([\d.]+)/',
        'Firefox' => '/(?:Firefox|FxiOS)\/([\d.]+)/',
        'Chrome' => '/(?:Chrome|CriOS)\/([\d.]+)/',
        'Safari' => '/Version\/([\d.]+).*Safari/',
    ];

    foreach ($browserPatterns as $browserName => $pattern) {
        if (preg_match($pattern, $userAgent, $matches) === 1) {
            return $browserName . ' ' . $matches[1];
        }
    }

    return $userAgent === '' ? 'Unknown' : 'Other browser';
}

$pageTitles = [
    'overview' => '',
    'academic' => '',
    'classes' => '',
    'subjects' => '',
    'student-records' => '',
    'teachers' => '',
    'teacher-allocation' => '',
    'class-teachers' => '',
    'allocations' => '',
    'results' => 'Results',
    'summary' => 'Summary sheets',
    'score-resets' => 'Score edit approvals',
    'activity-logs' => 'Login & upload logs',
    'promotion' => 'Promotion',
];
$pageDescriptions = [
    'overview' => '',
    'academic' => '',
    'classes' => '',
    'subjects' => '',
    'student-records' => '',
    'teachers' => '',
    'teacher-allocation' => '',
    'class-teachers' => '',
    'allocations' => '',
    'results' => '',
    'summary' => '',
    'score-resets' => '',
    'activity-logs' => '',
    'promotion' => '',
];
$requestedPage = $_GET['page'] ?? 'overview';
$activePage = is_string($requestedPage)
    ? ($requestedPage === 'students' ? 'student-records' : $requestedPage)
    : 'overview';
if (!array_key_exists($activePage, $pageTitles)) {
    $activePage = 'overview';
}
$navItemClasses = 'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition';
$activeNavClasses = 'bg-emerald-50 font-semibold text-emerald-800 ring-1 ring-emerald-100';
$inactiveNavClasses = 'text-slate-600 hover:bg-slate-50 hover:text-slate-950';

$classesQuery = mysqli_prepare($conn, 'SELECT id, class_name FROM classes ORDER BY class_name');
mysqli_stmt_execute($classesQuery);
$classes = mysqli_fetch_all(mysqli_stmt_get_result($classesQuery), MYSQLI_ASSOC);
mysqli_stmt_close($classesQuery);

$sessionsQuery = mysqli_prepare(
    $conn,
    'SELECT id, session_name, starts_on, ends_on, is_active
     FROM academic_sessions
     ORDER BY starts_on DESC'
);
mysqli_stmt_execute($sessionsQuery);
$sessions = mysqli_fetch_all(mysqli_stmt_get_result($sessionsQuery), MYSQLI_ASSOC);
mysqli_stmt_close($sessionsQuery);

$teachersQuery = mysqli_prepare(
    $conn,
    "SELECT id, fullname, phone_No FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY fullname"
);
mysqli_stmt_execute($teachersQuery);
$teachers = mysqli_fetch_all(mysqli_stmt_get_result($teachersQuery), MYSQLI_ASSOC);
mysqli_stmt_close($teachersQuery);

$subjectsQuery = mysqli_prepare($conn, 'SELECT id, subject_name FROM subjects ORDER BY subject_name');
mysqli_stmt_execute($subjectsQuery);
$subjects = mysqli_fetch_all(mysqli_stmt_get_result($subjectsQuery), MYSQLI_ASSOC);
mysqli_stmt_close($subjectsQuery);

$termsQuery = mysqli_prepare(
    $conn,
    'SELECT t.id, t.term_name, t.academic_session_id, a.session_name
     FROM academic_terms t
     INNER JOIN academic_sessions a ON a.id = t.academic_session_id
     ORDER BY a.starts_on DESC, t.starts_on'
);
mysqli_stmt_execute($termsQuery);
$terms = mysqli_fetch_all(mysqli_stmt_get_result($termsQuery), MYSQLI_ASSOC);
mysqli_stmt_close($termsQuery);

$activeSession = null;
foreach ($sessions as $session) {
    if ((int) $session['is_active'] === 1) {
        $activeSession = $session;
        break;
    }
}

$sessionFilterProvided = array_key_exists('session_id', $_GET);
$sessionFilterInput = $_GET['session_id'] ?? null;
$selectedSessionId = $activeSession !== null ? (int) $activeSession['id'] : null;
if ($sessionFilterProvided && is_string($sessionFilterInput) && $sessionFilterInput === 'all') {
    $selectedSessionId = null;
} elseif ($sessionFilterProvided) {
    $requestedSessionId = filter_var($sessionFilterInput, FILTER_VALIDATE_INT);
    $knownSessionIds = array_map('intval', array_column($sessions, 'id'));
    $selectedSessionId = is_int($requestedSessionId)
        && $requestedSessionId > 0
        && in_array($requestedSessionId, $knownSessionIds, true)
        ? $requestedSessionId
        : ($activeSession !== null ? (int) $activeSession['id'] : null);
}

$selectedTermId = null;
$termFilterInput = filter_var($_GET['term_id'] ?? null, FILTER_VALIDATE_INT);
if (is_int($termFilterInput) && $termFilterInput > 0) {
    foreach ($terms as $term) {
        if ((int) $term['id'] === $termFilterInput) {
            $termSessionId = (int) $term['academic_session_id'];
            if (!$sessionFilterProvided || $selectedSessionId === null || $selectedSessionId === $termSessionId) {
                $selectedTermId = $termFilterInput;
                $selectedSessionId = $termSessionId;
            }
            break;
        }
    }
}
$selectedClassId = null;
$classFilterInput = filter_var($_GET['class_id'] ?? null, FILTER_VALIDATE_INT);
if (is_int($classFilterInput) && $classFilterInput > 0) {
    foreach ($classes as $class) {
        if ((int) $class['id'] === $classFilterInput) {
            $selectedClassId = $classFilterInput;
            break;
        }
    }
}
$studentAssignmentsSql =
    'SELECT sc.student_id, sc.class_id, sc.academic_session_id,
            s.fullname, c.class_name, a.session_name
     FROM student_class_allocations sc
     INNER JOIN students s ON s.id = sc.student_id
     INNER JOIN classes c ON c.id = sc.class_id
     INNER JOIN academic_sessions a ON a.id = sc.academic_session_id';
if ($selectedSessionId !== null) {
    $studentAssignmentsSql .= ' WHERE sc.academic_session_id = ?';
}
$studentAssignmentsSql .= ' ORDER BY a.starts_on DESC, c.class_name, s.fullname LIMIT 500';
$studentAssignmentsQuery = mysqli_prepare($conn, $studentAssignmentsSql);
if ($selectedSessionId !== null) {
    mysqli_stmt_bind_param($studentAssignmentsQuery, 'i', $selectedSessionId);
}
mysqli_stmt_execute($studentAssignmentsQuery);
$studentAssignments = mysqli_fetch_all(mysqli_stmt_get_result($studentAssignmentsQuery), MYSQLI_ASSOC);
mysqli_stmt_close($studentAssignmentsQuery);

$teacherAllocationsSql =
    'SELECT tc.teacher_id, tc.class_id, tc.academic_session_id,
            tc.subject_id, tc.term_id, u.fullname AS teacher_name,
            c.class_name, sub.subject_name, t.term_name, a.session_name
     FROM teacher_class_allocations tc
     INNER JOIN users u ON u.id = tc.teacher_id
     INNER JOIN classes c ON c.id = tc.class_id
     INNER JOIN academic_sessions a ON a.id = tc.academic_session_id
     INNER JOIN subjects sub ON sub.id = tc.subject_id
     INNER JOIN academic_terms t ON t.id = tc.term_id';
if ($selectedTermId !== null) {
    $teacherAllocationsSql .= ' WHERE tc.term_id = ?';
} elseif ($selectedSessionId !== null) {
    $teacherAllocationsSql .= ' WHERE tc.academic_session_id = ?';
}
$teacherAllocationsSql .= ' ORDER BY a.starts_on DESC, t.starts_on, c.class_name, sub.subject_name, u.fullname LIMIT 500';
$teacherAllocationsQuery = mysqli_prepare($conn, $teacherAllocationsSql);
if ($selectedTermId !== null) {
    mysqli_stmt_bind_param($teacherAllocationsQuery, 'i', $selectedTermId);
} elseif ($selectedSessionId !== null) {
    mysqli_stmt_bind_param($teacherAllocationsQuery, 'i', $selectedSessionId);
}
mysqli_stmt_execute($teacherAllocationsQuery);
$teacherAllocations = mysqli_fetch_all(mysqli_stmt_get_result($teacherAllocationsQuery), MYSQLI_ASSOC);
mysqli_stmt_close($teacherAllocationsQuery);

$classTeacherAllocationsSql =
    'SELECT ct.teacher_id, ct.class_id, ct.academic_session_id, ct.term_id,
            u.fullname AS teacher_name, c.class_name, t.term_name, a.session_name
     FROM class_teacher_allocations ct
     INNER JOIN users u ON u.id = ct.teacher_id
     INNER JOIN classes c ON c.id = ct.class_id
     INNER JOIN academic_sessions a ON a.id = ct.academic_session_id
     INNER JOIN academic_terms t ON t.id = ct.term_id';
if ($selectedTermId !== null) {
    $classTeacherAllocationsSql .= ' WHERE ct.term_id = ?';
} elseif ($selectedSessionId !== null) {
    $classTeacherAllocationsSql .= ' WHERE ct.academic_session_id = ?';
}
$classTeacherAllocationsSql .= ' ORDER BY a.starts_on DESC, t.starts_on, c.class_name, u.fullname LIMIT 500';
$classTeacherAllocationsQuery = mysqli_prepare($conn, $classTeacherAllocationsSql);
if ($selectedTermId !== null) {
    mysqli_stmt_bind_param($classTeacherAllocationsQuery, 'i', $selectedTermId);
} elseif ($selectedSessionId !== null) {
    mysqli_stmt_bind_param($classTeacherAllocationsQuery, 'i', $selectedSessionId);
}
mysqli_stmt_execute($classTeacherAllocationsQuery);
$classTeacherAllocations = mysqli_fetch_all(mysqli_stmt_get_result($classTeacherAllocationsQuery), MYSQLI_ASSOC);
mysqli_stmt_close($classTeacherAllocationsQuery);

$resultsFilterConditions = [];
$resultFilterTypes = '';
$resultFilterParams = [];
if ($selectedTermId !== null) {
    $resultsFilterConditions[] = 'r.term_id = ?';
    $resultFilterTypes .= 'i';
    $resultFilterParams[] = &$selectedTermId;
} elseif ($selectedSessionId !== null) {
    $resultsFilterConditions[] = 'r.academic_session_id = ?';
    $resultFilterTypes .= 'i';
    $resultFilterParams[] = &$selectedSessionId;
}
if ($selectedClassId !== null) {
    $resultsFilterConditions[] = 'r.class_id = ?';
    $resultFilterTypes .= 'i';
    $resultFilterParams[] = &$selectedClassId;
}
$resultsFilterSql = $resultsFilterConditions
    ? ' WHERE ' . implode(' AND ', $resultsFilterConditions)
    : '';
$bindResultFilters = static function (mysqli_stmt $statement) use (
    &$resultFilterTypes,
    &$resultFilterParams
): void {
    if ($resultFilterParams !== []) {
        mysqli_stmt_bind_param($statement, $resultFilterTypes, ...$resultFilterParams);
    }
};
$countQuery = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM results r' . $resultsFilterSql);
$bindResultFilters($countQuery);
mysqli_stmt_execute($countQuery);
$resultCountRow = mysqli_fetch_assoc(mysqli_stmt_get_result($countQuery));
$resultCount = (int) $resultCountRow['total'];
mysqli_stmt_close($countQuery);

$activeStudentCount = 0;
if ($activeSession !== null) {
    $studentCountQuery = mysqli_prepare(
        $conn,
        'SELECT COUNT(DISTINCT student_id) AS total
         FROM student_class_allocations
         WHERE academic_session_id = ?'
    );
    $activeSessionId = (int) $activeSession['id'];
    mysqli_stmt_bind_param($studentCountQuery, 'i', $activeSessionId);
    mysqli_stmt_execute($studentCountQuery);
    $studentCountRow = mysqli_fetch_assoc(mysqli_stmt_get_result($studentCountQuery));
    $activeStudentCount = (int) $studentCountRow['total'];
    mysqli_stmt_close($studentCountQuery);
}

$previousSession = null;
$repeatedStudentCount = 0;
$promotedStudentCount = 0;
$classProgression = ['ss1', 'ss2', 'ss3'];
$classRanksById = [];
foreach ($classes as $class) {
    $normalizedClassName = strtolower((string) preg_replace('/[\s_-]+/', '', (string) $class['class_name']));
    $classRank = array_search($normalizedClassName, $classProgression, true);
    if ($classRank !== false) {
        $classRanksById[(int) $class['id']] = $classRank;
    }
}
if ($activeSession !== null) {
    foreach ($sessions as $session) {
        if ($session['starts_on'] < $activeSession['starts_on']) {
            $previousSession = $session;
            break;
        }
    }
}
if ($activeSession !== null && $previousSession !== null) {
    $studentProgressQuery = mysqli_prepare(
        $conn,
        'SELECT previous_allocation.class_id AS previous_class_id,
                current_allocation.class_id AS current_class_id
         FROM student_class_allocations previous_allocation
         INNER JOIN student_class_allocations current_allocation
            ON current_allocation.student_id = previous_allocation.student_id
         WHERE previous_allocation.academic_session_id = ?
           AND current_allocation.academic_session_id = ?'
    );
    $activeSessionId = (int) $activeSession['id'];
    $previousSessionId = (int) $previousSession['id'];
    mysqli_stmt_bind_param($studentProgressQuery, 'ii', $previousSessionId, $activeSessionId);
    mysqli_stmt_execute($studentProgressQuery);
    $studentProgress = mysqli_stmt_get_result($studentProgressQuery);
    while ($student = mysqli_fetch_assoc($studentProgress)) {
        $previousClassId = (int) $student['previous_class_id'];
        $currentClassId = (int) $student['current_class_id'];
        if ($previousClassId === $currentClassId) {
            $repeatedStudentCount++;
        }
        if (isset($classRanksById[$previousClassId], $classRanksById[$currentClassId])
            && $classRanksById[$currentClassId] === $classRanksById[$previousClassId] + 1) {
            $promotedStudentCount++;
        }
    }
    mysqli_stmt_close($studentProgressQuery);
}

$resultsQuery = mysqli_prepare(
    $conn,
    'SELECT r.student_id, r.term_id, r.first_test, r.second_test, r.exam, r.score, r.created_at,
            s.fullname AS student_name, c.class_name,
            sub.subject_name, t.term_name, a.session_name, u.fullname AS teacher_name,
            class_teachers.teacher_names AS class_teacher_names
     FROM results r
     INNER JOIN students s ON s.id = r.student_id
     INNER JOIN classes c ON c.id = r.class_id
     INNER JOIN subjects sub ON sub.id = r.subject_id
     INNER JOIN academic_terms t ON t.id = r.term_id
     INNER JOIN academic_sessions a ON a.id = r.academic_session_id
     INNER JOIN users u ON u.id = r.teacher_id
     LEFT JOIN (
         SELECT ct.class_id, ct.academic_session_id, ct.term_id,
                GROUP_CONCAT(class_teacher.fullname ORDER BY class_teacher.fullname SEPARATOR ", ") AS teacher_names
         FROM class_teacher_allocations ct
         INNER JOIN users class_teacher ON class_teacher.id = ct.teacher_id
         GROUP BY ct.class_id, ct.academic_session_id, ct.term_id
     ) class_teachers
         ON class_teachers.class_id = r.class_id
         AND class_teachers.academic_session_id = r.academic_session_id
         AND class_teachers.term_id = r.term_id
     ' . $resultsFilterSql . '
     ORDER BY r.created_at DESC
     LIMIT 500'
);
$bindResultFilters($resultsQuery);
mysqli_stmt_execute($resultsQuery);
$results = mysqli_fetch_all(mysqli_stmt_get_result($resultsQuery), MYSQLI_ASSOC);
mysqli_stmt_close($resultsQuery);

$summarySessionId = $selectedSessionId;
if ($summarySessionId === null && $activeSession !== null) {
    $summarySessionId = (int) $activeSession['id'];
} elseif ($summarySessionId === null && $sessions) {
    $summarySessionId = (int) $sessions[0]['id'];
}
$summaryClassId = $selectedClassId;
$summaryTerms = [];
$summaryStudentCount = 0;
if ($activePage === 'summary' && $summarySessionId !== null) {
    if ($summaryClassId !== null) {
        $summaryTermsQuery = mysqli_prepare(
            $conn,
            'SELECT t.id, t.term_name, t.starts_on,
                    COUNT(r.id) AS score_count,
                    COUNT(DISTINCT r.student_id) AS scored_students,
                    COUNT(DISTINCT r.subject_id) AS scored_subjects
             FROM academic_terms t
             LEFT JOIN results r
                ON r.term_id = t.id AND r.class_id = ?
             WHERE t.academic_session_id = ?
             GROUP BY t.id, t.term_name, t.starts_on
             ORDER BY t.starts_on, t.id
             LIMIT 3'
        );
        mysqli_stmt_bind_param($summaryTermsQuery, 'ii', $summaryClassId, $summarySessionId);
        mysqli_stmt_execute($summaryTermsQuery);
        $summaryTerms = mysqli_fetch_all(mysqli_stmt_get_result($summaryTermsQuery), MYSQLI_ASSOC);
        mysqli_stmt_close($summaryTermsQuery);

        $summaryStudentCountQuery = mysqli_prepare(
            $conn,
            'SELECT COUNT(*) AS total
             FROM student_class_allocations
             WHERE class_id = ? AND academic_session_id = ?'
        );
        mysqli_stmt_bind_param($summaryStudentCountQuery, 'ii', $summaryClassId, $summarySessionId);
        mysqli_stmt_execute($summaryStudentCountQuery);
        $summaryStudentCountRow = mysqli_fetch_assoc(mysqli_stmt_get_result($summaryStudentCountQuery));
        $summaryStudentCount = (int) $summaryStudentCountRow['total'];
        mysqli_stmt_close($summaryStudentCountQuery);
    } else {
        $summaryTermsQuery = mysqli_prepare(
            $conn,
            'SELECT id, term_name, starts_on, 0 AS score_count,
                    0 AS scored_students, 0 AS scored_subjects
             FROM academic_terms
             WHERE academic_session_id = ?
             ORDER BY starts_on, id
             LIMIT 3'
        );
        mysqli_stmt_bind_param($summaryTermsQuery, 'i', $summarySessionId);
        mysqli_stmt_execute($summaryTermsQuery);
        $summaryTerms = mysqli_fetch_all(mysqli_stmt_get_result($summaryTermsQuery), MYSQLI_ASSOC);
        mysqli_stmt_close($summaryTermsQuery);
    }
}

$teacherLoginLogs = [];
$teacherUploadLogs = [];
$teacherUploadSummary = [];
$scoreEditAssignments = [];
if ($activePage === 'activity-logs') {
    $loginLogsQuery = mysqli_prepare(
        $conn,
        "SELECT teacher_name, DATE_FORMAT(created_at, '%d %b %Y') AS activity_date,
                DATE_FORMAT(created_at, '%H:%i:%s') AS activity_time,
                ip_address, user_agent
         FROM teacher_activity_logs
         WHERE event_type = 'login'
         ORDER BY created_at DESC, id DESC
         LIMIT 500"
    );
    mysqli_stmt_execute($loginLogsQuery);
    $teacherLoginLogs = mysqli_fetch_all(mysqli_stmt_get_result($loginLogsQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($loginLogsQuery);

    $uploadLogsQuery = mysqli_prepare(
        $conn,
        "SELECT teacher_name, class_name, subject_name, term_name, session_name,
                student_entry_count,
                DATE_FORMAT(created_at, '%d %b %Y') AS activity_date,
                DATE_FORMAT(created_at, '%H:%i:%s') AS activity_time,
                ip_address, user_agent
         FROM teacher_activity_logs
         WHERE event_type = 'score_upload'
         ORDER BY created_at DESC, id DESC
         LIMIT 500"
    );
    mysqli_stmt_execute($uploadLogsQuery);
    $teacherUploadLogs = mysqli_fetch_all(mysqli_stmt_get_result($uploadLogsQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($uploadLogsQuery);

    $uploadSummaryQuery = mysqli_prepare(
        $conn,
        "SELECT latest.teacher_id, latest.teacher_name, totals.upload_count,
                totals.student_entry_count,
                DATE_FORMAT(totals.last_upload_at, '%d %b %Y') AS last_upload_date,
                DATE_FORMAT(totals.last_upload_at, '%H:%i:%s') AS last_upload_time
         FROM (
             SELECT teacher_id, MAX(id) AS latest_id, COUNT(*) AS upload_count,
                    SUM(student_entry_count) AS student_entry_count,
                    MAX(created_at) AS last_upload_at
             FROM teacher_activity_logs
             WHERE event_type = 'score_upload'
             GROUP BY teacher_id
         ) totals
         INNER JOIN teacher_activity_logs latest ON latest.id = totals.latest_id
         ORDER BY totals.last_upload_at DESC, latest.teacher_name"
    );
    mysqli_stmt_execute($uploadSummaryQuery);
    $teacherUploadSummary = mysqli_fetch_all(mysqli_stmt_get_result($uploadSummaryQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($uploadSummaryQuery);
}
if ($activePage === 'score-resets') {
    $scoreEditAssignmentsQuery = mysqli_prepare(
        $conn,
        'SELECT r.teacher_id, r.class_id, r.academic_session_id, r.subject_id, r.term_id,
                u.fullname AS teacher_name, c.class_name, sub.subject_name,
                t.term_name, a.session_name, COUNT(DISTINCT r.student_id) AS student_count,
                MAX(r.updated_at) AS latest_update,
                COALESCE(approval.edit_approved, 0) AS edit_approved
         FROM results r
         INNER JOIN users u ON u.id = r.teacher_id
         INNER JOIN classes c ON c.id = r.class_id
         INNER JOIN subjects sub ON sub.id = r.subject_id
         INNER JOIN academic_terms t ON t.id = r.term_id
         INNER JOIN academic_sessions a ON a.id = r.academic_session_id
         LEFT JOIN teacher_score_edit_approvals approval
             ON approval.teacher_id = r.teacher_id
             AND approval.class_id = r.class_id
             AND approval.academic_session_id = r.academic_session_id
             AND approval.subject_id = r.subject_id
             AND approval.term_id = r.term_id
         WHERE a.is_active = 1 AND t.is_active = 1
         GROUP BY r.teacher_id, r.class_id, r.academic_session_id, r.subject_id, r.term_id,
                  u.fullname, c.class_name, sub.subject_name, t.term_name, a.session_name,
                  approval.edit_approved
         ORDER BY edit_approved ASC, latest_update DESC
         LIMIT 500'
    );
    mysqli_stmt_execute($scoreEditAssignmentsQuery);
    $scoreEditAssignments = mysqli_fetch_all(mysqli_stmt_get_result($scoreEditAssignmentsQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($scoreEditAssignmentsQuery);
}

$promotionTargetSessions = [];
$selectedPromotionTargetId = null;
$promotionContext = null;
$promotionNotice = '';
$promotionActionableCount = 0;
if ($activePage === 'promotion') {
    if ($activeSession === null) {
        $promotionNotice = 'Set an active source session before reviewing promotions.';
    } else {
        foreach ($sessions as $session) {
            if ($session['starts_on'] > $activeSession['starts_on']) {
                $promotionTargetSessions[] = $session;
            }
        }
        usort($promotionTargetSessions, static function (array $left, array $right): int {
            $dateOrder = strcmp($left['starts_on'], $right['starts_on']);
            return $dateOrder !== 0 ? $dateOrder : ((int) $left['id'] <=> (int) $right['id']);
        });

        if (!$promotionTargetSessions) {
            $promotionNotice = 'Create a later academic session before promoting students.';
        } else {
            $targetSessionInput = $_GET['target_session_id'] ?? null;
            if ($targetSessionInput === null) {
                $selectedPromotionTargetId = (int) $promotionTargetSessions[0]['id'];
            } else {
                $requestedTargetSessionId = filter_var($targetSessionInput, FILTER_VALIDATE_INT);
                foreach ($promotionTargetSessions as $targetSession) {
                    if (is_int($requestedTargetSessionId)
                        && $requestedTargetSessionId === (int) $targetSession['id']) {
                        $selectedPromotionTargetId = $requestedTargetSessionId;
                        break;
                    }
                }
                if ($selectedPromotionTargetId === null) {
                    $promotionNotice = 'Select a valid later academic session as the promotion destination.';
                }
            }

            if ($selectedPromotionTargetId !== null) {
                try {
                    $promotionContext = get_student_promotion_context(
                        $conn,
                        (int) $activeSession['id'],
                        $selectedPromotionTargetId
                    );
                    foreach ($promotionContext['candidates'] as $candidate) {
                        if ($candidate['existing_class_id'] === null) {
                            $promotionActionableCount++;
                        }
                    }
                } catch (InvalidArgumentException $exception) {
                    $promotionNotice = $exception->getMessage();
                }
            }
        }
    }
}
$flash = take_flash();
?>
<!doctype html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GMMC (NUR-PRI) Yola | Admin Dashboard</title>
    <link href="../css/src/output.css" rel="stylesheet">
</head>
<body class="admin-dashboard min-h-screen bg-slate-50 text-slate-900 antialiased">
    <div id="sidebar-overlay" class="fixed inset-0 z-40 hidden bg-slate-950/50 backdrop-blur-sm lg:hidden"></div>
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col border-r border-slate-200 bg-white text-slate-900 shadow-xl shadow-slate-950/5 transition-transform duration-300 lg:translate-x-0">
        <div class="flex items-center gap-3 border-b border-slate-100 px-6 py-6">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-emerald-600 bg-white p-1 shadow-sm shadow-emerald-900/10">
                <img src="../assets/gmmc-logo1.png" alt="General Murtala Mohammed College Yola crest"
                     class="h-full w-full rounded-full object-contain">
            </div>
            <div>
                <p class="font-bold tracking-tight text-slate-950">GMMC (NUR-PRI) Yola</p>
                <p class="mt-0.5 text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700">EMIS Software</p>
            </div>
        </div>
        <nav class="flex-1 space-y-1 overflow-y-auto px-4 py-6" aria-label="Administrator navigation">
            <p class="px-3 pb-3 text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Workspace</p>
            <a href="?page=overview" class="<?= $navItemClasses ?> <?= $activePage === 'overview' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="8" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="15" width="7" height="6" rx="1.5"/></svg>
                Home
            </a>
            <a href="?page=academic" class="<?= $navItemClasses ?> <?= $activePage === 'academic' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                Academic setup
            </a>
            <a href="?page=classes" class="<?= $navItemClasses ?> <?= $activePage === 'classes' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
                Classes
            </a>
            <a href="?page=subjects" class="<?= $navItemClasses ?> <?= $activePage === 'subjects' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v18H6.5A2.5 2.5 0 0 1 4 18.5z"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>
                Subjects
            </a>
            <a href="?page=student-records" class="<?= $navItemClasses ?> <?= $activePage === 'student-records' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Student records
            </a>
            <a href="?page=teachers" class="<?= $navItemClasses ?> <?= $activePage === 'teachers' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/></svg>
                Teachers
            </a>
            <a href="?page=teacher-allocation" class="<?= $navItemClasses ?> <?= $activePage === 'teacher-allocation' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2M19 8h3m-1.5-1.5v3"/></svg>
                Teacher allocation
            </a>
            <a href="?page=class-teachers" class="<?= $navItemClasses ?> <?= $activePage === 'class-teachers' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M3 20v-1a6 6 0 0 1 12 0v1M16 5.5a3.5 3.5 0 0 1 0 6.8M18 15a5 5 0 0 1 3 4.5"/></svg>
                Class teachers
            </a>
            <a href="?page=allocations" class="<?= $navItemClasses ?> <?= $activePage === 'allocations' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/></svg>
                Allocations
            </a>
            <a href="?page=results" class="<?= $navItemClasses ?> <?= $activePage === 'results' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 19V5m0 14h17"/><path d="m7 15 4-4 3 2 6-7"/></svg>
                Results
            </a>
            <a href="?page=summary" class="<?= $navItemClasses ?> <?= $activePage === 'summary' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h4"/></svg>
                Summary sheets
            </a>
            <a href="?page=score-resets" class="<?= $navItemClasses ?> <?= $activePage === 'score-resets' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 7v5h-5"/><path d="M4 17v-5h5"/><path d="M5.6 9a7 7 0 0 1 11.6-2L20 12M4 12l2.8 5a7 7 0 0 0 11.6-2"/></svg>
                Score resets
            </a>
            <a href="?page=promotion" class="<?= $navItemClasses ?> <?= $activePage === 'promotion' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 19V5m-6 6 6-6 6 6"/><path d="M5 21h14"/></svg>
                Promotion
            </a>
            <a href="?page=activity-logs" class="<?= $navItemClasses ?> <?= $activePage === 'activity-logs' ? $activeNavClasses : $inactiveNavClasses ?>">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                Activity logs
            </a>
        </nav>
        <div class="m-4 rounded-2xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-teal-50 p-4">
            <div class="flex items-center gap-2">
                <span class="relative flex h-2.5 w-2.5">
                    <?php if ($activeSession): ?><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-50"></span><?php endif; ?>
                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full <?= $activeSession ? 'bg-emerald-500' : 'bg-slate-300' ?>"></span>
                </span>
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-emerald-800">Active session</p>
            </div>
            <!-- <p class="mt-3 text-base font-bold tracking-tight text-slate-900"><?= $activeSession ? e($activeSession['session_name']) : 'Not set' ?></p> -->
            <p class="mt-1 text-xs leading-5 text-slate-500"><?= $activeSession ? 'The school year currently in progress.' : 'Set up a session to get started.' ?></p>
        </div>
    </aside>

    <div class="min-h-screen lg:pl-72">
        <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/85 shadow-sm shadow-slate-950/[0.02] backdrop-blur-xl">
            <div class="flex h-[4.5rem] items-center justify-between gap-4 px-4 sm:px-6 lg:px-10">
                <div class="flex items-center gap-3">
                    <button id="sidebar-toggle" type="button" aria-label="Open navigation menu" aria-expanded="false"
                            class="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-600 shadow-sm transition hover:border-emerald-200 hover:text-emerald-700 lg:hidden">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <div class="hidden sm:block">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">GMMC Yola <span class="px-1 text-slate-300">
                    
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="hidden items-center gap-2 rounded-full border border-emerald-100 bg-emerald-50/80 px-3.5 py-2 text-xs font-semibold text-emerald-800 sm:inline-flex">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        <?= $activeSession ? 'Session ' . e($activeSession['session_name']) : 'No active session' ?>
                    </span>
                    <div class="hidden items-center gap-3 border-l border-slate-200 pl-4 sm:flex">
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold uppercase text-emerald-800 ring-4 ring-emerald-50">
                            <?= e(strtoupper(substr((string) $_SESSION['name'], 0, 1))) ?>
                        </div>
                        <div>
                            <p class="max-w-40 truncate text-sm font-semibold text-slate-900"><?= e((string) $_SESSION['name']) ?></p>
                            <p class="text-xs text-slate-500">Administrator</p>
                        </div>
                    </div>
                    <form method="post" action="../auth/logout.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="session_role" value="admin">
                        <button class="rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-600 shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700">Sign out</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="w-full px-4 py-7 sm:px-6 lg:px-10 lg:py-9">
            <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="mb-2 text-[11px] font-bold uppercase tracking-[0.2em] text-emerald-700">School administration</p>
                    <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl"><?= e($pageTitles[$activePage]) ?></h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500 sm:text-base"><?= e($pageDescriptions[$activePage]) ?></p>
                </div>
                <?php if ($activePage === 'overview'): ?>
                    <a href="?page=results" class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-900/15 transition hover:-translate-y-0.5 hover:bg-emerald-800">
                        Review results
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                <?php endif; ?>
            </div>
            <?php if ($activePage === 'overview'): ?>
    
                <div class="mb-6 overflow-hidden rounded-[1.75rem] bg-gradient-to-br from-emerald-950 via-emerald-900 to-teal-800 p-6 text-white shadow-xl shadow-emerald-950/10 sm:p-8">
                    <div class="flex flex-wrap items-end justify-between gap-6">
                        <div class="max-w-2xl">
                            <p class="text-sm font-bold text-gray-50">Welcome back, <?= e((string) $_SESSION['name']) ?></p>
                            <p class="mt-2 text-sm leading-6 text-emerald-100/80"></p>
                        </div>
                        <div class="rounded-2xl border border-white/15 bg-white/10 px-5 py-4 backdrop-blur">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-emerald-100/70">Current academic session</p>
                            <p class="mt-1.5 text-lg font-bold"><?= $activeSession ? e($activeSession['session_name']) : 'Not configured' ?></p>
                            <!-- <p class="mt-1 text-xs text-emerald-100/70"><?= $activeSession ? e($activeSession['starts_on'] . ' — ' . $activeSession['ends_on']) : 'Create a session to begin' ?></p> -->
                        </div>
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <!-- <article class="admin-stat-card group rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div><p class="text-sm font-medium text-slate-500">Results recorded</p><p class="mt-3 text-3xl font-bold tracking-tight text-slate-950"><?= number_format($resultCount) ?></p></div>
                            <span class="rounded-2xl bg-violet-50 p-3.5 text-violet-700 transition group-hover:scale-105"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/></svg></span>
                        </div>
                    </article> -->
                    <article class="admin-stat-card group rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div><p class="text-sm font-medium text-slate-500">Enrolled students</p><p class="mt-3 text-3xl font-bold tracking-tight text-slate-950"><?= number_format($activeStudentCount) ?></p></div>
                            <span class="rounded-2xl bg-sky-50 p-3.5 text-sky-700 transition group-hover:scale-105"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                        </div>
                    </article>
                    <article class="admin-stat-card group rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div><p class="text-sm font-medium text-slate-500">Active teachers</p><p class="mt-3 text-3xl font-bold tracking-tight text-slate-950"><?= number_format(count($teachers)) ?></p></div>
                            <span class="rounded-2xl bg-emerald-50 p-3.5 text-emerald-700 transition group-hover:scale-105"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/></svg></span>
                        </div>
                    </article>
                    <article class="admin-stat-card group rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div><p class="text-sm font-medium text-slate-500">Classes</p><p class="mt-3 text-3xl font-bold tracking-tight text-slate-950"><?= number_format(count($classes)) ?></p></div>
                            <span class="rounded-2xl bg-amber-50 p-3.5 text-amber-700 transition group-hover:scale-105"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg></span>
                        </div>
                    </article>
                    <article class="admin-stat-card group rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-sm font-medium text-slate-500">Repeated students</p>
                                <p class="mt-3 text-3xl font-bold tracking-tight text-slate-950"><?= number_format($repeatedStudentCount) ?></p>
                            </div>
                            <span class="rounded-2xl bg-amber-50 p-3.5 text-amber-700 transition group-hover:scale-105">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h13l-3-3M20 17H7l3 3"/><path d="M17 7a5 5 0 0 1 3 4M7 17a5 5 0 0 1-3-4"/></svg>
                            </span>
                        </div>
                        <p class="mt-3 text-xs text-slate-500"><?= $previousSession ? 'Same class in ' . e($previousSession['session_name']) . ' and ' . e($activeSession['session_name']) : '' ?></p>
                    </article>
                    <article class="admin-stat-card group rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-sm font-medium text-slate-500">Promoted students</p>
                                <p class="mt-3 text-3xl font-bold tracking-tight text-slate-950"><?= number_format($promotedStudentCount) ?></p>
                            </div>
                            <span class="rounded-2xl bg-teal-50 p-3.5 text-teal-700 transition group-hover:scale-105">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 19V5m-6 6 6-6 6 6"/><path d="M5 21h14"/></svg>
                            </span>
                        </div>
                        <p class="mt-3 text-xs text-slate-500"><?= $previousSession ? 'SS1 to SS2 to SS3 · from ' . e($previousSession['session_name']) : '' ?></p>
                    </article>
                </div>
            </section>
            <?php endif; ?>
            <?php if (in_array($activePage, ['academic', 'classes', 'subjects', 'student-records', 'teachers', 'teacher-allocation', 'class-teachers'], true)): ?>
        <section class="mb-8 w-full space-y-6">
            <?php if (in_array($activePage, ['academic', 'classes', 'subjects'], true)): ?>
            <div class="space-y-6">
                <?php if ($activePage === 'academic'): ?>
                <section id="academic" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h1 class="text-lg font-bold">Academic sessions</h1>
                    <form method="post" action="manage-academic.php" class="mt-4 grid gap-3 sm:grid-cols-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_session">
                        <input name="session_name" maxlength="50" required placeholder="e.g. 2026/2027"
                               class="rounded-lg border border-slate-300 px-3 py-2">
                        <input name="starts_on" type="date" required aria-label="Session start date"
                               class="rounded-lg border border-slate-300 px-3 py-2">
                        <input name="ends_on" type="date" required aria-label="Session end date"
                               class="rounded-lg border border-slate-300 px-3 py-2">
                        <button                         class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800 sm:col-span-3">Create session</button>
                    </form>
                    <div class="mt-4 space-y-2">
                        <?php foreach ($sessions as $session): ?>
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 px-4 py-3">
                                <div>
                                    <p class="font-medium"><?= e($session['session_name']) ?></p>
                                    <p class="mt-1 text-xs text-slate-500"><?= e($session['starts_on'] . ' to ' . $session['ends_on']) ?></p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <?php if ((int) $session['is_active'] === 1): ?>
                                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">Active</span>
                                    <?php else: ?>
                                        <form method="post" action="manage-academic.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="activate_session">
                                            <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                                            <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-white">Set active</button>
                                        </form>
                                    <?php endif; ?>
                                    <button type="button"
                                            data-edit-session
                                            data-session-id="<?= (int) $session['id'] ?>"
                                            data-session-name="<?= e($session['session_name']) ?>"
                                            data-starts-on="<?= e($session['starts_on']) ?>"
                                            data-ends-on="<?= e($session['ends_on']) ?>"
                                            class="rounded-lg border border-emerald-200 px-3 py-1.5 text-sm font-medium text-emerald-800 hover:bg-emerald-50">Edit</button>
                                    <form method="post" action="manage-academic.php" data-delete-session-form>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_session">
                                        <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                                        <button type="submit" class="rounded-lg border border-rose-200 px-3 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-50">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <dialog id="edit-session-dialog" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl border-0 p-0 shadow-xl backdrop:bg-slate-950/50">
                        <form method="post" action="manage-academic.php" class="space-y-4 p-6">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_session">
                            <input id="edit-session-id" type="hidden" name="session_id">
                            <div>
                                <h2 class="text-lg font-bold">Edit academic session</h2>
                                <p class="mt-1 text-sm text-slate-500">Existing terms must remain inside the updated date range.</p>
                            </div>
                            <div>
                                <label for="edit-session-name" class="mb-1 block text-sm font-medium text-slate-700">Session name</label>
                                <input id="edit-session-name" name="session_name" maxlength="50" required
                                       class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="edit-session-starts-on" class="mb-1 block text-sm font-medium text-slate-700">Start date</label>
                                    <input id="edit-session-starts-on" name="starts_on" type="date" required
                                           class="w-full rounded-lg border border-slate-300 px-3 py-2">
                                </div>
                                <div>
                                    <label for="edit-session-ends-on" class="mb-1 block text-sm font-medium text-slate-700">End date</label>
                                    <input id="edit-session-ends-on" name="ends_on" type="date" required
                                           class="w-full rounded-lg border border-slate-300 px-3 py-2">
                                </div>
                            </div>
                            <div class="flex justify-end gap-3 pt-2">
                                <button id="cancel-session-edit" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                                <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save changes</button>
                            </div>
                        </form>
                    </dialog>
                </section>
                <?php endif; ?>

                <?php if ($activePage === 'classes'): ?>
                <section id="classes" class="scroll-mt-24 grid gap-6 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.6fr)]">
                    <div class="h-fit rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div>
                            <h2 class="text-lg font-bold text-slate-950">Create a class</h2>
                        </div>
                        <form method="post" action="manage-academic.php" class="mt-5 space-y-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_class">
                            <label for="class-name" class="block text-sm font-semibold text-slate-700">Class name</label>
                            <input id="class-name" name="class_name" maxlength="100" required placeholder="e.g. NUR 1"
                                   class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
                            <button class="w-full rounded-lg bg-emerald-700 px-4 py-2.5 font-semibold text-white hover:bg-emerald-800">Add class</button>
                        </form>
                    </div>
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-6 py-5">
                            <div>
                                <h2 class="text-lg font-bold text-slate-950">Class records</h2>
                            </div>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-800"><?= count($classes) ?> <?= count($classes) === 1 ? 'class' : 'classes' ?></span>
                        </div>
                        <?php if (!$classes): ?>
                            <p class="p-6 text-sm text-slate-500">No classes have been created yet. Add the first class using the form.</p>
                        <?php else: ?>
                            <ul class="grid gap-3 p-5 sm:grid-cols-2 2xl:grid-cols-3">
                                <?php foreach ($classes as $classIndex => $class): ?>
                                    <li class="flex min-w-0 items-center gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-4 transition hover:border-emerald-200 hover:bg-emerald-50/50">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-sm font-bold text-emerald-800 shadow-sm ring-1 ring-slate-200">
                                            <?= str_pad((string) ($classIndex + 1), 2, '0', STR_PAD_LEFT) ?>
                                        </span>
                                        <span class="min-w-0 truncate text-sm font-semibold text-slate-800"><?= e($class['class_name']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ($activePage === 'subjects'): ?>
                <section id="subjects" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                   <h2 class="text-lg font-bold">Subjects</h2> 
                    <form method="post" action="manage-academic.php" class="mt-4 flex flex-wrap gap-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_subject">
                        <input name="subject_name" maxlength="150" required placeholder="Subject name"
                               class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2">
                        <button                         class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Add subject</button>
                    </form>
                    <?php if (!$subjects): ?>
                        <p class="mt-4 text-sm text-slate-600">No subjects created yet.</p>
                    <?php else: ?>
                        <div class="mt-5 overflow-x-auto rounded-xl border border-slate-200">
                            <table aria-label="Subject records" class="w-full text-left text-sm">
                                <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="border-b border-slate-200 px-4 py-3">Subject</th>
                                        <th class="border-b border-slate-200 px-4 py-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($subjects as $subject): ?>
                                        <tr class="hover:bg-slate-50">
                                            <td class="px-4 py-3 font-medium text-slate-900"><?= e($subject['subject_name']) ?></td>
                                            <td class="px-4 py-3">
                                                <button type="button" data-edit-subject
                                                        data-subject-id="<?= (int) $subject['id'] ?>"
                                                        data-subject-name="<?= e($subject['subject_name']) ?>"
                                                        class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">Edit</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
                <dialog id="edit-subject-dialog" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl border-0 p-0 shadow-xl backdrop:bg-slate-950/50">
                    <form method="post" action="manage-academic.php" class="space-y-4 p-6">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_subject">
                        <input id="edit-subject-id" type="hidden" name="subject_id">
                        <h2 class="text-lg font-bold">Edit subject</h2>
                        <div>
                            <label for="edit-subject-name" class="mb-1 block text-sm font-medium text-slate-700">Subject name</label>
                            <input id="edit-subject-name" name="subject_name" maxlength="150" required
                                   class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" data-close-dialog class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">Cancel</button>
                            <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save</button>
                        </div>
                    </form>
                </dialog>
                <?php endif; ?>

                <?php if ($activePage === 'academic'): ?>
                <section id="terms" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-bold">Academic terms</h2>
                    <form method="post" action="manage-academic.php" class="mt-4 grid gap-3 sm:grid-cols-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_term">
                        <input name="term_name" maxlength="100" required placeholder="e.g. First Term"
                               class="rounded-lg border border-slate-300 px-3 py-2">
                        <select id="term-session-id" name="session_id" required class="rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Select session</option>
                            <?php foreach ($sessions as $session): ?>
                                <option value="<?= (int) $session['id'] ?>"
                                        data-starts-on="<?= e($session['starts_on']) ?>"
                                        data-ends-on="<?= e($session['ends_on']) ?>"><?= e($session['session_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input id="term-starts-on" name="starts_on" type="date" required aria-label="Term start date"
                               class="rounded-lg border border-slate-300 px-3 py-2">
                        <input id="term-ends-on" name="ends_on" type="date" required aria-label="Term end date"
                               class="rounded-lg border border-slate-300 px-3 py-2">
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800 sm:col-span-2">Create active term</button>
                    </form>
                </section>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (in_array($activePage, ['student-records', 'teachers', 'teacher-allocation', 'class-teachers'], true)): ?>
            <div class="space-y-6">
                <?php if ($activePage === 'student-records'): ?>
                <div class="grid gap-6 xl:grid-cols-2">
                <section id="student-records" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-bold">Add student</h2>
                    <form method="post" action="manage-academic.php" class="mt-4 space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_student">
                        <input name="fullname" maxlength="200" required placeholder="Student full name"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        <select name="class_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Select class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>"><?= e($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="session_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Select academic session</option>
                            <?php foreach ($sessions as $session): ?>
                                <option value="<?= (int) $session['id'] ?>" <?= $selectedSessionId === (int) $session['id'] ? 'selected' : '' ?>><?= e($session['session_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="w-full rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Add student</button>
                    </form>
                </section>
                <section id="student-csv-import" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-bold">Import students from CSV</h2>
                    <p class="mt-1 text-sm text-slate-500">CSV header: <code>fullname</code> </p>
                    <form method="post" action="manage-records.php" enctype="multipart/form-data" class="mt-4 space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="import_students">
                        <label for="student-csv-class" class="block text-sm font-medium text-slate-700">Class</label>
                        <select id="student-csv-class" name="class_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Select class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>"><?= e($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="student-csv-session" class="block text-sm font-medium text-slate-700">Academic session</label>
                        <select id="student-csv-session" name="session_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Select academic session</option>
                            <?php foreach ($sessions as $session): ?>
                                <option value="<?= (int) $session['id'] ?>" <?= $selectedSessionId === (int) $session['id'] ? 'selected' : '' ?>><?= e($session['session_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="student-csv-file" class="block text-sm font-medium text-slate-700">CSV file</label>
                        <input id="student-csv-file" name="student_csv" type="file" accept=".csv,text/csv" required
                               class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        <button class="w-full rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Import students</button>
                    </form>
                </section>
                </div>
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <form method="get" action="dashboard.php" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <input type="hidden" name="page" value="student-records">
                        <div>
                            <label for="student-allocation-session" class="mb-1 block text-xs font-semibold text-slate-600">Academic session</label>
                            <select id="student-allocation-session" name="session_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="all" <?= $selectedSessionId === null ? 'selected' : '' ?>>All sessions</option>
                                <?php foreach ($sessions as $session): ?>
                                    <option value="<?= (int) $session['id'] ?>" <?= $selectedSessionId === (int) $session['id'] ? 'selected' : '' ?>><?= e($session['session_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="student-allocation-term" class="mb-1 block text-xs font-semibold text-slate-600">Term</label>
                            <select id="student-allocation-term" name="term_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="">All terms</option>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= (int) $term['id'] ?>" data-session-id="<?= (int) $term['academic_session_id'] ?>" <?= $selectedTermId === (int) $term['id'] ? 'selected' : '' ?>>
                                        <?= e($term['term_name'] . ' — ' . $term['session_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Show allocations</button>
                    </form>
                    <div class="border-b border-slate-200 px-6 py-4">
                        <h2 class="font-bold">Student class allocations</h2>
                    </div>
                    <div class="max-h-96 overflow-auto">
                        <?php if (!$studentAssignments): ?>
                            <p class="p-6 text-sm text-slate-600">No students assigned yet.</p>
                        <?php else: ?>
                            <table aria-label="Student enrollments" class="w-full min-w-[48rem] text-left text-sm">
                                <thead class="sticky top-0 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="border-b border-slate-200 px-4 py-3">Student</th>
                                        <th class="border-b border-slate-200 px-4 py-3">Class</th>
                                        <th class="border-b border-slate-200 px-4 py-3">Academic session</th>
                                        <th class="border-b border-slate-200 px-4 py-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($studentAssignments as $assignment): ?>
                                        <tr class="transition odd:bg-white even:bg-slate-50/40 hover:bg-emerald-50/40">
                                            <td class="border-b border-slate-100 px-4 py-3 font-medium text-slate-900"><?= e($assignment['fullname']) ?></td>
                                            <td class="border-b border-slate-100 px-4 py-3 text-slate-600"><?= e($assignment['class_name']) ?></td>
                                            <td class="border-b border-slate-100 px-4 py-3 text-slate-600"><?= e($assignment['session_name']) ?></td>
                                            <td class="whitespace-nowrap border-b border-slate-100 px-4 py-3">
                                                <button type="button" data-edit-student
                                                        data-student-id="<?= (int) $assignment['student_id'] ?>"
                                                        data-fullname="<?= e($assignment['fullname']) ?>"
                                                        data-class-id="<?= (int) $assignment['class_id'] ?>"
                                                        data-session-id="<?= (int) $assignment['academic_session_id'] ?>"
                                                        class="font-semibold text-emerald-800 hover:text-emerald-950">Edit</button>
                                                <form method="post" action="manage-records.php" class="ml-3 inline" data-confirm-record-delete data-delete-label="this student enrollment">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_student_allocation">
                                                    <input type="hidden" name="student_id" value="<?= (int) $assignment['student_id'] ?>">
                                                    <input type="hidden" name="class_id" value="<?= (int) $assignment['class_id'] ?>">
                                                    <input type="hidden" name="session_id" value="<?= (int) $assignment['academic_session_id'] ?>">
                                                    <button class="font-semibold text-rose-700 hover:text-rose-900">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ($activePage === 'teachers'): ?>
                <section id="teachers" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-bold">Add a teacher</h2>
                    </div>
                    <form id="teacher-create-form" method="post" action="create-teacher.php" class="space-y-3">
                        <?= csrf_field() ?>
                        <input name="fullname" maxlength="150" required placeholder="Teacher full name"
                               class="w-full rounded-xl border border-slate-300 px-3 py-2.5 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-50">
                        <input name="phone_No" type="tel" maxlength="30" autocomplete="tel" required placeholder="Phone number"
                               class="w-full rounded-xl border border-slate-300 px-3 py-2.5 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-50">
                        <input id="teacher-password" name="password" type="password" minlength="8" autocomplete="new-password" required placeholder="Temporary password"
                               class="w-full rounded-xl border border-slate-300 px-3 py-2.5 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-50">
                        <p class="text-xs leading-5 text-slate-500">At least 8 characters with uppercase and lowercase letters, a number, and a special character.</p>
                        <button class="w-full rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800">Create Teacher Account</button>
                    </form>
                    <div class="mt-5 border-t border-slate-100 pt-4">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Active teachers · <?= count($teachers) ?></p>
                        <?php if (!$teachers): ?>
                            <p class="text-sm text-slate-500">No active teacher accounts yet.</p>
                        <?php else: ?>
                            <div class="max-h-96 overflow-auto rounded-xl border border-slate-200">
                                <table aria-label="Teacher records" class="w-full min-w-[36rem] text-left text-sm">
                                    <thead class="sticky top-0 bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th class="border-b border-slate-200 px-4 py-3">Teacher</th>
                                            <th class="border-b border-slate-200 px-4 py-3">Phone number</th>
                                            <th class="border-b border-slate-200 px-4 py-3">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($teachers as $teacher): ?>
                                        <tr class="transition hover:bg-slate-50">
                                            <td class="px-4 py-3 font-medium text-slate-900"><?= e($teacher['fullname']) ?></td>
                                            <td class="px-4 py-3 text-slate-600"><?= e($teacher['phone_No']) ?></td>
                                            <td class="whitespace-nowrap px-4 py-3">
                                            <button type="button" data-edit-teacher
                                                    data-teacher-id="<?= (int) $teacher['id'] ?>"
                                                    data-fullname="<?= e($teacher['fullname']) ?>"
                                                    data-phone="<?= e($teacher['phone_No']) ?>"
                                                    class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">Edit</button>
                                                <form method="post" action="manage-records.php" class="ml-3 inline" data-confirm-record-delete data-delete-label="this teacher account">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_teacher">
                                                    <input type="hidden" name="teacher_id" value="<?= (int) $teacher['id'] ?>">
                                                    <button class="text-sm font-semibold text-rose-700 hover:text-rose-900">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ($activePage === 'teacher-allocation'): ?>
                <section id="teacher-allocation" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-bold">Subject Allocation</h2>
                    <p class="mt-1 text-sm text-slate-500">Teachers may teach multiple subjects and classes in the same term. Each class and subject can have one assigned teacher per term.</p>
                    <form method="post" action="manage-academic.php" class="mt-4 space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="allocate_teacher">
                        <select name="teacher_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Select teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= (int) $teacher['id'] ?>"><?= e($teacher['fullname'] . ' — ' . $teacher['phone_No']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="class_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Select class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>"><?= e($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="subject_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Select subject</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= (int) $subject['id'] ?>"><?= e($subject['subject_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="teacher-allocation-session" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="all" <?= $selectedSessionId === null ? 'selected' : '' ?>>Select session</option>
                            <?php foreach ($sessions as $session): ?>
                                <option value="<?= (int) $session['id'] ?>" <?= $selectedSessionId === (int) $session['id'] ? 'selected' : '' ?>><?= e($session['session_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="teacher-allocation-term" name="term_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Select academic term</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= (int) $term['id'] ?>" data-session-id="<?= (int) $term['academic_session_id'] ?>" <?= $selectedTermId === (int) $term['id'] ? 'selected' : '' ?>>
                                    <?= e($term['term_name'] . ' — ' . $term['session_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="w-full rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Save teacher allocation</button>
                    </form>
                </section>
                <?php endif; ?>

                <?php if ($activePage === 'class-teachers'): ?>
                <section id="class-teachers" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-bold">Assign class teachers</h2>
                    <form method="post" action="manage-academic.php" class="mt-4 space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="allocate_class_teacher">
                        <select name="teacher_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Select teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= (int) $teacher['id'] ?>"><?= e($teacher['fullname'] . ' — ' . $teacher['phone_No']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="class_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Select class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>"><?= e($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="class-teacher-session" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="all" <?= $selectedSessionId === null ? 'selected' : '' ?>>Select session</option>
                            <?php foreach ($sessions as $session): ?>
                                <option value="<?= (int) $session['id'] ?>" <?= $selectedSessionId === (int) $session['id'] ? 'selected' : '' ?>><?= e($session['session_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="class-teacher-term" name="term_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Select academic term</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= (int) $term['id'] ?>" data-session-id="<?= (int) $term['academic_session_id'] ?>" <?= $selectedTermId === (int) $term['id'] ? 'selected' : '' ?>>
                                    <?= e($term['term_name'] . ' — ' . $term['session_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="w-full rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Save class teacher assignment</button>
                    </form>
                </section>
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <form method="get" action="dashboard.php" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <input type="hidden" name="page" value="class-teachers">
                        <div>
                            <label for="class-teacher-filter-session" class="mb-1 block text-xs font-semibold text-slate-600">Academic session</label>
                            <select id="class-teacher-filter-session" name="session_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="all" <?= $selectedSessionId === null ? 'selected' : '' ?>>All sessions</option>
                                <?php foreach ($sessions as $session): ?>
                                    <option value="<?= (int) $session['id'] ?>" <?= $selectedSessionId === (int) $session['id'] ? 'selected' : '' ?>><?= e($session['session_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="class-teacher-filter-term" class="mb-1 block text-xs font-semibold text-slate-600">Academic term</label>
                            <select id="class-teacher-filter-term" name="term_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="">All terms</option>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= (int) $term['id'] ?>" data-session-id="<?= (int) $term['academic_session_id'] ?>" <?= $selectedTermId === (int) $term['id'] ? 'selected' : '' ?>>
                                        <?= e($term['term_name'] . ' — ' . $term['session_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Show assignments</button>
                    </form>
                    <div class="border-b border-slate-200 px-6 py-4">
                        <h2 class="font-bold">Class teacher assignments</h2>
                    </div>
                    <div class="max-h-96 overflow-auto">
                        <?php if (!$classTeacherAllocations): ?>
                            <p class="p-6 text-sm text-slate-600">No class teachers assigned for this selection.</p>
                        <?php else: ?>
                            <table aria-label="Class teacher assignments" class="w-full min-w-[48rem] text-left text-sm">
                                <thead class="sticky top-0 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3">Teacher</th>
                                        <th class="px-4 py-3">Class</th>
                                        <th class="px-4 py-3">Term</th>
                                        <th class="px-4 py-3">Session</th>
                                        <th class="px-4 py-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($classTeacherAllocations as $allocation): ?>
                                        <tr>
                                            <td class="px-4 py-3 font-medium text-slate-900"><?= e($allocation['teacher_name']) ?></td>
                                            <td class="px-4 py-3 text-slate-600"><?= e($allocation['class_name']) ?></td>
                                            <td class="px-4 py-3 text-slate-600"><?= e($allocation['term_name']) ?></td>
                                            <td class="px-4 py-3 text-slate-600"><?= e($allocation['session_name']) ?></td>
                                            <td class="whitespace-nowrap px-4 py-3">
                                                <button type="button" data-edit-class-teacher
                                                        data-teacher-id="<?= (int) $allocation['teacher_id'] ?>"
                                                        data-class-id="<?= (int) $allocation['class_id'] ?>"
                                                        data-session-id="<?= (int) $allocation['academic_session_id'] ?>"
                                                        data-term-id="<?= (int) $allocation['term_id'] ?>"
                                                        class="font-semibold text-emerald-800 hover:text-emerald-950">Edit</button>
                                                <form method="post" action="manage-records.php" class="ml-3 inline" data-confirm-record-delete data-delete-label="this class teacher assignment">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_class_teacher">
                                                    <input type="hidden" name="teacher_id" value="<?= (int) $allocation['teacher_id'] ?>">
                                                    <input type="hidden" name="class_id" value="<?= (int) $allocation['class_id'] ?>">
                                                    <input type="hidden" name="session_id" value="<?= (int) $allocation['academic_session_id'] ?>">
                                                    <input type="hidden" name="term_id" value="<?= (int) $allocation['term_id'] ?>">
                                                    <button class="font-semibold text-rose-700 hover:text-rose-900">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>
            <?php endif; ?>

            <?php if ($activePage === 'allocations'): ?>
        <section id="allocations" class="mb-8 w-full scroll-mt-24">
            <form method="get" action="dashboard.php" class="mb-6 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <input type="hidden" name="page" value="allocations">
                <div>
                    <label for="allocation-filter-session" class="mb-1 block text-xs font-semibold text-slate-600">Academic session</label>
                    <select id="allocation-filter-session" name="session_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="all" <?= $selectedSessionId === null ? 'selected' : '' ?>>All sessions</option>
                        <?php foreach ($sessions as $session): ?>
                            <option value="<?= (int) $session['id'] ?>" <?= $selectedSessionId === (int) $session['id'] ? 'selected' : '' ?>><?= e($session['session_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="allocation-filter-term" class="mb-1 block text-xs font-semibold text-slate-600">Academic term</label>
                    <select id="allocation-filter-term" name="term_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">All terms</option>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= (int) $term['id'] ?>" data-session-id="<?= (int) $term['academic_session_id'] ?>" <?= $selectedTermId === (int) $term['id'] ? 'selected' : '' ?>>
                                <?= e($term['term_name'] . ' — ' . $term['session_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Show allocations</button>
            </form>
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h2 class="font-bold">Teacher allocations</h2>
                </div>
                <div class="max-h-96 overflow-auto">
                    <table aria-label="Teacher allocations" class="w-full min-w-[56rem] text-left text-sm">
                        <thead class="sticky top-0 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2">Teacher</th>
                                <th class="px-4 py-2">Class</th>
                                <th class="px-4 py-2">Subject</th>
                                <th class="px-4 py-2">Term</th>
                                <th class="px-4 py-2">Session</th>
                                <th class="px-4 py-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($teacherAllocations as $allocation): ?>
                                <tr class="transition hover:bg-slate-50">
                                    <td class="px-4 py-3 font-medium text-slate-900"><?= e($allocation['teacher_name']) ?></td>
                                    <td class="px-4 py-3 text-slate-600"><?= e($allocation['class_name']) ?></td>
                                    <td class="px-4 py-3 text-slate-600"><?= e($allocation['subject_name']) ?></td>
                                    <td class="px-4 py-3 text-slate-600"><?= e($allocation['term_name']) ?></td>
                                    <td class="px-4 py-3 text-slate-600"><?= e($allocation['session_name']) ?></td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <button type="button" data-edit-teacher-allocation
                                                data-teacher-id="<?= (int) $allocation['teacher_id'] ?>"
                                                data-class-id="<?= (int) $allocation['class_id'] ?>"
                                                data-session-id="<?= (int) $allocation['academic_session_id'] ?>"
                                                data-subject-id="<?= (int) $allocation['subject_id'] ?>"
                                                data-term-id="<?= (int) $allocation['term_id'] ?>"
                                                class="font-semibold text-emerald-800 hover:text-emerald-950">Edit</button>
                                        <form method="post" action="manage-records.php" class="ml-3 inline" data-confirm-record-delete data-delete-label="this teacher allocation">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_teacher_allocation">
                                            <input type="hidden" name="teacher_id" value="<?= (int) $allocation['teacher_id'] ?>">
                                            <input type="hidden" name="class_id" value="<?= (int) $allocation['class_id'] ?>">
                                            <input type="hidden" name="session_id" value="<?= (int) $allocation['academic_session_id'] ?>">
                                            <input type="hidden" name="subject_id" value="<?= (int) $allocation['subject_id'] ?>">
                                            <input type="hidden" name="term_id" value="<?= (int) $allocation['term_id'] ?>">
                                            <button class="font-semibold text-rose-700 hover:text-rose-900">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!$teacherAllocations): ?><p class="p-4 text-sm text-slate-600">No teachers allocated yet.</p><?php endif; ?>
                </div>
            </section>
        </section>
            <?php endif; ?>

            <?php if ($activePage === 'results'): ?>
        <section id="results" class="scroll-mt-24 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <form method="get" action="dashboard.php" class="grid gap-3 border-b border-slate-200 bg-slate-50 p-4 sm:grid-cols-[1fr_1fr_1fr_auto_auto] sm:items-end">
                <input type="hidden" name="page" value="results">
                <div>
                    <label for="results-filter-session" class="mb-1 block text-xs font-semibold text-slate-600">Academic session</label>
                    <select id="results-filter-session" name="session_id" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="all" <?= $selectedSessionId === null ? 'selected' : '' ?>>All sessions</option>
                        <?php foreach ($sessions as $session): ?>
                            <option value="<?= (int) $session['id'] ?>" <?= $selectedSessionId === (int) $session['id'] ? 'selected' : '' ?>><?= e($session['session_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="results-filter-term" class="mb-1 block text-xs font-semibold text-slate-600">Academic term</label>
                    <select id="results-filter-term" name="term_id" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="">All terms</option>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= (int) $term['id'] ?>" data-session-id="<?= (int) $term['academic_session_id'] ?>" <?= $selectedTermId === (int) $term['id'] ? 'selected' : '' ?>>
                                <?= e($term['term_name'] . ' — ' . $term['session_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="results-filter-class" class="mb-1 block text-xs font-semibold text-slate-600">Class</label>
                    <select id="results-filter-class" name="class_id" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="">All classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= (int) $class['id'] ?>" <?= $selectedClassId === (int) $class['id'] ? 'selected' : '' ?>>
                                <?= e($class['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Show results</button>
                <button type="submit" formmethod="get" formaction="print-class-results.php" formtarget="_blank"
                        <?= $selectedClassId === null || $selectedTermId === null || $resultCount === 0 ? 'disabled' : '' ?>
                        class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-950 disabled:cursor-not-allowed disabled:opacity-50">
                    Print report sheets
                </button>
            </form>
            <div class="border-b border-slate-200 px-6 py-5">
                <h1 class="text-xl font-bold">Student scores</h1>
                <p class="mt-1 text-sm text-slate-600">Showing <?= min($resultCount, 500) ?><?= $resultCount > 500 ? ' latest' : '' ?> results for this selection.</p>
            </div>
            <?php if (!$results): ?>
                <p class="p-6 text-sm text-slate-600">No scores have been entered yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full whitespace-nowrap text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Student</th>
                                <th class="px-5 py-3">Class</th>
                                <th class="px-5 py-3">Class teacher</th>
                                <th class="px-5 py-3">Subject</th>
                                <th class="px-5 py-3">Term / session</th>
                                <th class="px-5 py-3">1st CA /20</th>
                                <th class="px-5 py-3">2nd CA /20</th>
                                <th class="px-5 py-3">Exam /60</th>
                                <th class="px-5 py-3">Total /100</th>
                                <th class="px-5 py-3">Entered by</th>
                                <th class="px-5 py-3">Date entered</th>
                                <th class="px-5 py-3">Report sheet</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($results as $result): ?>
                                <tr class="transition hover:bg-slate-50">
                                    <td class="px-5 py-4 font-medium text-slate-900"><?= e($result['student_name']) ?></td>
                                    <td class="px-5 py-4 text-slate-600"><?= e($result['class_name']) ?></td>
                                    <td class="px-5 py-4 text-slate-600"><?= $result['class_teacher_names'] === null ? '—' : e($result['class_teacher_names']) ?></td>
                                    <td class="px-5 py-4 text-slate-600"><?= e($result['subject_name']) ?></td>
                                    <td class="px-5 py-4 text-slate-600"><?= e($result['term_name'] . ' — ' . $result['session_name']) ?></td>
                                    <td class="px-5 py-4 text-slate-600"><?= $result['first_test'] === null ? '—' : e((string) $result['first_test']) ?></td>
                                    <td class="px-5 py-4 text-slate-600"><?= $result['second_test'] === null ? '—' : e((string) $result['second_test']) ?></td>
                                    <td class="px-5 py-4 text-slate-600"><?= $result['exam'] === null ? '—' : e((string) $result['exam']) ?></td>
                                    <td class="px-5 py-4"><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700"><?= e((string) $result['score']) ?></span></td>
                                    <td class="px-5 py-4 text-slate-600"><?= e($result['teacher_name']) ?></td>
                                    <td class="px-5 py-4 text-slate-500"><?= e($result['created_at']) ?></td>
                                    <td class="px-5 py-4">
                                        <a href="print-result.php?student_id=<?= (int) $result['student_id'] ?>&amp;term_id=<?= (int) $result['term_id'] ?>"
                                           target="_blank" rel="noopener"
                                           class="rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50">Print sheet</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
            <?php endif; ?>

            <?php if ($activePage === 'summary'): ?>
                <section class="space-y-6">
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <form method="get" action="dashboard.php" class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                            <input type="hidden" name="page" value="summary">
                            <label for="summary-session" class="block text-xs font-semibold text-slate-600">
                                Academic session
                                <select id="summary-session" name="session_id" class="mt-1 w-full border px-3 py-2 text-sm">
                                    <?php foreach ($sessions as $session): ?>
                                        <option value="<?= (int) $session['id'] ?>" <?= $summarySessionId === (int) $session['id'] ? 'selected' : '' ?>>
                                            <?= e($session['session_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label for="summary-class" class="block text-xs font-semibold text-slate-600">
                                Class
                                <select id="summary-class" name="class_id" required class="mt-1 w-full border px-3 py-2 text-sm">
                                    <option value="">Select a class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?= (int) $class['id'] ?>" <?= $summaryClassId === (int) $class['id'] ? 'selected' : '' ?>>
                                            <?= e($class['class_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button class="rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">
                                Show summary sheets
                            </button>
                        </form>
                    </section>

                    <?php if ($summaryClassId === null): ?>
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">
                            Select a class and academic session to prepare its term summaries.
                        </div>
                    <?php elseif (!$summaryTerms): ?>
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">
                            No academic terms are configured for this session.
                        </div>
                    <?php else: ?>
                        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
                                <div>
                                    <h2 class="font-bold text-slate-900">
                                        <?= e((string) (array_column($classes, 'class_name', 'id')[$summaryClassId] ?? 'Selected class')) ?>
                                    </h2>
                                    <p class="mt-1 text-sm text-slate-500">
                                        <?= number_format($summaryStudentCount) ?> enrolled students ·
                                        <?= e((string) (array_column($sessions, 'session_name', 'id')[$summarySessionId] ?? 'Selected session')) ?>
                                    </p>
                                </div>
                            </div>
                            <div class="grid gap-4 p-5 md:grid-cols-3">
                                <?php foreach ($summaryTerms as $index => $summaryTerm): ?>
                                    <article class="flex flex-col items-start rounded-xl border border-slate-200 bg-slate-50 p-4">
                                        <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">
                                            <?= ['1st', '2nd', '3rd'][$index] ?? ($index + 1) . 'th' ?> term
                                        </p>
                                        <h3 class="mt-1 font-semibold text-slate-900"><?= e($summaryTerm['term_name']) ?></h3>
                                        <!-- <p class="mt-3 text-sm text-slate-600">
                                            <?= number_format((int) $summaryTerm['scored_students']) ?> students with scores ·
                                            <?= number_format((int) $summaryTerm['scored_subjects']) ?> subjects
                                        </p> -->
                                        <a href="print-summary-sheets.php?<?= e(http_build_query([
                                            'class_id' => $summaryClassId,
                                            'session_id' => $summarySessionId,
                                            'term_id' => (int) $summaryTerm['id'],
                                        ])) ?>" target="_blank" rel="noopener"
                                           <?= $summaryStudentCount === 0 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                                           class="mt-4 rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800 <?= $summaryStudentCount === 0 ? 'pointer-events-none opacity-50' : '' ?>">
                                            Print <?= ['1st', '2nd', '3rd'][$index] ?? ($index + 1) . 'th' ?> term sheet
                                        </a>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($summaryStudentCount === 0): ?>
                                <p class="border-t border-slate-200 px-5 py-4 text-sm text-amber-800">
                                    No students are enrolled in this class for the selected session.
                                </p>
                            <?php elseif (count($summaryTerms) < 3): ?>
                                <p class="border-t border-slate-200 px-5 py-4 text-sm text-amber-800">
                                    Only <?= count($summaryTerms) ?> academic term<?= count($summaryTerms) === 1 ? '' : 's' ?> are configured. Buttons are shown for the available terms.
                                </p>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($activePage === 'promotion'): ?>
                <section id="promotion" class="space-y-6">
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <form method="get" action="dashboard.php" class="grid gap-4 sm:grid-cols-2 sm:items-end">
                            <input type="hidden" name="page" value="promotion">
                            <div>
                                <span class="mb-1 block text-xs font-semibold text-slate-600">Source session</span>
                                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-800">
                                    <?= $activeSession ? e($activeSession['session_name']) : 'No active session' ?>
                                </div>
                            </div>
                            <div>
                                <label for="promotion-target-session" class="mb-1 block text-xs font-semibold text-slate-600">Destination session</label>
                                <select id="promotion-target-session" name="target_session_id" required
                                        <?= !$promotionTargetSessions ? 'disabled' : '' ?>
                                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm disabled:bg-slate-100">
                                    <?php if (!$promotionTargetSessions): ?>
                                        <option value="">No later session available</option>
                                    <?php else: ?>
                                        <?php foreach ($promotionTargetSessions as $targetSession): ?>
                                            <option value="<?= (int) $targetSession['id'] ?>"
                                                <?= $selectedPromotionTargetId === (int) $targetSession['id'] ? 'selected' : '' ?>>
                                                <?= e($targetSession['session_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </form>
                        <!-- <p class="mt-4 text-xs leading-5 text-slate-500">
                            Promotion uses the printed third-term average of subject cumulative scores. Students need an average of at least
                            <?= number_format(STUDENT_PROMOTION_MINIMUM_AVERAGE, 1) ?> / 100. SS1 advances to SS2 and SS2 advances to SS3; SS3 is the final configured class.
                        </p> -->
                    </section>

                    <?php if ($promotionNotice !== ''): ?>
                        <p role="status" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"><?= e($promotionNotice) ?></p>
                    <?php elseif ($promotionContext !== null): ?>
                        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 px-5 py-4 sm:px-6">
                                <div>
                                    <h2 class="text-lg font-bold text-slate-950">Students eligible for promotion</h2>
                                    <p class="mt-1 text-sm text-slate-500">
                                        <?= e($promotionContext['third_term']['term_name']) ?> ended <?= e($promotionContext['third_term']['ends_on']) ?>.
                                        Existing destination-session placements are protected.
                                    </p>
                                </div>
                                <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-800">
                                    <?= count($promotionContext['candidates']) ?> qualifying students
                                </span>
                            </div>

                            <?php if (!$promotionContext['candidates']): ?>
                                <p class="px-5 py-8 text-sm text-slate-600 sm:px-6">
                                    No SS1 or SS2 students have a third-term report average of at least <?= number_format(STUDENT_PROMOTION_MINIMUM_AVERAGE, 1) ?>.
                                </p>
                            <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full whitespace-nowrap text-left text-sm">
                                        <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            <tr>
                                                <th class="px-5 py-3">Student</th>
                                                <th class="px-5 py-3">Current class</th>
                                                <th class="px-5 py-3">Third-term average</th>
                                                <th class="px-5 py-3">Destination class</th>
                                                <th class="px-5 py-3">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <?php foreach ($promotionContext['candidates'] as $candidate): ?>
                                                <tr class="hover:bg-slate-50">
                                                    <td class="px-5 py-3 font-medium text-slate-900"><?= e($candidate['student_name']) ?></td>
                                                    <td class="px-5 py-3 text-slate-600"><?= e($candidate['current_class_name']) ?></td>
                                                    <td class="px-5 py-3 font-bold text-emerald-800"><?= number_format($candidate['average'], 1) ?></td>
                                                    <td class="px-5 py-3 text-slate-600"><?= e($candidate['target_class_name']) ?></td>
                                                    <td class="px-5 py-3">
                                                        <?php if ($candidate['existing_class_id'] === null): ?>
                                                            <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800">Ready to promote</span>
                                                        <?php elseif ($candidate['existing_class_id'] === $candidate['target_class_id']): ?>
                                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">Already promoted</span>
                                                        <?php else: ?>
                                                            <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800">
                                                                Already allocated to <?= e((string) $candidate['existing_class_name']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50/60 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                    <p class="text-xs text-slate-500">
                                        <?= $promotionActionableCount ?> qualifying students can be added to <?= e($promotionContext['target_session']['session_name']) ?>.
                                    </p>
                                    <?php if ($promotionActionableCount > 0): ?>
                                        <form method="post" action="promote-students.php" data-promote-form
                                              data-promote-count="<?= $promotionActionableCount ?>"
                                              data-target-session="<?= e($promotionContext['target_session']['session_name']) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="source_session_id" value="<?= (int) $promotionContext['source_session']['id'] ?>">
                                            <input type="hidden" name="target_session_id" value="<?= (int) $promotionContext['target_session']['id'] ?>">
                                            <button type="submit" class="w-full rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 sm:w-auto">
                                                Promote <?= $promotionActionableCount ?> eligible <?= $promotionActionableCount === 1 ? 'student' : 'students' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($activePage === 'activity-logs'): ?>
                <section id="activity-logs" class="space-y-6">
                    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <h2 class="text-lg font-bold text-slate-950">Teacher upload totals</h2>
                        </div>
                        <?php if (!$teacherUploadSummary): ?>
                            <p class="px-5 py-8 text-sm text-slate-600 sm:px-6">No successful score uploads have been recorded yet.</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full whitespace-nowrap text-left text-sm">
                                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th class="px-5 py-3">Teacher</th>
                                            <th class="px-5 py-3">Successful uploads</th>
                                            <th class="px-5 py-3">Student entries</th>
                                            <th class="px-5 py-3">Last upload</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($teacherUploadSummary as $teacherSummary): ?>
                                            <tr class="hover:bg-slate-50">
                                                <td class="px-5 py-3 font-semibold text-slate-900"><?= e($teacherSummary['teacher_name']) ?></td>
                                                <td class="px-5 py-3 text-slate-600"><?= number_format((int) $teacherSummary['upload_count']) ?></td>
                                                <td class="px-5 py-3 font-bold text-emerald-800"><?= number_format((int) $teacherSummary['student_entry_count']) ?></td>
                                                <td class="px-5 py-3 text-slate-600"><?= e($teacherSummary['last_upload_date'] . ' ' . $teacherSummary['last_upload_time']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <h2 class="text-lg font-bold text-slate-950">Teacher login history</h2>
                            <!-- <p class="mt-1 text-sm text-slate-500">Latest <?= count($teacherLoginLogs) ?> successful teacher sign-ins, with client-reported browser details.</p> -->
                        </div>
                        <?php if (!$teacherLoginLogs): ?>
                            <p class="px-5 py-8 text-sm text-slate-600 sm:px-6">No successful teacher sign-ins have been recorded yet.</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full whitespace-nowrap text-left text-sm">
                                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th class="px-5 py-3">Teacher</th>
                                            <th class="px-5 py-3">Date</th>
                                            <th class="px-5 py-3">Time</th>
                                            <th class="px-5 py-3">IP address</th>
                                            <th class="px-5 py-3">Browser</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($teacherLoginLogs as $loginLog): ?>
                                            <tr class="hover:bg-slate-50">
                                                <td class="px-5 py-3 font-medium text-slate-900"><?= e($loginLog['teacher_name']) ?></td>
                                                <td class="px-5 py-3 text-slate-600"><?= e($loginLog['activity_date']) ?></td>
                                                <td class="px-5 py-3 text-slate-600"><?= e($loginLog['activity_time']) ?></td>
                                                <td class="px-5 py-3 font-mono text-xs text-slate-600"><?= e($loginLog['ip_address'] ?? 'Unknown') ?></td>
                                                <td class="px-5 py-3 text-slate-600" title="<?= e($loginLog['user_agent']) ?>"><?= e(teacher_activity_browser_label($loginLog['user_agent'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <h2 class="text-lg font-bold text-slate-950">Successful score uploads</h2>
                            <!-- <p class="mt-1 text-sm text-slate-500">Latest <?= count($teacherUploadLogs) ?> completed uploads. Each upload is logged only after its student scores are saved.</p> -->
                        </div>
                        <?php if (!$teacherUploadLogs): ?>
                            <p class="px-5 py-8 text-sm text-slate-600 sm:px-6">No successful score uploads have been recorded yet.</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full whitespace-nowrap text-left text-sm">
                                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th class="px-5 py-3">Teacher</th>
                                            <th class="px-5 py-3">Date</th>
                                            <th class="px-5 py-3">Time</th>
                                            <th class="px-5 py-3">Class</th>
                                            <th class="px-5 py-3">Subject</th>
                                            <th class="px-5 py-3">Term / session</th>
                                            <th class="px-5 py-3">Student entries</th>
                                            <th class="px-5 py-3">IP address</th>
                                            <th class="px-5 py-3">Browser</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($teacherUploadLogs as $uploadLog): ?>
                                            <tr class="hover:bg-slate-50">
                                                <td class="px-5 py-3 font-medium text-slate-900"><?= e($uploadLog['teacher_name']) ?></td>
                                                <td class="px-5 py-3 text-slate-600"><?= e($uploadLog['activity_date']) ?></td>
                                                <td class="px-5 py-3 text-slate-600"><?= e($uploadLog['activity_time']) ?></td>
                                                <td class="px-5 py-3 text-slate-600"><?= e($uploadLog['class_name'] ?? '—') ?></td>
                                                <td class="px-5 py-3 text-slate-600"><?= e($uploadLog['subject_name'] ?? '—') ?></td>
                                                <td class="px-5 py-3 text-slate-600"><?= e(($uploadLog['term_name'] ?? '—') . ' / ' . ($uploadLog['session_name'] ?? '—')) ?></td>
                                                <td class="px-5 py-3 font-bold text-emerald-800"><?= number_format((int) $uploadLog['student_entry_count']) ?></td>
                                                <td class="px-5 py-3 font-mono text-xs text-slate-600"><?= e($uploadLog['ip_address'] ?? 'Unknown') ?></td>
                                                <td class="px-5 py-3 text-slate-600" title="<?= e($uploadLog['user_agent']) ?>"><?= e(teacher_activity_browser_label($uploadLog['user_agent'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                </section>
            <?php endif; ?>

            <?php if ($activePage === 'score-resets'): ?>
                <section id="score-resets" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                        <h2 class="text-lg font-bold text-slate-950">Submitted score sets</h2>
                    </div>
                    <?php if (!$scoreEditAssignments): ?>
                        <p class="px-5 py-8 text-sm text-slate-600 sm:px-6">No submitted scores are available to unlock for the active session and term.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full whitespace-nowrap text-left text-sm">
                                <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-5 py-3">Teacher</th>
                                        <th class="px-5 py-3">Class / subject</th>
                                        <th class="px-5 py-3">Term / session</th>
                                        <th class="px-5 py-3">Students</th>
                                        <th class="px-5 py-3">Last saved</th>
                                        <th class="px-5 py-3">Status / action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($scoreEditAssignments as $scoreAssignment): ?>
                                        <tr class="hover:bg-slate-50">
                                            <td class="px-5 py-3 font-semibold text-slate-900"><?= e($scoreAssignment['teacher_name']) ?></td>
                                            <td class="px-5 py-3 text-slate-600">
                                                <?= e($scoreAssignment['class_name']) ?>
                                                <span class="text-slate-400">·</span>
                                                <?= e($scoreAssignment['subject_name']) ?>
                                            </td>
                                            <td class="px-5 py-3 text-slate-600">
                                                <?= e($scoreAssignment['term_name']) ?>
                                                <span class="text-slate-400">·</span>
                                                <?= e($scoreAssignment['session_name']) ?>
                                            </td>
                                            <td class="px-5 py-3 text-slate-600"><?= number_format((int) $scoreAssignment['student_count']) ?></td>
                                            <td class="px-5 py-3 text-slate-600"><?= e($scoreAssignment['latest_update']) ?></td>
                                            <td class="px-5 py-3">
                                                <?php if ((int) $scoreAssignment['edit_approved'] === 1): ?>
                                                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800">
                                                        Edit approved · awaiting save
                                                    </span>
                                                <?php else: ?>
                                                    <form method="post" action="manage-records.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="approve_score_reset">
                                                        <input type="hidden" name="teacher_id" value="<?= (int) $scoreAssignment['teacher_id'] ?>">
                                                        <input type="hidden" name="class_id" value="<?= (int) $scoreAssignment['class_id'] ?>">
                                                        <input type="hidden" name="session_id" value="<?= (int) $scoreAssignment['academic_session_id'] ?>">
                                                        <input type="hidden" name="subject_id" value="<?= (int) $scoreAssignment['subject_id'] ?>">
                                                        <input type="hidden" name="term_id" value="<?= (int) $scoreAssignment['term_id'] ?>">
                                                        <button type="submit" class="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800">
                                                            Reset 
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <dialog id="edit-teacher-dialog" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl border-0 p-0 shadow-xl backdrop:bg-slate-950/50">
                <form id="edit-teacher-form" method="post" action="manage-records.php" class="space-y-4 p-6">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_teacher">
                    <input id="edit-teacher-id" type="hidden" name="teacher_id">
                    <h2 class="text-lg font-bold">Edit teacher</h2>
                    <div>
                        <label for="edit-teacher-name" class="mb-1 block text-sm font-medium text-slate-700">Full name</label>
                        <input id="edit-teacher-name" name="fullname" maxlength="150" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="edit-teacher-phone" class="mb-1 block text-sm font-medium text-slate-700">Phone number</label>
                        <input id="edit-teacher-phone" name="phone_No" type="tel" maxlength="30" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="edit-teacher-password" class="mb-1 block text-sm font-medium text-slate-700">Change password <span class="font-normal text-slate-500">(optional)</span></label>
                        <input id="edit-teacher-password" name="password" type="password" minlength="8" autocomplete="new-password"
                               placeholder="Leave blank to keep the current password"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        <p class="mt-1 text-xs leading-5 text-slate-500">Use at least 8 characters with uppercase and lowercase letters, a number, and a special character.</p>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" data-close-dialog class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">Cancel</button>
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save</button>
                    </div>
                </form>
            </dialog>

            <dialog id="edit-student-dialog" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl border-0 p-0 shadow-xl backdrop:bg-slate-950/50">
                <form method="post" action="manage-records.php" class="space-y-4 p-6">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_student">
                    <input id="edit-student-id" type="hidden" name="student_id">
                    <input id="edit-student-old-class" type="hidden" name="old_class_id">
                    <input id="edit-student-old-session" type="hidden" name="old_session_id">
                    <h2 class="text-lg font-bold">Edit student record</h2>
                    <div>
                        <label for="edit-student-name" class="mb-1 block text-sm font-medium text-slate-700">Full name</label>
                        <input id="edit-student-name" name="fullname" maxlength="200" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="edit-student-class" class="mb-1 block text-sm font-medium text-slate-700">Class</label>
                        <select id="edit-student-class" name="class_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>"><?= e($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="edit-student-session" class="mb-1 block text-sm font-medium text-slate-700">Academic session</label>
                        <select id="edit-student-session" name="session_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($sessions as $session): ?>
                                <option value="<?= (int) $session['id'] ?>"><?= e($session['session_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" data-close-dialog class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">Cancel</button>
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save</button>
                    </div>
                </form>
            </dialog>

            <dialog id="edit-teacher-allocation-dialog" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl border-0 p-0 shadow-xl backdrop:bg-slate-950/50">
                <form method="post" action="manage-records.php" class="space-y-4 p-6">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_teacher_allocation">
                    <input id="edit-allocation-old-teacher" type="hidden" name="old_teacher_id">
                    <input id="edit-allocation-old-class" type="hidden" name="old_class_id">
                    <input id="edit-allocation-old-session" type="hidden" name="old_session_id">
                    <input id="edit-allocation-old-subject" type="hidden" name="old_subject_id">
                    <input id="edit-allocation-old-term" type="hidden" name="old_term_id">
                    <h2 class="text-lg font-bold">Edit teacher allocation</h2>
                    <label class="block text-sm font-medium text-slate-700">Teacher
                        <select id="edit-allocation-teacher" name="teacher_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= (int) $teacher['id'] ?>"><?= e($teacher['fullname']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">Class
                        <select id="edit-allocation-class" name="class_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>"><?= e($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">Subject
                        <select id="edit-allocation-subject" name="subject_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= (int) $subject['id'] ?>"><?= e($subject['subject_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">Term
                        <select id="edit-allocation-term" name="term_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= (int) $term['id'] ?>"><?= e($term['term_name'] . ' — ' . $term['session_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="flex justify-end gap-3">
                        <button type="button" data-close-dialog class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">Cancel</button>
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save</button>
                    </div>
                </form>
            </dialog>

            <dialog id="edit-class-teacher-dialog" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl border-0 p-0 shadow-xl backdrop:bg-slate-950/50">
                <form method="post" action="manage-records.php" class="space-y-4 p-6">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_class_teacher">
                    <input id="edit-class-teacher-old-teacher" type="hidden" name="old_teacher_id">
                    <input id="edit-class-teacher-old-class" type="hidden" name="old_class_id">
                    <input id="edit-class-teacher-old-session" type="hidden" name="old_session_id">
                    <input id="edit-class-teacher-old-term" type="hidden" name="old_term_id">
                    <h2 class="text-lg font-bold">Edit class teacher assignment</h2>
                    <label class="block text-sm font-medium text-slate-700">Teacher
                        <select id="edit-class-teacher-teacher" name="teacher_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= (int) $teacher['id'] ?>"><?= e($teacher['fullname']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">Class
                        <select id="edit-class-teacher-class" name="class_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>"><?= e($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">Term
                        <select id="edit-class-teacher-term" name="term_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= (int) $term['id'] ?>"><?= e($term['term_name'] . ' — ' . $term['session_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="flex justify-end gap-3">
                        <button type="button" data-close-dialog class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">Cancel</button>
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save</button>
                    </div>
                </form>
            </dialog>
        </main>
    </div>
    <script src="../assets/sweetalert2.all.js"></script>
    <?php render_flash_script($flash); ?>
    <script>
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const sidebarToggle = document.getElementById('sidebar-toggle');

        function setSidebarOpen(isOpen) {
            sidebar.classList.toggle('-translate-x-full', !isOpen);
            sidebarOverlay.classList.toggle('hidden', !isOpen);
            sidebarToggle.setAttribute('aria-expanded', String(isOpen));
        }

        sidebarToggle.addEventListener('click', function () {
            setSidebarOpen(sidebar.classList.contains('-translate-x-full'));
        });
        sidebarOverlay.addEventListener('click', function () {
            setSidebarOpen(false);
        });
        sidebar.querySelectorAll('nav a').forEach(function (link) {
            link.addEventListener('click', function () {
                sidebar.querySelectorAll('nav a').forEach(function (navLink) {
                    navLink.classList.remove('bg-emerald-50', 'font-semibold', 'text-emerald-800', 'ring-1', 'ring-emerald-100');
                    navLink.classList.add('text-slate-600');
                });
                link.classList.add('bg-emerald-50', 'font-semibold', 'text-emerald-800', 'ring-1', 'ring-emerald-100');
                link.classList.remove('text-slate-600');
                setSidebarOpen(false);
            });
        });

        const termSessionSelect = document.getElementById('term-session-id');
        const termStartInput = document.getElementById('term-starts-on');
        const termEndInput = document.getElementById('term-ends-on');
        if (termSessionSelect && termStartInput && termEndInput) {
            function updateTermDateLimits() {
                const selectedSession = termSessionSelect.options[termSessionSelect.selectedIndex];
                const sessionStart = selectedSession.dataset.startsOn || '';
                const sessionEnd = selectedSession.dataset.endsOn || '';

                if (!sessionStart || !sessionEnd) {
                    termStartInput.removeAttribute('min');
                    termStartInput.removeAttribute('max');
                    termEndInput.removeAttribute('min');
                    termEndInput.removeAttribute('max');
                    return;
                }

                [termStartInput, termEndInput].forEach(function (input) {
                    if (input.value && (input.value < sessionStart || input.value > sessionEnd)) {
                        input.value = '';
                    }
                });
                if (termStartInput.value && termEndInput.value && termEndInput.value < termStartInput.value) {
                    termEndInput.value = '';
                }

                termStartInput.min = sessionStart;
                termStartInput.max = termEndInput.value || sessionEnd;
                termEndInput.min = termStartInput.value || sessionStart;
                termEndInput.max = sessionEnd;
            }

            termSessionSelect.addEventListener('change', updateTermDateLimits);
            termStartInput.addEventListener('change', updateTermDateLimits);
            termEndInput.addEventListener('change', updateTermDateLimits);
            updateTermDateLimits();
        }

        function bindSessionTermPicker(sessionSelectId, termSelectId) {
            const sessionSelect = document.getElementById(sessionSelectId);
            const termSelect = document.getElementById(termSelectId);
            if (!sessionSelect || !termSelect) {
                return;
            }

            function updateTermOptions() {
                Array.prototype.forEach.call(termSelect.options, function (option) {
                    option.hidden = Boolean(option.value)
                        && sessionSelect.value !== 'all'
                        && option.dataset.sessionId !== sessionSelect.value;
                });
                if (termSelect.selectedOptions.length && termSelect.selectedOptions[0].hidden) {
                    termSelect.value = '';
                }
            }

            sessionSelect.addEventListener('change', updateTermOptions);
            termSelect.addEventListener('change', function () {
                const selectedTerm = termSelect.options[termSelect.selectedIndex];
                if (selectedTerm && selectedTerm.dataset.sessionId) {
                    sessionSelect.value = selectedTerm.dataset.sessionId;
                }
                updateTermOptions();
            });
            updateTermOptions();
        }

        bindSessionTermPicker('student-allocation-session', 'student-allocation-term');
        bindSessionTermPicker('teacher-allocation-session', 'teacher-allocation-term');
        bindSessionTermPicker('class-teacher-session', 'class-teacher-term');
        bindSessionTermPicker('class-teacher-filter-session', 'class-teacher-filter-term');
        bindSessionTermPicker('allocation-filter-session', 'allocation-filter-term');
        bindSessionTermPicker('results-filter-session', 'results-filter-term');

        const resultsClassPicker = document.getElementById('results-filter-class');
        if (resultsClassPicker) {
            resultsClassPicker.addEventListener('change', function () {
                resultsClassPicker.form.submit();
            });
        }

        const promotionTargetPicker = document.getElementById('promotion-target-session');
        if (promotionTargetPicker) {
            promotionTargetPicker.addEventListener('change', function () {
                promotionTargetPicker.form.submit();
            });
        }

        document.querySelectorAll('[data-promote-form]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                Swal.fire({
                    title: 'Promote eligible students?',
                    text: form.dataset.promoteCount + ' students will be placed into ' + form.dataset.targetSession + '. Existing destination-session placements will not be overwritten.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Promote students',
                    confirmButtonColor: '#047857'
                }).then(function (result) {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });

        const editSessionDialog = document.getElementById('edit-session-dialog');
        if (editSessionDialog) {
            const editSessionId = document.getElementById('edit-session-id');
            const editSessionName = document.getElementById('edit-session-name');
            const editSessionStartsOn = document.getElementById('edit-session-starts-on');
            const editSessionEndsOn = document.getElementById('edit-session-ends-on');

            document.querySelectorAll('[data-edit-session]').forEach(function (button) {
                button.addEventListener('click', function () {
                    editSessionId.value = button.dataset.sessionId;
                    editSessionName.value = button.dataset.sessionName;
                    editSessionStartsOn.value = button.dataset.startsOn;
                    editSessionEndsOn.value = button.dataset.endsOn;
                    editSessionDialog.showModal();
                    editSessionName.focus();
                });
            });

            document.getElementById('cancel-session-edit').addEventListener('click', function () {
                editSessionDialog.close();
            });
        }

        document.querySelectorAll('[data-delete-session-form]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                Swal.fire({
                    title: 'Delete this academic session?',
                    text: 'Sessions with terms, enrollments, allocations, or results cannot be deleted.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Delete session',
                    confirmButtonColor: '#dc2626'
                }).then(function (result) {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });

        document.querySelectorAll('[data-confirm-record-delete]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                Swal.fire({
                    title: 'Delete ' + (form.dataset.deleteLabel || 'record') + '?',
                    text: 'This action cannot be undone.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Delete',
                    confirmButtonColor: '#dc2626'
                }).then(function (result) {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });

        document.querySelectorAll('[data-close-dialog]').forEach(function (button) {
            button.addEventListener('click', function () {
                button.closest('dialog').close();
            });
        });

        document.querySelectorAll('[data-edit-teacher]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('edit-teacher-id').value = button.dataset.teacherId;
                document.getElementById('edit-teacher-name').value = button.dataset.fullname;
                document.getElementById('edit-teacher-phone').value = button.dataset.phone;
                document.getElementById('edit-teacher-password').value = '';
                document.getElementById('edit-teacher-dialog').showModal();
            });
        });

        document.querySelectorAll('[data-edit-subject]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('edit-subject-id').value = button.dataset.subjectId;
                document.getElementById('edit-subject-name').value = button.dataset.subjectName;
                document.getElementById('edit-subject-dialog').showModal();
            });
        });

        document.querySelectorAll('[data-edit-student]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('edit-student-id').value = button.dataset.studentId;
                document.getElementById('edit-student-name').value = button.dataset.fullname;
                document.getElementById('edit-student-class').value = button.dataset.classId;
                document.getElementById('edit-student-session').value = button.dataset.sessionId;
                document.getElementById('edit-student-old-class').value = button.dataset.classId;
                document.getElementById('edit-student-old-session').value = button.dataset.sessionId;
                document.getElementById('edit-student-dialog').showModal();
            });
        });

        document.querySelectorAll('[data-edit-teacher-allocation]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('edit-allocation-old-teacher').value = button.dataset.teacherId;
                document.getElementById('edit-allocation-old-class').value = button.dataset.classId;
                document.getElementById('edit-allocation-old-session').value = button.dataset.sessionId;
                document.getElementById('edit-allocation-old-subject').value = button.dataset.subjectId;
                document.getElementById('edit-allocation-old-term').value = button.dataset.termId;
                document.getElementById('edit-allocation-teacher').value = button.dataset.teacherId;
                document.getElementById('edit-allocation-class').value = button.dataset.classId;
                document.getElementById('edit-allocation-subject').value = button.dataset.subjectId;
                document.getElementById('edit-allocation-term').value = button.dataset.termId;
                document.getElementById('edit-teacher-allocation-dialog').showModal();
            });
        });

        document.querySelectorAll('[data-edit-class-teacher]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('edit-class-teacher-old-teacher').value = button.dataset.teacherId;
                document.getElementById('edit-class-teacher-old-class').value = button.dataset.classId;
                document.getElementById('edit-class-teacher-old-session').value = button.dataset.sessionId;
                document.getElementById('edit-class-teacher-old-term').value = button.dataset.termId;
                document.getElementById('edit-class-teacher-teacher').value = button.dataset.teacherId;
                document.getElementById('edit-class-teacher-class').value = button.dataset.classId;
                document.getElementById('edit-class-teacher-term').value = button.dataset.termId;
                document.getElementById('edit-class-teacher-dialog').showModal();
            });
        });

        const teacherCreateForm = document.getElementById('teacher-create-form');
        if (teacherCreateForm) {
            teacherCreateForm.addEventListener('submit', function (event) {
                const password = document.getElementById('teacher-password').value;
                const strongEnough = password.length >= 8
                    && /[a-z]/.test(password)
                    && /[A-Z]/.test(password)
                    && /[0-9]/.test(password)
                    && /[^A-Za-z0-9\s]/.test(password);

                if (!strongEnough) {
                    event.preventDefault();
                    Swal.fire({
                        icon: 'warning',
                        text: 'Use at least 8 characters with uppercase and lowercase letters, a number, and a special character.'
                    });
                }
            });
        }

        const editTeacherForm = document.getElementById('edit-teacher-form');
        if (editTeacherForm) {
            editTeacherForm.addEventListener('submit', function (event) {
                const password = document.getElementById('edit-teacher-password').value;
                if (!password) {
                    return;
                }

                const strongEnough = password.length >= 8
                    && /[a-z]/.test(password)
                    && /[A-Z]/.test(password)
                    && /[0-9]/.test(password)
                    && /[^A-Za-z0-9\s]/.test(password);

                if (!strongEnough) {
                    event.preventDefault();
                    Swal.fire({
                        icon: 'warning',
                        text: 'Use at least 8 characters with uppercase and lowercase letters, a number, and a special character.'
                    });
                }
            });
        }
    </script>
</body>
</html>
