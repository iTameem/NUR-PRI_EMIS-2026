<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_role('teacher');

$teacherId = (int) $_SESSION['user_id'];

$activeSessionQuery = mysqli_prepare(
    $conn,
    'SELECT id, session_name FROM academic_sessions WHERE is_active = 1 LIMIT 1'
);
mysqli_stmt_execute($activeSessionQuery);
$activeSession = mysqli_fetch_assoc(mysqli_stmt_get_result($activeSessionQuery));
mysqli_stmt_close($activeSessionQuery);
$activeSessionId = $activeSession ? (int) $activeSession['id'] : 0;

$terms = [];
if ($activeSessionId > 0) {
    $termsQuery = mysqli_prepare(
        $conn,
        'SELECT id, term_name
         FROM academic_terms
         WHERE academic_session_id = ? AND is_active = 1
         ORDER BY starts_on'
    );
    mysqli_stmt_bind_param($termsQuery, 'i', $activeSessionId);
    mysqli_stmt_execute($termsQuery);
    $terms = mysqli_fetch_all(mysqli_stmt_get_result($termsQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($termsQuery);
}

$selectedTermId = filter_var($_GET['term_id'] ?? null, FILTER_VALIDATE_INT);
$selectedClassId = filter_var($_GET['class_id'] ?? null, FILTER_VALIDATE_INT);
$validTermIds = array_map('intval', array_column($terms, 'id'));
if (!$selectedTermId || !in_array($selectedTermId, $validTermIds, true)) {
    $selectedTermId = 0;
}

$assignments = [];
if ($activeSessionId > 0 && $selectedTermId > 0) {
    $assignmentsQuery = mysqli_prepare(
        $conn,
        'SELECT tc.class_id, tc.subject_id, c.class_name, sub.subject_name,
                COUNT(DISTINCT sc.student_id) AS student_count
         FROM teacher_class_allocations tc
         INNER JOIN classes c ON c.id = tc.class_id
         INNER JOIN subjects sub ON sub.id = tc.subject_id
         INNER JOIN academic_terms t
             ON t.id = tc.term_id
             AND t.academic_session_id = tc.academic_session_id
             AND t.is_active = 1
         LEFT JOIN student_class_allocations sc
             ON sc.class_id = tc.class_id
             AND sc.academic_session_id = tc.academic_session_id
         WHERE tc.teacher_id = ?
             AND tc.academic_session_id = ?
             AND tc.term_id = ?
         GROUP BY tc.class_id, tc.subject_id, c.class_name, sub.subject_name
         ORDER BY c.class_name, sub.subject_name'
    );
    mysqli_stmt_bind_param($assignmentsQuery, 'iii', $teacherId, $activeSessionId, $selectedTermId);
    mysqli_stmt_execute($assignmentsQuery);
    $assignments = mysqli_fetch_all(mysqli_stmt_get_result($assignmentsQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($assignmentsQuery);
}

$selectedSubjectId = filter_var($_GET['subject_id'] ?? null, FILTER_VALIDATE_INT);
$selectedAssignment = null;
if ($selectedTermId > 0 && $selectedClassId && $selectedSubjectId) {
    foreach ($assignments as $assignment) {
        if ((int) $assignment['class_id'] === $selectedClassId
            && (int) $assignment['subject_id'] === $selectedSubjectId) {
            $selectedAssignment = $assignment;
            break;
        }
    }
}

$students = [];
$hasUploadedScores = false;
$editApproved = false;
if ($selectedAssignment !== null) {
    $studentsQuery = mysqli_prepare(
        $conn,
        'SELECT s.id, s.fullname, r.first_test, r.second_test, r.exam, r.score
         FROM student_class_allocations sc
         INNER JOIN students s ON s.id = sc.student_id
         LEFT JOIN results r
             ON r.student_id = sc.student_id
             AND r.class_id = sc.class_id
             AND r.academic_session_id = sc.academic_session_id
             AND r.subject_id = ?
             AND r.term_id = ?
         WHERE sc.class_id = ? AND sc.academic_session_id = ?
         ORDER BY s.fullname'
    );
    mysqli_stmt_bind_param(
        $studentsQuery,
        'iiii',
        $selectedSubjectId,
        $selectedTermId,
        $selectedClassId,
        $activeSessionId
    );
    mysqli_stmt_execute($studentsQuery);
    $students = mysqli_fetch_all(mysqli_stmt_get_result($studentsQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($studentsQuery);

    foreach ($students as $student) {
        if ($student['score'] !== null) {
            $hasUploadedScores = true;
            break;
        }
    }

    $approvalQuery = mysqli_prepare(
        $conn,
        'SELECT edit_approved
         FROM teacher_score_edit_approvals
         WHERE teacher_id = ? AND class_id = ? AND academic_session_id = ?
             AND subject_id = ? AND term_id = ?
         LIMIT 1'
    );
    mysqli_stmt_bind_param(
        $approvalQuery,
        'iiiii',
        $teacherId,
        $selectedClassId,
        $activeSessionId,
        $selectedSubjectId,
        $selectedTermId
    );
    mysqli_stmt_execute($approvalQuery);
    $approval = mysqli_fetch_assoc(mysqli_stmt_get_result($approvalQuery));
    mysqli_stmt_close($approvalQuery);
    $editApproved = $approval !== null && (int) $approval['edit_approved'] === 1;
}
$scoresLocked = $hasUploadedScores && !$editApproved;

$formatScore = static function (?string $score): string {
    if ($score === null) {
        return '';
    }

    return rtrim(rtrim(number_format((float) $score, 2, '.', ''), '0'), '.');
};

$flash = take_flash();
?>
<!doctype html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Class scores | GMMC-NUR-PRI EMIS</title>
    <link href="../css/src/output.css" rel="stylesheet">
    <style>
        [data-score-input] {
            -moz-appearance: textfield;
            appearance: textfield;
        }

        [data-score-input]::-webkit-inner-spin-button,
        [data-score-input]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

    </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <div id="sidebar-overlay" class="fixed inset-0 z-40 hidden bg-emerald-600/60 backdrop-blur-sm lg:hidden"></div>
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col border-r border-slate-800 bg-emerald-950/95 text-white font-bold shadow-2xl shadow-slate-950/20 transition-transform duration-300 lg:translate-x-0">
        <div class="flex items-center gap-3 border-b border-white/10 px-6 py-6">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-white/20 bg-white p-1 shadow-sm">
                <img src="../assets/gmmc-logo1.png" alt="General Murtala Mohammed College Yola crest"
                     class="h-full w-full rounded-full object-contain">
            </div>
            <div>
                <p class="font-bold tracking-tight text-white">GMMC (NUR-PRI) Yola</p>
                <p class="mt-0.5 text-xs font-medium text-slate-400">EMIS Software</p>
            </div>
        </div>
        <nav class="flex-1 overflow-y-auto px-4 py-6" aria-label="Assigned classes and subjects">
            <p class="px-3 pb-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">My subjects</p>
            <?php if ($selectedTermId === 0): ?>
                <p class="px-3 py-2 text-sm leading-6 text-slate-400">Select a term to view your class and subject assignments.</p>
            <?php elseif (!$assignments): ?>
                <p class="px-3 py-2 text-sm leading-6 text-slate-400">No class or subject assignments for this term.</p>
            <?php else: ?>
                <div class="space-y-1">
                    <?php foreach ($assignments as $assignment): ?>
                        <?php
                        $assignmentUrl = 'dashboard.php?' . http_build_query([
                            'class_id' => (int) $assignment['class_id'],
                            'subject_id' => (int) $assignment['subject_id'],
                            'term_id' => $selectedTermId,
                        ]);
                        $isSelectedAssignment = $selectedAssignment !== null
                            && $selectedClassId === (int) $assignment['class_id']
                            && $selectedSubjectId === (int) $assignment['subject_id'];
                        ?>
                        <a href="<?= e($assignmentUrl) ?>"
                           aria-current="<?= $isSelectedAssignment ? 'page' : 'false' ?>"
                           class="flex items-center justify-between gap-3 rounded-xl px-3 py-3 text-sm transition <?= $isSelectedAssignment ? 'bg-emerald-500/15 font-semibold text-emerald-100 ring-1 ring-inset ring-emerald-400/20' : 'text-slate-300 hover:bg-white/5 hover:text-white' ?>">
                            <span class="min-w-0">
                                <span class="block truncate"><?= e($assignment['class_name']) ?></span>
                                <span class="block truncate text-xs text-slate-400"><?= e($assignment['subject_name']) ?></span>
                            </span>
                            <span class="rounded-full bg-white/10 px-2 py-0.5 text-xs"><?= (int) $assignment['student_count'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </nav>
        <div class="m-4 rounded-2xl border border-white/10 bg-white/[0.04] p-4">
            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">Active session</p>
            <p class="mt-2 text-sm font-semibold text-white"><?= $activeSession ? e($activeSession['session_name']) : 'Not set' ?></p>
        </div>
    </aside>

    <div class="min-h-screen lg:pl-72">
        <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 shadow-sm shadow-slate-950/[0.03] backdrop-blur-xl">
            <div class="flex min-h-[4.5rem] items-center justify-between gap-3 px-3 sm:px-6 lg:px-8">
                <div class="flex min-w-0 flex-1 items-center gap-2.5 sm:gap-3">
                    <button id="sidebar-toggle" type="button" aria-label="Open classes menu" aria-expanded="false"
                            class="shrink-0 rounded-xl border border-slate-200 bg-white p-2.5 text-slate-600 shadow-sm transition hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700 lg:hidden">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <div class="flex min-w-0 items-center gap-2.5 sm:gap-3 lg:hidden">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-emerald-100 bg-white p-1 shadow-sm sm:h-11 sm:w-11">
                            <img src="../assets/gmmc-logo1.png" alt="" class="h-full w-full rounded-lg object-contain">
                        </div>
                        <div class="min-w-0">
                            <p class="max-w-[42vw] truncate text-base font-extrabold tracking-tight text-slate-950 sm:max-w-none sm:text-lg"><?= e((string) $_SESSION['name']) ?></p>
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-emerald-700 sm:text-xs">Teacher</p>
                        </div>
                    </div>
                    <div class="hidden lg:block">
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Teacher portal</p>
                        <p class="mt-0.5 text-sm font-semibold text-slate-900">.<i>Building Minds....</i></p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                    <div class="hidden text-right md:block">
                        <p class="text-sm font-bold text-slate-900"><?= e((string) $_SESSION['name']) ?></p>
                        <p class="text-xs text-slate-500"><?= $activeSession ? e($activeSession['session_name']) : 'No active session' ?></p>
                    </div>
                    <form method="post" action="../auth/logout.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="session_role" value="teacher">
                        <button class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700 sm:px-4 sm:text-sm">Sign out</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="w-full px-3 py-5 sm:px-6 sm:py-8 lg:px-8">
            <section id="assessment-selection" class="mb-6">
                <div class="mb-5">
                    <p class="mb-2 text-[10px] font-bold uppercase tracking-[0.18em] text-emerald-700">Score Entry</p>
                    <!-- <h1 class="text-2xl font-extrabold tracking-tight text-slate-950 sm:text-3xl">Class score entry</h1>
                    <p class="mt-2 text-sm text-slate-500">Choose a term to view your classes and enter scores.</p> -->
                </div>

                <?php if (!$activeSession): ?>
                    <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">There is no active academic session. Ask an administrator to activate one.</p>
                <?php elseif (!$terms): ?>
                    <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">There are no active terms in this session. Ask an administrator to add one.</p>
                <?php else: ?>
                    <form method="get" action="dashboard.php" class="grid gap-4 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm shadow-slate-950/[0.03] sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end sm:p-5">
                        <div>
                            <label for="term_id" class="mb-1.5 block text-sm font-semibold text-slate-700">Academic term</label>
                            <select id="term_id" name="term_id" required class="w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-50">
                                <option value="">Choose a term</option>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= (int) $term['id'] ?>" <?= $selectedTermId === (int) $term['id'] ? 'selected' : '' ?>><?= e($term['term_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="w-full rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white shadow-sm shadow-emerald-900/15 transition hover:-translate-y-0.5 hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-200 sm:w-auto">Show assignments</button>
                    </form>
                <?php endif; ?>
            </section>

            <?php if ($selectedAssignment === null): ?>
                <section class="rounded-3xl border border-slate-200/80 bg-white p-4 shadow-sm shadow-slate-950/[0.03] sm:p-6">
                    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
                        <div>
                            <h2 class="text-lg font-bold tracking-tight text-slate-950">My assignments</h2>
                            <p class="mt-1 text-sm text-slate-500"><?= $selectedTermId > 0 ? e($activeSession['session_name']) : '' ?></p>
                        </div>
                        <?php if ($assignments): ?>
                            <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800">
                                <?= count($assignments) ?> <?= count($assignments) === 1 ? 'assignment' : 'assignments' ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$activeSession): ?>
                        <div class="rounded-2xl border border-dashed border-amber-200 bg-amber-50/60 px-5 py-10 text-center">
                            <p class="font-semibold text-slate-800">No active academic session</p>
                            <p class="mt-1 text-sm text-slate-600">Ask an administrator to activate a session.</p>
                        </div>
                    <?php elseif (!$terms): ?>
                        <div class="rounded-2xl border border-dashed border-amber-200 bg-amber-50/60 px-5 py-10 text-center">
                            <p class="font-semibold text-slate-800">No active terms</p>
                            <p class="mt-1 text-sm text-slate-600">Ask an administrator to add a term.</p>
                        </div>
                    <?php elseif ($selectedTermId === 0): ?>
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/70 px-5 py-10 text-center">
                            <p class="font-semibold text-slate-800">Choose an academic term</p>
                            <p class="mt-1 text-sm text-slate-500">Your assignments will appear here.</p>
                        </div>
                    <?php elseif (!$assignments): ?>
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/70 px-5 py-10 text-center">
                            <p class="font-semibold text-slate-800">No assignments for this term</p>
                            <p class="mt-1 text-sm text-slate-500">Ask an administrator to assign your classes and subjects.</p>
                        </div>
                    <?php else: ?>
                        <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-3">
                            <?php foreach ($assignments as $assignment): ?>
                                <?php
                                $assignmentUrl = 'dashboard.php?' . http_build_query([
                                    'class_id' => (int) $assignment['class_id'],
                                    'subject_id' => (int) $assignment['subject_id'],
                                    'term_id' => $selectedTermId,
                                ]);
                                ?>
                                <a href="<?= e($assignmentUrl) ?>"
                                   class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-emerald-200 hover:bg-emerald-50/30 hover:shadow-lg hover:shadow-emerald-950/[0.06]">
                                    <div class="flex items-start justify-between gap-3">
                                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-100">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
                                        </span>
                                        <svg class="mt-1 h-5 w-5 text-slate-400 transition group-hover:translate-x-1 group-hover:text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 12h14m-7-7 7 7-7 7"/></svg>
                                    </div>
                                    <h3 class="mt-5 text-lg font-bold tracking-tight text-slate-950"><?= e($assignment['class_name']) ?></h3>
                                    <p class="mt-1 text-sm font-semibold text-emerald-700"><?= e($assignment['subject_name']) ?></p>
                                    <p class="mt-3 inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600"><?= (int) $assignment['student_count'] ?> <?= (int) $assignment['student_count'] === 1 ? 'student' : 'students' ?></p>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-sm shadow-slate-950/[0.04]">
                    <div class="flex flex-col gap-4 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 sm:py-5">
                        <div class="min-w-0">
                            <a href="dashboard.php?<?= e(http_build_query(['term_id' => $selectedTermId])) ?>"
                               class="inline-flex items-center gap-2 rounded-lg text-xs font-semibold text-emerald-700 transition hover:text-emerald-900 focus:outline-none focus:ring-4 focus:ring-emerald-100">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6M9 12h12"/></svg>
                                All assignments
                            </a>
                            <h2 class="mt-2 truncate text-xl font-extrabold tracking-tight text-slate-950 sm:text-2xl"><?= e($selectedAssignment['class_name']) ?></h2>
                            <!-- <p class="mt-1 text-sm font-medium text-emerald-700">
                                <?= e($selectedAssignment['subject_name']) ?>
                                <span class="px-1 text-slate-300">·</span>
                                <?= $activeSession ? e($activeSession['session_name']) : 'No active session' ?>
                            </p> -->
                        </div>
                        <!-- <div class="inline-flex w-fit items-center gap-2 rounded-xl border border-emerald-100 bg-emerald-50 px-3.5 py-2 text-sm font-semibold text-emerald-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <?= count($students) ?> <?= count($students) === 1 ? 'student' : 'students' ?>
                        </div> -->
                    </div>

                    <?php if (!$students): ?>
                        <div class="px-5 py-14 text-center">
                            <p class="font-semibold text-slate-800">No students in this class</p>
                            <p class="mt-1 text-sm text-slate-500">There are no student enrollments for this session.</p>
                        </div>
                    <?php else: ?>
                        <form id="class-scores-form" method="post" action="save-class-scores.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                            <input type="hidden" name="subject_id" value="<?= $selectedSubjectId ?>">
                            <input type="hidden" name="term_id" value="<?= $selectedTermId ?>">
                            <div class="grid grid-cols-[minmax(0,1.3fr)_repeat(3,minmax(0,1fr))] items-center gap-1 border-b border-slate-200 bg-slate-50/90 px-2 py-2 text-left text-[9px] font-bold uppercase tracking-[0.04em] text-slate-500 md:grid-cols-[minmax(0,1.2fr)_repeat(3,minmax(0,0.85fr))_minmax(3rem,0.5fr)] md:gap-2 md:px-4 lg:hidden">
                                <div class="truncate">Student</div>
                                <div class="truncate">1st CA /20</div>
                                <div class="truncate">2nd CA /20</div>
                                <div class="truncate">Exam /60</div>
                                <div class="hidden truncate md:block">Total /100</div>
                            </div>
                            <div class="hidden border-b border-slate-200 bg-slate-50/90 text-left text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500 lg:grid lg:grid-cols-[3rem_minmax(12rem,1fr)_repeat(4,minmax(7rem,0.7fr))] lg:items-center">
                                <div class="px-3 py-3 text-right">#</div>
                                <div class="px-3 py-3">Student</div>
                                <div class="px-3 py-3">1st CA /20</div>
                                <div class="px-3 py-3">2nd CA /20</div>
                                <div class="px-3 py-3">Exam /60</div>
                                <div class="px-3 py-3">Total /100</div>
                            </div>
                            <div class="space-y-1.5 p-2 md:p-3 lg:space-y-0 lg:p-0">
                                <?php foreach ($students as $index => $student): ?>
                                    <div class="score-row grid grid-cols-[minmax(0,1.3fr)_repeat(3,minmax(0,1fr))] items-center gap-1 rounded-xl border border-slate-200 bg-white p-2 shadow-sm shadow-slate-950/[0.03] md:grid-cols-[minmax(0,1.2fr)_repeat(3,minmax(0,0.85fr))_minmax(3rem,0.5fr)] md:gap-2 md:p-3 lg:grid-cols-[3rem_minmax(12rem,1fr)_repeat(4,minmax(7rem,0.7fr))] lg:gap-0 lg:rounded-none lg:border-0 lg:border-b lg:px-0 lg:py-2 lg:shadow-none">
                                        <div class="hidden px-3 py-3 text-right text-xs font-medium text-slate-400 lg:col-start-1 lg:block"><?= $index + 1 ?></div>
                                        <div class="min-w-0 lg:contents">
                                            <div class="min-w-0 truncate text-[11px] font-semibold text-slate-900 lg:col-start-2 lg:px-3 lg:py-3 lg:text-sm">
                                                <?= e($student['fullname']) ?>
                                            </div>
                                        </div>
                                        <?php foreach (['first_test', 'second_test', 'exam'] as $component): ?>
                                            <?php
                                            $componentLabel = match ($component) {
                                                'first_test' => '1st CA',
                                                'second_test' => '2nd CA',
                                                'exam' => 'Exam',
                                            };
                                            $componentColumn = match ($component) {
                                                'first_test' => 'lg:col-start-3',
                                                'second_test' => 'lg:col-start-4',
                                                'exam' => 'lg:col-start-5',
                                            };
                                            ?>
                                            <div class="min-w-0 <?= $componentColumn ?> lg:px-3 lg:py-3">
                                                <input data-score-input type="number" inputmode="decimal"
                                                           min="0" max="<?= $component === 'exam' ? 60 : 20 ?>" step="0.01"
                                                       required
                                                       name="scores[<?= (int) $student['id'] ?>][<?= e($component) ?>]"
                                                       value="<?= $student[$component] === null ? '' : e($formatScore((string) $student[$component])) ?>"
                                                       <?= $scoresLocked ? 'disabled' : '' ?>
                                                       data-select-value="<?= $editApproved ? 'true' : 'false' ?>"
                                                       aria-label="<?= e($componentLabel . ' score for ' . $student['fullname']) ?>"
                                                       class="min-w-0 w-full rounded-lg border border-slate-300 bg-white px-1.5 py-2 text-xs font-medium text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 lg:rounded-lg lg:px-2.5 lg:py-2 lg:text-sm">
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="hidden min-w-0 items-center justify-center rounded-lg bg-emerald-50/70 px-1 py-2 text-center md:flex lg:col-start-6 lg:justify-start lg:rounded-none lg:bg-transparent lg:px-3 lg:py-3">
                                            <span data-overall class="text-sm font-bold text-emerald-800"><?= $student['score'] === null ? '—' : e($formatScore((string) $student['score'])) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50/60 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <button id="save-class-scores" type="submit" <?= $scoresLocked ? 'disabled' : '' ?>
                                        class="w-full rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white shadow-sm shadow-emerald-900/15 transition hover:-translate-y-0.5 hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-200 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto">
                                    <?= $scoresLocked ? 'Scores locked' : ($editApproved ? 'Update scores' : 'Save scores') ?>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>
    <script src="../assets/sweetalert2.all.js"></script>
    <?php render_flash_script($flash); ?>
    <script>
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const sidebarToggle = document.getElementById('sidebar-toggle');

        sidebarToggle.addEventListener('click', function () {
            const isOpening = sidebar.classList.contains('-translate-x-full');
            sidebar.classList.toggle('-translate-x-full', !isOpening);
            sidebarOverlay.classList.toggle('hidden', !isOpening);
            sidebarToggle.setAttribute('aria-expanded', String(isOpening));
        });
        sidebarOverlay.addEventListener('click', function () {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
            sidebarToggle.setAttribute('aria-expanded', 'false');
        });

        const scoreInputs = document.querySelectorAll('[data-score-input]');
        function updateScoreRow(row) {
            const values = Array.from(row.querySelectorAll('[data-score-input]')).map(function (field) {
                return field.value === '' ? null : Number(field.value);
            });
            const overall = row.querySelector('[data-overall]');
            if (values.every(function (value) { return value !== null && Number.isFinite(value); })) {
                const total = values[0] + values[1] + values[2];
                overall.textContent = String(Number(total.toFixed(2)));
            } else {
                overall.textContent = '—';
            }
        }

        document.querySelectorAll('.score-row').forEach(updateScoreRow);
        scoreInputs.forEach(function (input, index) {
            let advanceTimer = null;

            if (input.dataset.selectValue === 'true') {
                input.addEventListener('focus', function () {
                    input.select();
                });
                input.addEventListener('mouseup', function (event) {
                    event.preventDefault();
                    input.select();
                });
            }

            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    window.clearTimeout(advanceTimer);
                    advanceTimer = null;
                    const nextInput = scoreInputs[index + 1];
                    if (nextInput) {
                        nextInput.focus();
                    } else {
                        document.getElementById('save-class-scores').focus();
                    }
                }
            });
            input.addEventListener('blur', function () {
                window.clearTimeout(advanceTimer);
                advanceTimer = null;
            });
            input.addEventListener('input', function () {
                const row = input.closest('.score-row');
                updateScoreRow(row);

                window.clearTimeout(advanceTimer);
                advanceTimer = null;
                if (!input.value || !input.validity.valid) {
                    return;
                }

                advanceTimer = window.setTimeout(function () {
                    advanceTimer = null;
                    const nextInput = scoreInputs[index + 1];
                    if (nextInput) {
                        nextInput.focus();
                    } else {
                        document.getElementById('save-class-scores').focus();
                    }
                }, 1000);
            });
        });

        const scoresForm = document.getElementById('class-scores-form');
        if (scoresForm) {
            scoresForm.addEventListener('submit', function (event) {
                if (!scoresForm.reportValidity()) {
                    event.preventDefault();
                    return;
                }
                const saveButton = document.getElementById('save-class-scores');
                saveButton.disabled = true;
                saveButton.textContent = 'Saving scores…';
            });
        }
    </script>
</body>
</html>
