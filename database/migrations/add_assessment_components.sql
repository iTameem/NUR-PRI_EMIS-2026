ALTER TABLE results
    ADD COLUMN first_test DECIMAL(5,2) NULL AFTER teacher_id,
    ADD COLUMN second_test DECIMAL(5,2) NULL AFTER first_test,
    ADD COLUMN exam DECIMAL(5,2) NULL AFTER second_test,
    ADD CONSTRAINT chk_result_first_test
        CHECK (first_test IS NULL OR first_test BETWEEN 0 AND 100),
    ADD CONSTRAINT chk_result_second_test
        CHECK (second_test IS NULL OR second_test BETWEEN 0 AND 100),
    ADD CONSTRAINT chk_result_exam
        CHECK (exam IS NULL OR exam BETWEEN 0 AND 100);
