ALTER TABLE teacher_score_edit_approvals
    ADD COLUMN edit_approved TINYINT(1) NOT NULL DEFAULT 0
    AFTER scores_submitted;

UPDATE teacher_score_edit_approvals
SET edit_approved = 1
WHERE edit_attempts_remaining > 0;

ALTER TABLE teacher_score_edit_approvals
    DROP INDEX idx_score_edit_approvals_status,
    DROP COLUMN edit_attempts_remaining,
    ADD KEY idx_score_edit_approvals_status (edit_approved, updated_at);
