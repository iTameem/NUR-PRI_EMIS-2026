<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_role('admin');

$studentId = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT);
$termId = filter_var($_GET['term_id'] ?? null, FILTER_VALIDATE_INT);
$isEmbeddedReport = ($_GET['embed'] ?? '') === '1';
if ($isEmbeddedReport) {
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");
}
if (!is_int($studentId) || $studentId < 1 || !is_int($termId) || $termId < 1) {
    http_response_code(400);
    exit('A valid student and academic term are required.');
}

$termQuery = mysqli_prepare(
    $conn,
    'SELECT t.id, t.term_name, t.starts_on, t.ends_on, t.academic_session_id,
            a.session_name
     FROM academic_terms t
     INNER JOIN academic_sessions a ON a.id = t.academic_session_id
     WHERE t.id = ?'
);
mysqli_stmt_bind_param($termQuery, 'i', $termId);
mysqli_stmt_execute($termQuery);
$term = mysqli_fetch_assoc(mysqli_stmt_get_result($termQuery));
mysqli_stmt_close($termQuery);
if (!$term) {
    http_response_code(404);
    exit('The selected academic term was not found.');
}

$sessionId = (int) $term['academic_session_id'];
$studentQuery = mysqli_prepare(
    $conn,
    'SELECT s.id, s.fullname, c.id AS class_id, c.class_name
     FROM students s
     INNER JOIN student_class_allocations sc ON sc.student_id = s.id
     INNER JOIN classes c ON c.id = sc.class_id
     WHERE s.id = ? AND sc.academic_session_id = ?'
);
mysqli_stmt_bind_param($studentQuery, 'ii', $studentId, $sessionId);
mysqli_stmt_execute($studentQuery);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($studentQuery));
mysqli_stmt_close($studentQuery);
if (!$student) {
    http_response_code(404);
    exit('The student is not enrolled in the selected academic session.');
}

$classId = (int) $student['class_id'];
$classTeachersQuery = mysqli_prepare(
    $conn,
    'SELECT GROUP_CONCAT(u.fullname ORDER BY u.fullname SEPARATOR ", ") AS teacher_names
     FROM class_teacher_allocations ct
     INNER JOIN users u ON u.id = ct.teacher_id
     WHERE ct.class_id = ? AND ct.academic_session_id = ? AND ct.term_id = ?'
);
mysqli_stmt_bind_param($classTeachersQuery, 'iii', $classId, $sessionId, $termId);
mysqli_stmt_execute($classTeachersQuery);
$classTeachersRow = mysqli_fetch_assoc(mysqli_stmt_get_result($classTeachersQuery));
mysqli_stmt_close($classTeachersQuery);
$classTeacherNames = (string) ($classTeachersRow['teacher_names'] ?? '');

$sessionTermsQuery = mysqli_prepare(
    $conn,
    'SELECT id, term_name, starts_on
     FROM academic_terms
     WHERE academic_session_id = ?
     ORDER BY starts_on, id'
);
mysqli_stmt_bind_param($sessionTermsQuery, 'i', $sessionId);
mysqli_stmt_execute($sessionTermsQuery);
$sessionTerms = mysqli_fetch_all(mysqli_stmt_get_result($sessionTermsQuery), MYSQLI_ASSOC);
mysqli_stmt_close($sessionTermsQuery);
$termPosition = 1;
foreach ($sessionTerms as $index => $sessionTerm) {
    if ((int) $sessionTerm['id'] === $termId) {
        $termPosition = $index + 1;
        break;
    }
}
$showCumulative = $termPosition > 1;
$showPromotion = $termPosition === 3;

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
$subjects = mysqli_fetch_all(mysqli_stmt_get_result($subjectsQuery), MYSQLI_ASSOC);
mysqli_stmt_close($subjectsQuery);

$historicalScoresQuery = mysqli_prepare(
    $conn,
    'SELECT r.term_id, r.subject_id, r.first_test, r.second_test, r.exam
     FROM results r
     INNER JOIN academic_terms t ON t.id = r.term_id
     WHERE r.student_id = ? AND r.class_id = ? AND r.academic_session_id = ?
       AND t.starts_on <= ?
     ORDER BY t.starts_on, t.id, r.subject_id'
);
$termStartDate = (string) $term['starts_on'];
mysqli_stmt_bind_param(
    $historicalScoresQuery,
    'iiis',
    $studentId,
    $classId,
    $sessionId,
    $termStartDate
);
mysqli_stmt_execute($historicalScoresQuery);
$historicalScoreRows = mysqli_fetch_all(mysqli_stmt_get_result($historicalScoresQuery), MYSQLI_ASSOC);
mysqli_stmt_close($historicalScoresQuery);
$scoresByTermAndSubject = [];
foreach ($historicalScoreRows as $historicalScoreRow) {
    $scoresByTermAndSubject[(int) $historicalScoreRow['term_id']][(int) $historicalScoreRow['subject_id']] = $historicalScoreRow;
}

$classSizeQuery = mysqli_prepare(
    $conn,
    'SELECT COUNT(*) AS total
     FROM student_class_allocations
     WHERE class_id = ? AND academic_session_id = ?'
);
mysqli_stmt_bind_param($classSizeQuery, 'ii', $classId, $sessionId);
mysqli_stmt_execute($classSizeQuery);
$classSizeRow = mysqli_fetch_assoc(mysqli_stmt_get_result($classSizeQuery));
mysqli_stmt_close($classSizeQuery);
$classSize = (int) $classSizeRow['total'];

$positionQuery = mysqli_prepare(
    $conn,
    'SELECT sc.student_id, COALESCE(SUM(r.score), 0) AS total_score
     FROM student_class_allocations sc
     LEFT JOIN results r ON r.student_id = sc.student_id
         AND r.class_id = sc.class_id
         AND r.academic_session_id = sc.academic_session_id
         AND r.term_id = ?
     WHERE sc.class_id = ? AND sc.academic_session_id = ?
     GROUP BY sc.student_id
     ORDER BY total_score DESC, sc.student_id'
);
mysqli_stmt_bind_param($positionQuery, 'iii', $termId, $classId, $sessionId);
mysqli_stmt_execute($positionQuery);
$positionRows = mysqli_fetch_all(mysqli_stmt_get_result($positionQuery), MYSQLI_ASSOC);
mysqli_stmt_close($positionQuery);
$position = '—';
$rank = 0;
$rowNumber = 0;
$previousTotal = null;
foreach ($positionRows as $positionRow) {
    $totalScore = (float) $positionRow['total_score'];
    if ($previousTotal === null || $totalScore < $previousTotal) {
        $rank = $rowNumber + 1;
    }
    if ((int) $positionRow['student_id'] === $studentId) {
        $position = (string) $rank;
        break;
    }
    $previousTotal = $totalScore;
    $rowNumber++;
}

$nextTermQuery = mysqli_prepare(
    $conn,
    'SELECT starts_on
     FROM academic_terms
     WHERE starts_on > ?
     ORDER BY starts_on, id
     LIMIT 1'
);
$termEndDate = (string) $term['ends_on'];
mysqli_stmt_bind_param($nextTermQuery, 's', $termEndDate);
mysqli_stmt_execute($nextTermQuery);
$nextTermRow = mysqli_fetch_assoc(mysqli_stmt_get_result($nextTermQuery));
mysqli_stmt_close($nextTermQuery);

$formatDate = static function (?string $date): string {
    if ($date === null || $date === '') {
        return '—';
    }

    $dateValue = new DateTimeImmutable($date);
    $day = (int) $dateValue->format('j');
    $lastTwoDigits = $day % 100;
    $suffix = ($lastTwoDigits >= 11 && $lastTwoDigits <= 13)
        ? 'th'
        : match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };

    return $day . $suffix . ' ' . $dateValue->format('F, Y');
};
$formatMark = static function (?float $mark): string {
    return $mark === null
        ? '—'
        : rtrim(rtrim(number_format($mark, 2, '.', ''), '0'), '.');
};
$gradeForScore = static function (?float $score): array {
    if ($score === null) {
        return ['', ''];
    }
    if ($score >= 80) {
        return ['A', 'Excellent'];
    }
    if ($score >= 60) {
        return ['B', 'V.Good'];
    }
    if ($score >= 50) {
        return ['C', 'Good'];
    }
    if ($score >= 45) {
        return ['D', 'Pass'];
    }
    if ($score >= 40) {
        return ['E', 'Pass'];
    }
    return ['F', 'Fail'];
};

$reportRows = [];
$totalScore = 0;
foreach ($subjects as $subject) {
    $subjectId = (int) $subject['id'];
    $previousCumulativeScore = null;
    $firstTest = null;
    $secondTest = null;
    $assessmentAverage = null;
    $exam = null;
    $overall = null;
    $lastTermScore = null;
    $cumulativeScore = null;
    $reportScore = null;

    foreach ($sessionTerms as $sessionTerm) {
        $sessionTermId = (int) $sessionTerm['id'];
        $termScoreRow = $scoresByTermAndSubject[$sessionTermId][$subjectId] ?? null;
        $termFirstTest = $termScoreRow !== null && $termScoreRow['first_test'] !== null
            ? (float) $termScoreRow['first_test']
            : null;
        $termSecondTest = $termScoreRow !== null && $termScoreRow['second_test'] !== null
            ? (float) $termScoreRow['second_test']
            : null;
        $termExam = $termScoreRow !== null && $termScoreRow['exam'] !== null
            ? (float) $termScoreRow['exam']
            : null;
        $termAssessmentAverage = $termFirstTest !== null && $termSecondTest !== null
            ? round($termFirstTest + $termSecondTest, 2)
            : null;
        $termOverall = $termAssessmentAverage !== null && $termExam !== null
            ? round($termAssessmentAverage + $termExam, 2)
            : null;
        $termCumulativeScore = $previousCumulativeScore === null
            ? $termOverall
            : round((($termOverall ?? 0) + $previousCumulativeScore) / 2, 2);

        if ($sessionTermId === $termId) {
            $firstTest = $termFirstTest;
            $secondTest = $termSecondTest;
            $assessmentAverage = $termAssessmentAverage;
            $exam = $termExam;
            $overall = $termOverall;
            $lastTermScore = $showCumulative ? $previousCumulativeScore : null;
            $cumulativeScore = $showCumulative ? $termCumulativeScore : null;
            $reportScore = $showCumulative ? $cumulativeScore : $overall;
            break;
        }

        $previousCumulativeScore = $termCumulativeScore;
    }

    if ($reportScore !== null) {
        $totalScore += $reportScore;
    }
    [$grade, $remark] = $gradeForScore($reportScore);

    $reportRows[] = [
        'subject_name' => $subject['subject_name'],
        'first_test' => $firstTest,
        'second_test' => $secondTest,
        'assessment_average' => $assessmentAverage,
        'exam' => $exam,
        'overall' => $overall,
        'last_term_score' => $lastTermScore,
        'cumulative_score' => $cumulativeScore,
        'report_score' => $reportScore,
        'all_components_zero' => $firstTest !== null
            && $secondTest !== null
            && $exam !== null
            && $firstTest === 0.0
            && $secondTest === 0.0
            && $exam === 0.0,
        'grade' => $grade,
        'remark' => $remark,
    ];
}
$averageScore = $subjects
    ? number_format($totalScore / count($subjects), 1, '.', '')
    : '0.0';
$averageScoreValue = (float) $averageScore;
$performanceRemarks = match (true) {
    $averageScoreValue >= 90 => [
        ['title' => 'Outstanding', 'message' => 'Your dedication is inspiring. Keep shining!'],
        ['title' => 'Exceptional', 'message' => 'A remarkable achievement. Keep reaching higher!'],
        ['title' => 'Superb', 'message' => 'Your hard work has brought excellent results!'],
    ],
    $averageScoreValue >= 80 => [
        ['title' => 'Superb', 'message' => 'Keep believing in yourself and aiming high!'],
        ['title' => 'Excellent', 'message' => 'Your effort is paying off. Keep it up!'],
        ['title' => 'Brilliant', 'message' => 'Wonderful work. Keep growing and succeeding!'],
    ],
    $averageScoreValue >= 70 => [
        ['title' => 'Bravo', 'message' => 'You are making wonderful progress!'],
        ['title' => 'Great Work', 'message' => 'Your commitment is showing. Keep going!'],
        ['title' => 'Strong Progress', 'message' => 'Keep learning and reaching for more!'],
    ],
    $averageScoreValue >= 60 => [
        ['title' => 'Very Good', 'message' => 'Keep building on this positive progress!'],
        ['title' => 'Well Done', 'message' => 'Stay curious and keep moving forward!'],
        ['title' => 'Good Progress', 'message' => 'Your steady effort is making a difference!'],
    ],
    $averageScoreValue >= 50 => [
        ['title' => 'Good Effort', 'message' => 'Keep practicing and growing every day!'],
        ['title' => 'Keep Rising', 'message' => 'Your persistence will take you far!'],
        ['title' => 'Promising', 'message' => 'Stay focused; you are moving forward!'],
    ],
    $averageScoreValue >= 40 => [
        ['title' => 'Keep Going', 'message' => 'Every effort helps you improve. You can do it!'],
        ['title' => 'Keep Growing', 'message' => 'Practice step by step and keep believing!'],
        ['title' => 'Stay Encouraged', 'message' => 'Each new lesson is a fresh opportunity!'],
    ],
    default => [
        ['title' => 'Keep Trying', 'message' => 'Every day is a new chance to learn and improve!'],
        ['title' => 'Believe in Yourself', 'message' => 'Keep practicing; progress comes one step at a time!'],
        ['title' => 'Keep Learning', 'message' => 'Your effort matters. Take the next step with confidence!'],
    ],
};
$performanceRemark = $performanceRemarks[(int) $student['id'] % count($performanceRemarks)];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Report sheet | <?= e($student['fullname']) ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #e5e7eb;
            color: #111;
            font-family: Arial, Helvetica, sans-serif;
        }

        .print-actions {
            display: flex;
            justify-content: center;
            gap: 12px;
            padding: 16px;
        }

        .embedded-report .print-actions {
            display: none;
        }

        .print-actions a,
        .print-actions button {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            color: #1e293b;
            cursor: pointer;
            font: 600 14px Arial, sans-serif;
            padding: 9px 14px;
            text-decoration: none;
        }

        .print-actions button {
            border-color: #4338ca;
            background: #4338ca;
            color: #fff;
        }

        .print-actions button:disabled {
            cursor: wait;
            opacity: 0.65;
        }

        .report {
            position: relative;
            width: 202mm;
            min-height: 289mm;
            margin: 0 auto 20px;
            background: #fff;
            padding: 3mm 4mm 4mm;
        }

        .watermark {
            position: absolute;
            z-index: 0;
            top: 37%;
            left: 50%;
            width: 48%;
            height: 36%;
            background: url('../assets/gmmc-logo1.png') center / contain no-repeat;
            opacity: 0.25;
            transform: translateX(-50%);
            pointer-events: none;
        }

        .report-content {
            position: relative;
            z-index: 1;
        }

        .report-header {
            display: grid;
            grid-template-columns: 22mm minmax(0, 1fr) 22mm;
            align-items: flex-start;
            width: 100%;
            min-height: 38mm;
            margin: 0 auto 1mm;
            text-align: center;
        }

        .report-header img {
            width: 32mm;
            height: 32mm;
            margin-bottom: 10mm;
            margin-right: 4mm;
            object-fit: contain;
        }

        .report-header-copy {
            min-width: 0;
            grid-column: 2;
        }

        .report-header h1,
        .report-header p {
            margin: 0;
            text-transform: uppercase;
        }

        .report-header h1 {
            /* border: 1.5px solid #08713a; */
            /* border-left-width: 1.2mm;
            border-radius: 6px; */
            padding: 5.6mm 3mm;
            margin-left: 3mm;
            /* background: linear-gradient(110deg, #f1faf4 0%, #fff 70%, #fffaf0 100%); */
            /* box-shadow: inset 0 0 0 1px #d1a84c, 0 1mm 2mm rgba(6, 75, 44, .12);
            color: #064b2c; */
            font-family: "Arial", Arial, Helvetica, sans-serif;
            font-size: 15pt;
            font-weight: 800;
            letter-spacing: .09em;
            line-height: 1.26;
        }

        .report-header .school-address {
            margin-bottom: 3mm;
            color: #075b31;
            font-size: 14pt;
            line-height: .59;
            white-space: nowrap;
        }

        .report-header .report-title {
            margin-top: 2mm;
            color: #d94b2b;
            font-size: 21pt;
            font-weight: 700;
            line-height: .91;
        }

        .student-details {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 26mm;
            column-gap: 0.12in;
            font-size: 9.5pt;
            line-height: 1.65;
        }

        .qr-panel {
            grid-column: 3;
            grid-row: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1mm;
            font-size: 5.5pt;
            text-align: center;
        }

        .qr-panel canvas {
            width: 24mm !important;
            height: 24mm !important;
        }

        .qr-error {
            color: #b91c1c;
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1.35in minmax(0, 1fr);
            min-height: 0.19in;
        }

        .detail-row strong {
            font-weight: 700;
        }

        .term-title {
            margin: 0.08in 0 0.04in;
            border-bottom: 1px solid #111;
            padding-bottom: 0.025in;
            text-align: center;
            font-size: 14pt;
            font-weight: 800;
            text-transform: uppercase;
        }

        table {
            border-collapse: separate;
            border-spacing: 0;
            border-top: 1px solid #111;
            border-left: 1px solid #111;
            table-layout: fixed;
            width: 100%;
        }

        .marks-table th,
        .marks-table td,
        .summary-table th,
        .summary-table td,
        .comment-table td {
            border: 0;
            border-right: 1px solid #111;
            border-bottom: 1px solid #111;
        }

        .marks-table {
            font-size: 11pt;
        }

        .marks-table .group-row th {
            height: 0.2in;
            font-size: 7pt;
        }

        .marks-table .labels-row th {
            height: 0.75in;
            padding: 2px 1px;
            vertical-align: middle;
        }

        .marks-table .subject-heading,
        .marks-table .remark-heading {
            font-size: 11pt;
        }

        .vertical-label {
            display: inline-block;
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            white-space: nowrap;
            font-size: 10pt;
            line-height: 1;
        }

        .marks-table tbody td {
            height: 0.18in;
            padding: 1px 2px;
            text-align: center;
        }

        .marks-table .subject-cell {
            padding-left: 5px;
            text-align: left;
        }

        .summary-line {
            display: flex;
            justify-content: space-between;
            margin: 0.08in 0 0.14in;
            padding: 0 0.65in 0 1.55in;
            font-size: 11pt;
            font-weight: 800;
        }

        .lower-tables {
            display: grid;
            grid-template-columns: 1.35fr 0.82fr 0.82fr;
            align-items: start;
            gap: 0.55in;
            margin: 0 0.06in 0.12in;
        }

        .summary-table {
            font-size: 7pt;
        }

        .summary-table th,
        .summary-table td {
            height: 0.17in;
            padding: 1px 3px;
        }

        .summary-table th {
            text-align: left;
            font-size: 7pt;
        }

        .summary-table td:last-child {
            text-align: center;
        }

        .comments {
            margin: 0 0.06in;
            border: 1px solid #111;
            font-size: 7.5pt;
        }

        .comment-row {
            display: flex;
            align-items: center;
            min-height: 0.28in;
            padding: 0.04in 0.06in;
        }

        .comment-row + .comment-row {
            border-top: 1px dotted #555;
        }

        .comment-label {
            flex: 0 0 auto;
            font-weight: 700;
        }

        .comment-line {
            flex: 1;
            height: 0.12in;
            margin-left: 0.05in;
            border-bottom: 1px dotted #555;
        }

        .signature-line {
            flex: 1;
            height: 0.16in;
            margin-left: 0.08in;
            border-bottom: 1px solid #111;
        }

        .head-teacher-row {
            min-height: 0.78in;
        }

        .head-teacher-stamp {
            flex: 0 0 auto;
            width: 34mm;
            height: 17mm;
            margin-left: 0.1in;
            object-fit: contain;
        }

        @page {
            size: A4 portrait;
            margin: 4mm;
        }

        @media print {
            body {
                background: #fff;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            .print-actions {
                display: none;
            }

            .report {
                width: 202mm;
                min-height: 289mm;
                margin: 0;
                padding: 3mm 4mm 4mm;
                break-after: avoid;
                page-break-after: avoid;
            }
        }

        @media screen and (max-width: 210mm) {
            .report {
                width: 100%;
                min-height: 0;
                padding: 24px;
            }

            .student-details {
                grid-template-columns: minmax(0, 1fr) 26mm;
                column-gap: 3mm;
            }

            .detail-row {
                grid-template-columns: 1.1in minmax(0, 1fr);
            }

            .qr-panel {
                grid-column: 2;
                grid-row: 1;
            }
        }
    </style>
</head>
<body<?= $isEmbeddedReport ? ' class="embedded-report"' : '' ?>>
    <div class="print-actions">
        <a href="dashboard.php?page=results">Back to results</a>
        <button id="print-report-button" type="button" onclick="window.print()">Print report sheet</button>
    </div>
    <main class="report">
        <div class="watermark" aria-hidden="true"></div>
        <div class="report-content">
            <header class="report-header">
                <img src="../assets/gmmc-logo1.png" alt="General Murtala Mohammed College Nursery and Primary School crest">
                <div class="report-header-copy">
                    <h1>GENERAL MURTALA MOHAMMED COLLEGE<br>NURSERY&amp;PRIMARY SCHOOL, YOLA</h1>
                    <p class="school-address">P.M.B. 2013 YOLA ADAMAWA STATE OF NIGERIA</p>
                    <p class="report-title">REPORT SHEET</p>
                </div>
            </header>
            <section class="student-details" aria-label="Student information">
                <div>
                    <!-- <div class="detail-row"><span>STUDENT ROLL CALL NO:</span><strong><?= (int) $student['id'] ?></strong></div> -->
                    <div class="detail-row"><span>NAME OF STUDENT:</span><strong><?= e($student['fullname']) ?></strong></div>
                    <div class="detail-row"><span>CLASS:</span><strong><?= e($student['class_name']) ?></strong></div>
                    <div class="detail-row"><span>NO IN CLASS:</span><strong><?= $classSize ?></strong></div>
                     <div class="detail-row"><span>TERM:</span><strong><?= e($term['term_name']) ?></strong></div>
                    <?php if ($showPromotion): ?>
                        <div class="detail-row"><span>PROMOTION STATUS:</span><strong>&nbsp;</strong></div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="detail-row"><span>CLASS TEACHER:</span><strong><?= e($classTeacherNames) ?></strong></div>
                    <div class="detail-row"><span>YEAR:</span><strong><?= e($term['session_name']) ?></strong></div>
                   
                    <div class="detail-row"><span>NEXT TERM BEGINS:</span><strong><?= e($formatDate($nextTermRow['starts_on'] ?? null)) ?></strong></div>
                    <!-- <div class="detail-row"><span>POSITION STATUS:</span><strong><?= e($position) ?></strong></div> -->
                </div>
                <div
                    class="qr-panel"
                    id="student-result-qr"
                    data-student-name="<?= e($student['fullname']) ?>"
                    data-remark-title="<?= e($performanceRemark['title']) ?>"
                    data-remark-message="<?= e($performanceRemark['message']) ?>"
                >
                    <canvas id="student-result-qr-canvas" width="256" height="256" role="img"
                            aria-label="QR code containing the student's name and performance remark"></canvas>
                    <span class="qr-error" id="student-result-qr-error" hidden>QR unavailable</span>
                </div>
            </section>

            <h1 class="term-title"><?= e(strtoupper($term['term_name'])) ?>  WORK</h1>
            <table class="marks-table">
                <colgroup>
                    <?php if ($showCumulative): ?>
                        <col style="width: 24%">
                        <?php for ($column = 0; $column < 7; $column++): ?><col style="width: 7%"><?php endfor; ?>
                        <col style="width: 7%">
                        <col style="width: 20%">
                    <?php else: ?>
                        <col style="width: 26%">
                        <?php for ($column = 0; $column < 5; $column++): ?><col style="width: 8%"><?php endfor; ?>
                        <col style="width: 8%">
                        <col style="width: 26%">
                    <?php endif; ?>
                </colgroup>
                <thead>
                    <tr class="group-row">
                        <th></th>
                        <?php foreach ($showCumulative ? range('A', 'H') : range('A', 'E') as $letter): ?>
                            <th><?= $letter ?></th>
                        <?php endforeach; ?>
                        <?php if (!$showCumulative): ?><th></th><?php endif; ?>
                        <th></th>
                    </tr>
                    <tr class="labels-row">
                        <th class="subject-heading">SUBJECTS</th>
                        <th><span class="vertical-label">C.A. 1</span></th>
                        <th><span class="vertical-label">C.A. 2</span></th>
                        <th><span class="vertical-label">C.A. Total<br>(A+B)</span></th>
                        <th><span class="vertical-label">Exam</span></th>
                        <th><span class="vertical-label">Overall<br>(C+D)</span></th>
                        <?php if ($showCumulative): ?>
                            <th><span class="vertical-label">Last Term<br>Cum/BF</span></th>
                            <th><span class="vertical-label">Cumulative Score<br>(E+F)/2</span></th>
                        <?php endif; ?>
                        <th><span class="vertical-label">Grade (A-F)</span></th>
                        <th class="remark-heading">Remark</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportRows as $row): ?>
                        <tr>
                            <td class="subject-cell"><?= e($row['subject_name']) ?></td>
                            <td><?= $row['all_components_zero'] ? '-' : e($formatMark($row['first_test'])) ?></td>
                            <td><?= $row['all_components_zero'] ? '-' : e($formatMark($row['second_test'])) ?></td>
                            <td><?= $row['all_components_zero'] ? '-' : e($formatMark($row['assessment_average'])) ?></td>
                            <td><?= $row['all_components_zero'] ? '-' : e($formatMark($row['exam'])) ?></td>
                            <td><?= $row['all_components_zero'] ? '-' : e($formatMark($row['overall'])) ?></td>
                            <?php if ($showCumulative): ?>
                                <td><?= $row['all_components_zero'] ? '-' : e($formatMark($row['last_term_score'])) ?></td>
                                <td><?= $row['all_components_zero'] ? '-' : e($formatMark($row['cumulative_score'])) ?></td>
                            <?php endif; ?>
                            <td><?= $row['all_components_zero'] ? '-' : e($row['grade'] !== '' ? $row['grade'] : '—') ?></td>
                            <td><?= $row['all_components_zero'] ? '-' : e($row['remark'] !== '' ? $row['remark'] : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$reportRows): ?>
                        <tr><td colspan="10" class="subject-cell">No subjects have been allocated for this class and term.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="summary-line">
                <span>TOTAL: <?= $totalScore ?></span>
                <span>AVERAGE: <?= e($averageScore) ?></span>
            </div>

            <section class="lower-tables" aria-label="Personality and grading guides">
                <table class="summary-table">
                    <thead><tr><th>PERSONALITY</th><th>GRADE (A-E)</th></tr></thead>
                    <tbody>
                        <?php foreach (['Attendance', 'Punctuality', 'Neatness', 'Politeness', 'Self-Control', 'Relationship with others', 'Honesty'] as $trait): ?>
                            <tr><td><?= e($trait) ?></td><td>&nbsp;</td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <table class="summary-table">
                    <thead><tr><th colspan="2">SUBJECTS GRADING</th></tr></thead>
                    <tbody>
                        <tr><td>70-100</td><td>Excellent</td></tr>
                        <tr><td>60-69</td><td>V.Good</td></tr>
                        <tr><td>50-59</td><td>Good</td></tr>
                        <tr><td>45-49</td><td>Pass</td></tr>
                        <tr><td>40-44</td><td>Fair</td></tr>
                        <tr><td>0-39</td><td>Fail</td></tr>
                    </tbody>
                </table>
                <table class="summary-table">
                    <thead><tr><th colspan="2">KEYS TO GRADING</th></tr></thead>
                    <tbody>
                        <tr><td>A =</td><td>Excellent</td></tr>
                        <tr><td>B =</td><td>V.Good</td></tr>
                        <tr><td>C =</td><td>Good</td></tr>
                        <tr><td>D =</td><td>Pass</td></tr>
                        <tr><td>E =</td><td>Pass</td></tr>
                        <tr><td>F =</td><td>Fail</td></tr>
                    </tbody>
                </table>
            </section>

            <section class="comments" aria-label="Comments and signature">
                <div class="comment-row">
                    <span class="comment-label">Class Teacher's Comment:</span>
                    <span class="comment-line"></span>
                </div>
                <div class="comment-row"><span class="comment-line"></span></div>
                <div class="comment-row head-teacher-row">
                    <span class="comment-label">Head Teacher's Signature/Stamp/Date:</span>
                    <img class="head-teacher-stamp" src="../assets/head-teacher-stamp.png" alt="Head teacher's stamp">
                    <span class="signature-line"></span>
                </div>
            </section>
        </div>
    </main>
    <script src="../assets/qrcode.min.js"></script>
    <script>
        const isEmbeddedReport = <?= $isEmbeddedReport ? 'true' : 'false' ?>;
        const qrPanel = document.getElementById('student-result-qr');
        const qrCanvas = document.getElementById('student-result-qr-canvas');
        const qrError = document.getElementById('student-result-qr-error');
        const printButton = document.getElementById('print-report-button');
        function notifyReportReady(qrReady) {
            if (isEmbeddedReport && window.parent !== window) {
                window.parent.postMessage({
                    type: 'gmmc-report-ready',
                    qrReady: qrReady
                }, window.location.origin);
            }
        }

        const qrPayload = [
            'Name: ' + qrPanel.dataset.studentName,
            'Remark: ' + qrPanel.dataset.remarkTitle + ' - ' + qrPanel.dataset.remarkMessage
        ].join('\n');

        if (typeof QRCode === 'undefined' || typeof QRCode.toCanvas !== 'function') {
            console.error('The local QR code library is unavailable.');
            qrError.hidden = false;
            notifyReportReady(false);
        } else {
            printButton.disabled = true;
            QRCode.toCanvas(
                qrCanvas,
                qrPayload,
                { width: 256, margin: 2, errorCorrectionLevel: 'L' },
                function (error) {
                    if (error) {
                        console.error('Could not generate the student result QR code.', error);
                        qrError.hidden = false;
                    }
                    printButton.disabled = false;
                    notifyReportReady(!error);
                }
            );
        }
    </script>
</body>
</html>
