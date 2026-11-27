CREATE TABLE teacher_score_edit_approvals (
    teacher_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    academic_session_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    scores_submitted TINYINT(1) NOT NULL DEFAULT 0,
    edit_approved TINYINT(1) NOT NULL DEFAULT 0,
    approved_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (teacher_id, class_id, academic_session_id, subject_id, term_id),
    KEY idx_score_edit_approvals_status (edit_approved, updated_at),
    CONSTRAINT fk_score_edit_approval_allocation
        FOREIGN KEY (teacher_id, class_id, academic_session_id, subject_id, term_id)
        REFERENCES teacher_class_allocations
            (teacher_id, class_id, academic_session_id, subject_id, term_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_score_edit_approval_admin FOREIGN KEY (approved_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO teacher_score_edit_approvals
    (teacher_id, class_id, academic_session_id, subject_id, term_id, scores_submitted)
SELECT teacher_id, class_id, academic_session_id, subject_id, term_id, 1
FROM results
GROUP BY teacher_id, class_id, academic_session_id, subject_id, term_id;
