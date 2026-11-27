<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_role('admin');

$classId = filter_var($_GET['class_id'] ?? null, FILTER_VALIDATE_INT);
$sessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT);
$selectedTermId = filter_var($_GET['term_id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($classId) || $classId < 1
    || !is_int($sessionId) || $sessionId < 1
    || !is_int($selectedTermId) || $selectedTermId < 1) {
    http_response_code(400);
    exit('Select a class, academic session, and term before printing a summary sheet.');
}

$classQuery = mysqli_prepare($conn, 'SELECT id, class_name FROM classes WHERE id = ?');
mysqli_stmt_bind_param($classQuery, 'i', $classId);
mysqli_stmt_execute($classQuery);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($classQuery));
mysqli_stmt_close($classQuery);
if (!$class) {
    http_response_code(404);
    exit('The selected class was not found.');
}

$sessionQuery = mysqli_prepare(
    $conn,
    'SELECT id, session_name
     FROM academic_sessions
     WHERE id = ?'
);
mysqli_stmt_bind_param($sessionQuery, 'i', $sessionId);
mysqli_stmt_execute($sessionQuery);
$session = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionQuery));
mysqli_stmt_close($sessionQuery);
if (!$session) {
    http_response_code(404);
    exit('The selected academic session was not found.');
}

$termsQuery = mysqli_prepare(
    $conn,
    'SELECT id, term_name
     FROM academic_terms
     WHERE academic_session_id = ?
     ORDER BY starts_on, id
     LIMIT 3'
);
mysqli_stmt_bind_param($termsQuery, 'i', $sessionId);
mysqli_stmt_execute($termsQuery);
$terms = mysqli_fetch_all(mysqli_stmt_get_result($termsQuery), MYSQLI_ASSOC);
mysqli_stmt_close($termsQuery);
if (!$terms) {
    http_response_code(404);
    exit('No academic terms are configured for the selected session.');
}
$selectedTermIndex = null;
foreach ($terms as $termIndex => $term) {
    if ((int) $term['id'] === $selectedTermId) {
        $selectedTermIndex = $termIndex;
        break;
    }
}
if ($selectedTermIndex === null) {
    http_response_code(404);
    exit('The selected term is not one of the first three terms for this session.');
}

$studentsQuery = mysqli_prepare(
    $conn,
    'SELECT s.id, s.fullname
     FROM student_class_allocations sc
     INNER JOIN students s ON s.id = sc.student_id
     WHERE sc.class_id = ? AND sc.academic_session_id = ?
     ORDER BY s.fullname, s.id'
);
mysqli_stmt_bind_param($studentsQuery, 'ii', $classId, $sessionId);
mysqli_stmt_execute($studentsQuery);
$students = mysqli_fetch_all(mysqli_stmt_get_result($studentsQuery), MYSQLI_ASSOC);
mysqli_stmt_close($studentsQuery);

$subjectsByTerm = [];
$marksByTerm = [];
$classTeachersByTerm = [];
foreach ($terms as $term) {
    $termId = (int) $term['id'];
    $subjectsQuery = mysqli_prepare(
        $conn,
        'SELECT DISTINCT sub.id, sub.subject_name
         FROM teacher_class_allocations tc
         INNER JOIN subjects sub ON sub.id = tc.subject_id
         WHERE tc.class_id = ? AND tc.academic_session_id = ? AND tc.term_id = ?
         ORDER BY sub.subject_name'
    );
    mysqli_stmt_bind_param($subjectsQuery, 'iii', $classId, $sessionId, $termId);
    mysqli_stmt_execute($subjectsQuery);
    $subjectsByTerm[$termId] = mysqli_fetch_all(mysqli_stmt_get_result($subjectsQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($subjectsQuery);

    $scoresQuery = mysqli_prepare(
        $conn,
        'SELECT student_id, subject_id, first_test, second_test, exam
         FROM results
         WHERE class_id = ? AND academic_session_id = ? AND term_id = ?'
    );
    mysqli_stmt_bind_param($scoresQuery, 'iii', $classId, $sessionId, $termId);
    mysqli_stmt_execute($scoresQuery);
    $scoreRows = mysqli_fetch_all(mysqli_stmt_get_result($scoresQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($scoresQuery);
    foreach ($scoreRows as $scoreRow) {
        $marksByTerm[$termId][(int) $scoreRow['student_id']][(int) $scoreRow['subject_id']] = $scoreRow;
    }

    $classTeachersQuery = mysqli_prepare(
        $conn,
        'SELECT GROUP_CONCAT(u.fullname ORDER BY u.fullname SEPARATOR ", ") AS teacher_names
         FROM class_teacher_allocations ct
         INNER JOIN users u ON u.id = ct.teacher_id
         WHERE ct.class_id = ? AND ct.academic_session_id = ? AND ct.term_id = ?'
    );
    mysqli_stmt_bind_param($classTeachersQuery, 'iii', $classId, $sessionId, $termId);
    mysqli_stmt_execute($classTeachersQuery);
    $classTeacherRow = mysqli_fetch_assoc(mysqli_stmt_get_result($classTeachersQuery));
    mysqli_stmt_close($classTeachersQuery);
    $classTeachersByTerm[$termId] = (string) ($classTeacherRow['teacher_names'] ?? '');
}

$deriveTermMark = static function (?array $mark): array {
    if ($mark === null) {
        return [
            'assessment_average' => null,
            'overall' => null,
            'all_components_zero' => false,
        ];
    }

    $firstTest = $mark['first_test'] === null ? null : (float) $mark['first_test'];
    $secondTest = $mark['second_test'] === null ? null : (float) $mark['second_test'];
    $exam = $mark['exam'] === null ? null : (float) $mark['exam'];
    $assessmentAverage = $firstTest !== null && $secondTest !== null
        ? round($firstTest + $secondTest, 2)
        : null;
    $overall = $assessmentAverage !== null && $exam !== null
        ? round($assessmentAverage + $exam, 2)
        : null;

    return [
        'assessment_average' => $assessmentAverage,
        'overall' => $overall,
        'all_components_zero' => $firstTest === 0.0
            && $secondTest === 0.0
            && $exam === 0.0,
    ];
};
$termSheets = [];
foreach ($terms as $termIndex => $term) {
    if ($termIndex !== $selectedTermIndex) {
        continue;
    }

    $termId = (int) $term['id'];
    $subjects = $subjectsByTerm[$termId];
    $studentRows = [];
    foreach ($students as $student) {
        $studentId = (int) $student['id'];
        $subjectMarks = [];
        $total = 0;
        foreach ($subjects as $subject) {
            $subjectId = (int) $subject['id'];
            $lastCumulative = null;
            $previousCumulative = null;
            $currentTermMark = [
                'assessment_average' => null,
                'overall' => null,
                'all_components_zero' => false,
            ];
            $currentCumulative = null;
            for ($priorTermIndex = 0; $priorTermIndex <= $termIndex; $priorTermIndex++) {
                $priorTermId = (int) $terms[$priorTermIndex]['id'];
                $priorMark = $deriveTermMark(
                    $marksByTerm[$priorTermId][$studentId][$subjectId] ?? null
                );
                $previousCumulative = $lastCumulative;
                $currentCumulative = $lastCumulative === null
                    ? $priorMark['overall']
                    : round((($priorMark['overall'] ?? 0) + $lastCumulative) / 2, 2);
                if ($priorTermIndex === $termIndex) {
                    $currentTermMark = $priorMark;
                }
                $lastCumulative = $currentCumulative;
            }

            $summaryScore = $termIndex === 0
                ? $currentTermMark['overall']
                : $currentCumulative;
            if ($summaryScore !== null) {
                $total += $summaryScore;
            }
            $subjectMarks[] = [
                'last_term_average' => $previousCumulative,
                'current_term' => $currentTermMark['overall'],
                'average' => $currentTermMark['assessment_average'],
                'cumulative_average' => $currentCumulative,
                'summary_score' => $summaryScore,
                'all_components_zero' => $currentTermMark['all_components_zero'],
            ];
        }

        $studentRows[] = [
            'id' => $studentId,
            'name' => $student['fullname'],
            'subjects' => $subjectMarks,
            'total' => $total,
            'average' => $subjects ? $total / count($subjects) : 0,
            'position' => null,
        ];
    }

    $rankedStudents = $studentRows;
    usort($rankedStudents, static function (array $first, array $second): int {
        return $second['total'] <=> $first['total'] ?: $first['id'] <=> $second['id'];
    });
    $rank = 0;
    $previousTotal = null;
    foreach ($rankedStudents as $index => $rankedStudent) {
        if ($previousTotal === null || $rankedStudent['total'] < $previousTotal) {
            $rank = $index + 1;
            $previousTotal = $rankedStudent['total'];
        }
        foreach ($studentRows as &$studentRow) {
            if ($studentRow['id'] === $rankedStudent['id']) {
                $studentRow['position'] = $rank;
                break;
            }
        }
        unset($studentRow);
    }

    $termSheets[] = [
        'ordinal' => ['1st', '2nd', '3rd'][$termIndex] ?? ($termIndex + 1) . 'th',
        'term' => $term,
        'subjects' => $subjects,
        'students' => $studentRows,
        'class_teacher' => $classTeachersByTerm[$termId],
    ];
}

$formatMark = static function (?float $mark): string {
    return $mark === null
        ? '—'
        : rtrim(rtrim(number_format($mark, 2, '.', ''), '0'), '.');
};
$dashboardUrl = 'dashboard.php?' . http_build_query([
    'page' => 'summary',
    'session_id' => $sessionId,
    'class_id' => $classId,
]);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Summary sheets | <?= e($class['class_name']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #e5e7eb;
            color: #111827;
            font-family: Arial, Helvetica, sans-serif;
        }
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 20px;
            background: #fff;
            box-shadow: 0 1px 5px #0f172a1a;
        }
        .toolbar h1 { margin: 0; font-size: 17px; }
        .toolbar p { margin: 4px 0 0; color: #64748b; font-size: 13px; }
        .actions { display: flex; flex-shrink: 0; gap: 8px; }
        .actions a, .actions button {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            color: #334155;
            cursor: pointer;
            font: 600 13px Arial, sans-serif;
            padding: 9px 12px;
            text-decoration: none;
        }
        .actions button { border-color: #047857; background: #047857; color: #fff; }
        .sheets { padding: 16px 0; }
        .sheet {
            position: relative;
            width: 287mm;
            min-height: 200mm;
            margin: 0 auto 16px;
            overflow: hidden;
            background: #fff;
            padding: 7mm;
            box-shadow: 0 2px 10px #0f172a1a;
        }
        .sheet-header {
            display: grid;
            grid-template-columns: 19mm 1fr 19mm;
            align-items: center;
            gap: 4mm;
            border-bottom: 1px solid #111;
            padding-bottom: 2mm;
            text-align: center;
        }
        .sheet-header img { width: 17mm; height: 17mm; object-fit: contain; }
        .sheet-header h1 { margin: 0; font-size: 12pt; letter-spacing: .04em; }
        .sheet-header h2 { margin: 1mm 0 0; font-size: 10pt; text-transform: uppercase; }
        .sheet-header p { margin: 1mm 0 0; font-size: 8pt; }
        .class-details {
            display: flex;
            justify-content: space-between;
            gap: 4mm;
            margin: 2mm 0;
            font-size: 8pt;
        }
        .class-details strong { font-weight: 700; }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 6pt;
        }
        th, td {
            border: 1px solid #222;
            padding: 1.1mm .45mm;
            text-align: center;
            overflow-wrap: anywhere;
        }
        thead { display: table-header-group; }
        thead th { background: #f1f5f9; font-weight: 700; }
        .number-column { width: 7mm; }
        .name-column { width: 34mm; text-align: left; }
        .total-column { width: 11mm; }
        .average-column { width: 12mm; }
        .position-column { width: 10mm; }
        .student-name { text-align: left; font-weight: 600; }
        .empty-row { padding: 7mm; text-align: center; }
        .signatures {
            display: flex;
            justify-content: space-between;
            gap: 20mm;
            margin-top: 5mm;
            font-size: 7pt;
        }
        .signature { min-width: 60mm; border-top: 1px solid #222; padding-top: 1mm; }
        .watermark {
            position: absolute;
            z-index: 0;
            top: 38%;
            left: 50%;
            width: 42%;
            height: 35%;
            background: url('../assets/gmmc-logo1.png') center / contain no-repeat;
            opacity: .12;
            transform: translateX(-50%);
            pointer-events: none;
        }
        .sheet-content { position: relative; z-index: 1; }
        @page { size: A4 landscape; margin: 5mm; }
        @media print {
            body { background: #fff; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .toolbar { display: none; }
            .sheets { padding: 0; }
            .sheet {
                width: 287mm;
                min-height: 200mm;
                margin: 0;
                padding: 4mm;
                box-shadow: none;
                break-after: page;
                page-break-after: always;
            }
            .sheet:last-child { break-after: auto; page-break-after: auto; }
            tr { break-inside: avoid; page-break-inside: avoid; }
        }
        @media screen and (max-width: 900px) {
            .toolbar { align-items: flex-start; flex-direction: column; padding: 12px; }
            .actions { width: 100%; }
            .actions a, .actions button { flex: 1; text-align: center; }
            .sheet { width: 100%; min-height: 0; overflow-x: auto; padding: 12px; }
            table { min-width: 900px; }
        }
    </style>
</head>
<body>
    <header class="toolbar">
        <div>
            <h1><?= e($class['class_name']) ?> term summary sheets</h1>
            <p><?= e($session['session_name']) ?> · <?= count($students) ?> students · <?= count($termSheets) ?> term<?= count($termSheets) === 1 ? '' : 's' ?></p>
        </div>
        <div class="actions">
            <a href="<?= e($dashboardUrl) ?>">Back to summaries</a>
            <button type="button" onclick="window.print()">Print / Save as PDF</button>
        </div>
    </header>
    <main class="sheets">
        <?php foreach ($termSheets as $termIndex => $sheet): ?>
            <section class="sheet">
                <div class="watermark" aria-hidden="true"></div>
                <div class="sheet-content">
                    <header class="sheet-header">
                        <img src="../assets/gmmc-logo1.png" alt="">
                        <div>
                            <h1>GENERAL MURTALA MUHAMMAD COLLEGE, YOLA</h1>
                            <h2><?= e($sheet['ordinal'] . ' Term Summary Sheet') ?></h2>
                            <p><?= e($session['session_name']) ?></p>
                        </div>
                        <span></span>
                    </header>
                    <div class="class-details">
                        <span><strong>Class:</strong> <?= e($class['class_name']) ?></span>
                        <span><strong>Term:</strong> <?= e($sheet['term']['term_name']) ?></span>
                        <span><strong>Class Teacher:</strong> <?= e($sheet['class_teacher'] !== '' ? $sheet['class_teacher'] : '—') ?></span>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th class="number-column" rowspan="2">S/N</th>
                                <th class="name-column" rowspan="2">Student Data</th>
                                <?php foreach ($sheet['subjects'] as $subject): ?>
                                    <th colspan="<?= $termIndex === 0 ? 2 : 4 ?>"><?= e($subject['subject_name']) ?></th>
                                <?php endforeach; ?>
                                <th class="total-column" rowspan="2">TOTAL</th>
                                <th class="average-column" rowspan="2">Total Average</th>
                                <th class="position-column" rowspan="2">Position</th>
                            </tr>
                            <tr>
                                <?php foreach ($sheet['subjects'] as $_subject): ?>
                                    <?php if ($termIndex === 0): ?>
                                        <th>Current</th>
                                        <th>C.A. total</th>
                                    <?php else: ?>
                                        <th>Last term average</th>
                                        <th>Current term</th>
                                        <th>C.A. total</th>
                                        <th>Cumulative average</th>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sheet['students'] as $studentIndex => $student): ?>
                                <tr>
                                    <td><?= $studentIndex + 1 ?></td>
                                    <td class="student-name"><?= e($student['name']) ?></td>
                                    <?php foreach ($student['subjects'] as $subjectMark): ?>
                                        <?php if ($termIndex === 0): ?>
                                            <td><?= $subjectMark['all_components_zero'] ? '-' : e($formatMark($subjectMark['current_term'])) ?></td>
                                            <td><?= $subjectMark['all_components_zero'] ? '-' : e($formatMark($subjectMark['average'])) ?></td>
                                        <?php else: ?>
                                            <td><?= $subjectMark['all_components_zero'] ? '-' : e($formatMark($subjectMark['last_term_average'])) ?></td>
                                            <td><?= $subjectMark['all_components_zero'] ? '-' : e($formatMark($subjectMark['current_term'])) ?></td>
                                            <td><?= $subjectMark['all_components_zero'] ? '-' : e($formatMark($subjectMark['average'])) ?></td>
                                            <td><?= $subjectMark['all_components_zero'] ? '-' : e($formatMark($subjectMark['cumulative_average'])) ?></td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <td><?= e($formatMark((float) $student['total'])) ?></td>
                                    <td><?= e($formatMark((float) $student['average'])) ?></td>
                                    <td><?= $student['position'] === null ? '—' : (int) $student['position'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$sheet['students']): ?>
                                <tr>
                                    <td class="empty-row" colspan="<?= 5 + count($sheet['subjects']) * ($termIndex === 0 ? 2 : 4) ?>">
                                        No students are enrolled in this class for the selected academic session.
                                    </td>
                                </tr>
                            <?php elseif (!$sheet['subjects']): ?>
                                <tr>
                                    <td class="empty-row" colspan="5">No subjects are allocated to this class for this term.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="signatures">
                        <div class="signature">Class Teacher's Signature / Date</div>
                        <div class="signature">Principal's Signature / Stamp / Date</div>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </main>
</body>
</html>
