<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_role('admin');

$classId = filter_var($_GET['class_id'] ?? null, FILTER_VALIDATE_INT);
$termId = filter_var($_GET['term_id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($classId) || $classId < 1 || !is_int($termId) || $termId < 1) {
    http_response_code(400);
    exit('Select a class and academic term before printing reports.');
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

$termQuery = mysqli_prepare(
    $conn,
    'SELECT t.id, t.term_name, t.academic_session_id, a.session_name
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
$resultCountQuery = mysqli_prepare(
    $conn,
    'SELECT COUNT(*) AS total
     FROM results
     WHERE class_id = ? AND academic_session_id = ? AND term_id = ?'
);
mysqli_stmt_bind_param($resultCountQuery, 'iii', $classId, $sessionId, $termId);
mysqli_stmt_execute($resultCountQuery);
$resultCountRow = mysqli_fetch_assoc(mysqli_stmt_get_result($resultCountQuery));
mysqli_stmt_close($resultCountQuery);
if ((int) $resultCountRow['total'] === 0) {
    http_response_code(404);
    exit('No score records were found for the selected class and term.');
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

$resultsUrl = 'dashboard.php?' . http_build_query([
    'page' => 'results',
    'class_id' => $classId,
    'term_id' => $termId,
]);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Class reports | <?= e($class['class_name']) ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #e5e7eb;
            color: #0f172a;
            font-family: Arial, Helvetica, sans-serif;
        }

        .print-toolbar {
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

        .print-toolbar h1 {
            margin: 0;
            font-size: 17px;
        }

        .print-toolbar p {
            margin: 4px 0 0;
            color: #64748b;
            font-size: 13px;
        }

        .print-actions {
            display: flex;
            flex-shrink: 0;
            gap: 8px;
        }

        .print-actions a,
        .print-actions button {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            color: #334155;
            cursor: pointer;
            font: 600 13px Arial, sans-serif;
            padding: 9px 12px;
            text-decoration: none;
        }

        .print-actions button {
            border-color: #047857;
            background: #047857;
            color: #fff;
        }

        .print-actions button:disabled {
            cursor: wait;
            opacity: 0.55;
        }

        .report-list {
            padding: 16px 0;
        }

        .report-frame {
            display: block;
            width: 202mm;
            max-width: 100%;
            height: 289mm;
            margin: 0 auto 16px;
            border: 0;
            background: #fff;
            box-shadow: 0 2px 10px #0f172a1a;
        }

        .empty-state {
            margin: 24px auto;
            max-width: 700px;
            border-radius: 12px;
            background: #fff;
            padding: 24px;
            color: #475569;
            text-align: center;
        }

        @page {
            size: A4 portrait;
            margin: 4mm;
        }

        @media print {
            body {
                background: #fff;
            }

            .print-toolbar {
                display: none;
            }

            .report-list {
                padding: 0;
            }

            .report-frame {
                width: 202mm;
                max-width: none;
                height: 289mm;
                margin: 0 auto;
                box-shadow: none;
                break-after: page;
                page-break-after: always;
            }

            .report-frame:last-child {
                break-after: auto;
                page-break-after: auto;
            }
        }

        @media screen and (max-width: 640px) {
            .print-toolbar {
                align-items: flex-start;
                flex-direction: column;
                padding: 12px;
            }

            .print-actions {
                width: 100%;
            }

            .print-actions a,
            .print-actions button {
                flex: 1;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <header class="print-toolbar">
        <div>
            <h1><?= e($class['class_name']) ?> report sheets</h1>
            <p><?= e($term['term_name'] . ' — ' . $term['session_name']) ?> · <?= count($students) ?> students</p>
            <p id="print-status" role="status" aria-live="polite">Preparing report sheets…</p>
        </div>
        <div class="print-actions">
            <a href="<?= e($resultsUrl) ?>">Back to results</a>
            <button id="print-all-reports" type="button" disabled>Print all / Save as PDF</button>
        </div>
    </header>
    <?php if (!$students): ?>
        <p class="empty-state">There are no students enrolled in this class for the selected academic session.</p>
    <?php else: ?>
        <main class="report-list">
            <?php foreach ($students as $student): ?>
                <iframe class="report-frame" data-report-frame
                        title="<?= e('Report sheet for ' . $student['fullname']) ?>"
                        src="print-result.php?<?= e(http_build_query([
                            'student_id' => (int) $student['id'],
                            'term_id' => $termId,
                            'embed' => '1',
                        ])) ?>"></iframe>
            <?php endforeach; ?>
        </main>
    <?php endif; ?>
    <script>
        const reportFrames = Array.from(document.querySelectorAll('[data-report-frame]'));
        const printButton = document.getElementById('print-all-reports');
        const printStatus = document.getElementById('print-status');
        let preparedReports = 0;
        let reportsWithoutQr = 0;

        function finishPreparingReports() {
            if (preparedReports !== reportFrames.length || reportFrames.length === 0) {
                return;
            }

            printButton.disabled = false;
            if (reportsWithoutQr > 0) {
                printStatus.textContent = 'Some reports could not generate a QR code. Review the sheets before printing.';
                return;
            }

            printStatus.textContent = 'All report sheets are ready. Opening the print dialog…';
            window.setTimeout(function () {
                window.print();
            }, 500);
        }

        window.addEventListener('message', function (event) {
            if (event.origin !== window.location.origin
                || typeof event.data !== 'object'
                || event.data === null
                || event.data.type !== 'gmmc-report-ready') {
                return;
            }

            const reportFrame = reportFrames.find(function (frame) {
                return frame.contentWindow === event.source;
            });
            if (!reportFrame || reportFrame.dataset.reportReady === 'true') {
                return;
            }

            reportFrame.dataset.reportReady = 'true';
            preparedReports += 1;
            if (event.data.qrReady !== true) {
                reportsWithoutQr += 1;
            }
            printStatus.textContent = 'Prepared ' + preparedReports + ' of ' + reportFrames.length + ' report sheets…';
            finishPreparingReports();
        });

        printButton.addEventListener('click', function () {
            window.print();
        });

        window.addEventListener('afterprint', function () {
            printStatus.textContent = 'Print dialog closed. You can print or save the report sheets as a PDF.';
        });

        window.setTimeout(function () {
            if (preparedReports < reportFrames.length) {
                printButton.disabled = false;
                printStatus.textContent = 'Some report sheets are still loading. Review them before printing.';
            }
        }, 30000);
    </script>
</body>
</html>
