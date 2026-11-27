ALTER TABLE teacher_score_edit_approvals
    ADD COLUMN edit_attempts_remaining TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER scores_submitted;

UPDATE teacher_score_edit_approvals
SET edit_attempts_remaining = 6
WHERE edit_approved = 1;

ALTER TABLE teacher_score_edit_approvals
    DROP INDEX idx_score_edit_approvals_status,
    DROP COLUMN edit_approved,
    ADD KEY idx_score_edit_approvals_status (edit_attempts_remaining, updated_at);
