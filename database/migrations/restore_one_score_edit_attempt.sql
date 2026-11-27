UPDATE teacher_score_edit_approvals
SET edit_attempts_remaining = 1
WHERE edit_attempts_remaining > 1;

