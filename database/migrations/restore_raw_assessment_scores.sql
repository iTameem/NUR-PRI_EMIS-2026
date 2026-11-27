ALTER TABLE results
    DROP CONSTRAINT chk_result_first_test,
    DROP CONSTRAINT chk_result_second_test,
    DROP CONSTRAINT chk_result_exam;

UPDATE results
SET first_test = ROUND(first_test * 5, 2)
WHERE first_test IS NOT NULL;

UPDATE results
SET second_test = ROUND(second_test * 5, 2)
WHERE second_test IS NOT NULL;

UPDATE results
SET exam = ROUND(exam / 0.60, 2)
WHERE exam IS NOT NULL;

UPDATE results
SET score = ROUND(first_test + second_test + exam, 2)
WHERE first_test IS NOT NULL
  AND second_test IS NOT NULL
  AND exam IS NOT NULL;

ALTER TABLE results
    ADD CONSTRAINT chk_result_first_test
        CHECK (first_test IS NULL OR first_test BETWEEN 0 AND 20),
    ADD CONSTRAINT chk_result_second_test
        CHECK (second_test IS NULL OR second_test BETWEEN 0 AND 20),
    ADD CONSTRAINT chk_result_exam
        CHECK (exam IS NULL OR exam BETWEEN 0 AND 60);
