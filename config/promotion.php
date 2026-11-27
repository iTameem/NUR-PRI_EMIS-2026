<?php
declare(strict_types=1);

const STUDENT_PROMOTION_MINIMUM_AVERAGE = 40.0;

function promotion_class_key(string $className): string
{
    return strtolower((string) preg_replace('/[\s_-]+/', '', $className));
}

function promotion_report_average(array $terms, array $subjectIds, array $scoresByTermAndSubject): float
{
    if (count($terms) < 3 || $subjectIds === []) {
        return 0.0;
    }

    $thirdTermId = (int) $terms[2]['id'];
    $totalScore = 0.0;
    foreach ($subjectIds as $subjectId) {
        $previousCumulativeScore = null;
        $reportScore = null;
        foreach (array_slice($terms, 0, 3) as $term) {
            $termId = (int) $term['id'];
            $score = $scoresByTermAndSubject[$termId][$subjectId] ?? null;
            $firstTest = $score !== null && $score['first_test'] !== null
                ? (float) $score['first_test']
                : null;
            $secondTest = $score !== null && $score['second_test'] !== null
                ? (float) $score['second_test']
                : null;
            $exam = $score !== null && $score['exam'] !== null
                ? (float) $score['exam']
                : null;
            $assessmentAverage = $firstTest !== null && $secondTest !== null
                ? round($firstTest + $secondTest, 2)
                : null;
            $overall = $assessmentAverage !== null && $exam !== null
                ? round($assessmentAverage + $exam, 2)
                : null;
            $cumulativeScore = $previousCumulativeScore === null
                ? $overall
                : round((($overall ?? 0) + $previousCumulativeScore) / 2, 2);

            if ($termId === $thirdTermId) {
                $reportScore = $cumulativeScore;
                break;
            }

            $previousCumulativeScore = $cumulativeScore;
        }

        if ($reportScore !== null) {
            $totalScore += $reportScore;
        }
    }

    return (float) number_format($totalScore / count($subjectIds), 1, '.', '');
}

function get_student_promotion_context(mysqli $connection, int $sourceSessionId, int $targetSessionId): array
{
    $sourceSessionQuery = mysqli_prepare(
        $connection,
        'SELECT id, session_name, starts_on, is_active
         FROM academic_sessions
         WHERE id = ?'
    );
    mysqli_stmt_bind_param($sourceSessionQuery, 'i', $sourceSessionId);
    mysqli_stmt_execute($sourceSessionQuery);
    $sourceSession = mysqli_fetch_assoc(mysqli_stmt_get_result($sourceSessionQuery));
    mysqli_stmt_close($sourceSessionQuery);
    if (!$sourceSession || (int) $sourceSession['is_active'] !== 1) {
        throw new InvalidArgumentException('The source session must be the active academic session.');
    }

    $targetSessionQuery = mysqli_prepare(
        $connection,
        'SELECT id, session_name, starts_on
         FROM academic_sessions
         WHERE id = ?'
    );
    mysqli_stmt_bind_param($targetSessionQuery, 'i', $targetSessionId);
    mysqli_stmt_execute($targetSessionQuery);
    $targetSession = mysqli_fetch_assoc(mysqli_stmt_get_result($targetSessionQuery));
    mysqli_stmt_close($targetSessionQuery);
    if (!$targetSession || (int) $targetSession['id'] === $sourceSessionId
        || $targetSession['starts_on'] <= $sourceSession['starts_on']) {
        throw new InvalidArgumentException('Choose a later academic session as the promotion destination.');
    }

    $termsQuery = mysqli_prepare(
        $connection,
        'SELECT id, term_name, starts_on, ends_on
         FROM academic_terms
         WHERE academic_session_id = ?
         ORDER BY starts_on, id'
    );
    mysqli_stmt_bind_param($termsQuery, 'i', $sourceSessionId);
    mysqli_stmt_execute($termsQuery);
    $terms = mysqli_fetch_all(mysqli_stmt_get_result($termsQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($termsQuery);
    if (count($terms) < 3) {
        throw new InvalidArgumentException('The active session must have three academic terms before promotion.');
    }
    $thirdTerm = $terms[2];
    if ($thirdTerm['ends_on'] > date('Y-m-d')) {
        throw new InvalidArgumentException('Promotion is available after the third term has ended.');
    }

    $classesQuery = mysqli_prepare($connection, 'SELECT id, class_name FROM classes ORDER BY class_name');
    mysqli_stmt_execute($classesQuery);
    $classes = mysqli_fetch_all(mysqli_stmt_get_result($classesQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($classesQuery);

    $progression = ['ss1', 'ss2', 'ss3'];
    $classRankById = [];
    $classByRank = [];
    $classById = [];
    foreach ($classes as $class) {
        $classId = (int) $class['id'];
        $classById[$classId] = $class;
        $rank = array_search(promotion_class_key((string) $class['class_name']), $progression, true);
        if ($rank === false) {
            continue;
        }
        if (isset($classByRank[$rank]) && (int) $classByRank[$rank]['id'] !== $classId) {
            throw new InvalidArgumentException('More than one class matches the same promotion level. Resolve duplicate class names first.');
        }
        $classRankById[$classId] = $rank;
        $classByRank[$rank] = $class;
    }

    $nextClassById = [];
    foreach ($classRankById as $classId => $rank) {
        if (isset($classByRank[$rank + 1])) {
            $nextClassById[$classId] = (int) $classByRank[$rank + 1]['id'];
        }
    }

    $subjectsQuery = mysqli_prepare(
        $connection,
        'SELECT DISTINCT tc.class_id, sub.id AS subject_id, sub.subject_name
         FROM teacher_class_allocations tc
         INNER JOIN subjects sub ON sub.id = tc.subject_id
         WHERE tc.academic_session_id = ? AND tc.term_id = ?
         ORDER BY tc.class_id, sub.subject_name'
    );
    $thirdTermId = (int) $thirdTerm['id'];
    mysqli_stmt_bind_param($subjectsQuery, 'ii', $sourceSessionId, $thirdTermId);
    mysqli_stmt_execute($subjectsQuery);
    $subjectRows = mysqli_fetch_all(mysqli_stmt_get_result($subjectsQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($subjectsQuery);
    $subjectIdsByClass = [];
    foreach ($subjectRows as $subjectRow) {
        $classId = (int) $subjectRow['class_id'];
        if (isset($nextClassById[$classId])) {
            $subjectIdsByClass[$classId][] = (int) $subjectRow['subject_id'];
        }
    }

    $studentAllocationsQuery = mysqli_prepare(
        $connection,
        'SELECT sc.student_id, sc.class_id, s.fullname, c.class_name
         FROM student_class_allocations sc
         INNER JOIN students s ON s.id = sc.student_id
         INNER JOIN classes c ON c.id = sc.class_id
         WHERE sc.academic_session_id = ?
         ORDER BY c.class_name, s.fullname, sc.student_id'
    );
    mysqli_stmt_bind_param($studentAllocationsQuery, 'i', $sourceSessionId);
    mysqli_stmt_execute($studentAllocationsQuery);
    $studentAllocations = mysqli_fetch_all(mysqli_stmt_get_result($studentAllocationsQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($studentAllocationsQuery);

    $scoresByStudentAndClass = [];
    $firstTermId = (int) $terms[0]['id'];
    $secondTermId = (int) $terms[1]['id'];
    foreach (array_keys($nextClassById) as $classId) {
        $scoresQuery = mysqli_prepare(
            $connection,
            'SELECT student_id, class_id, subject_id, term_id, first_test, second_test, exam
             FROM results
             WHERE academic_session_id = ? AND class_id = ?
               AND term_id IN (?, ?, ?)'
        );
        mysqli_stmt_bind_param(
            $scoresQuery,
            'iiiii',
            $sourceSessionId,
            $classId,
            $firstTermId,
            $secondTermId,
            $thirdTermId
        );
        mysqli_stmt_execute($scoresQuery);
        $scoreRows = mysqli_fetch_all(mysqli_stmt_get_result($scoresQuery), MYSQLI_ASSOC);
        mysqli_stmt_close($scoresQuery);
        foreach ($scoreRows as $scoreRow) {
            $scoresByStudentAndClass[(int) $scoreRow['class_id']][(int) $scoreRow['student_id']]
                [(int) $scoreRow['term_id']][(int) $scoreRow['subject_id']] = $scoreRow;
        }
    }

    $targetAllocationsQuery = mysqli_prepare(
        $connection,
        'SELECT sc.student_id, sc.class_id, c.class_name
         FROM student_class_allocations sc
         INNER JOIN classes c ON c.id = sc.class_id
         WHERE sc.academic_session_id = ?'
    );
    mysqli_stmt_bind_param($targetAllocationsQuery, 'i', $targetSessionId);
    mysqli_stmt_execute($targetAllocationsQuery);
    $targetAllocations = mysqli_fetch_all(mysqli_stmt_get_result($targetAllocationsQuery), MYSQLI_ASSOC);
    mysqli_stmt_close($targetAllocationsQuery);
    $targetAllocationByStudent = [];
    foreach ($targetAllocations as $allocation) {
        $targetAllocationByStudent[(int) $allocation['student_id']] = $allocation;
    }

    $candidates = [];
    foreach ($studentAllocations as $student) {
        $studentId = (int) $student['student_id'];
        $classId = (int) $student['class_id'];
        if (!isset($nextClassById[$classId], $subjectIdsByClass[$classId])) {
            continue;
        }
        $scores = $scoresByStudentAndClass[$classId][$studentId] ?? [];
        $average = promotion_report_average($terms, $subjectIdsByClass[$classId], $scores);
        if ($average < STUDENT_PROMOTION_MINIMUM_AVERAGE) {
            continue;
        }

        $targetClassId = $nextClassById[$classId];
        $existingTargetAllocation = $targetAllocationByStudent[$studentId] ?? null;
        $candidates[] = [
            'student_id' => $studentId,
            'student_name' => $student['fullname'],
            'current_class_id' => $classId,
            'current_class_name' => $student['class_name'],
            'target_class_id' => $targetClassId,
            'target_class_name' => $classById[$targetClassId]['class_name'],
            'average' => $average,
            'existing_class_id' => $existingTargetAllocation !== null
                ? (int) $existingTargetAllocation['class_id']
                : null,
            'existing_class_name' => $existingTargetAllocation['class_name'] ?? null,
        ];
    }

    return [
        'source_session' => $sourceSession,
        'target_session' => $targetSession,
        'third_term' => $thirdTerm,
        'candidates' => $candidates,
    ];
}
