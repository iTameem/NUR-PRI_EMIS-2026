ALTER TABLE results
    DROP FOREIGN KEY fk_results_teacher_class_session;

RENAME TABLE teacher_class_allocations TO teacher_class_allocations_legacy;

-- A teacher can have multiple subject/class assignments in a term.
CREATE TABLE teacher_class_allocations (
    teacher_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    academic_session_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    allocated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (teacher_id, class_id, academic_session_id, subject_id, term_id),
    KEY idx_teacher_allocation_term (teacher_id, academic_session_id, term_id),
    KEY idx_class_allocation_term (class_id, academic_session_id, term_id),
    CONSTRAINT fk_teacher_subject_alloc_teacher FOREIGN KEY (teacher_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_subject_alloc_class FOREIGN KEY (class_id)
        REFERENCES classes (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_subject_alloc_session FOREIGN KEY (academic_session_id)
        REFERENCES academic_sessions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_subject_alloc_subject FOREIGN KEY (subject_id)
        REFERENCES subjects (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_subject_alloc_term_session FOREIGN KEY (term_id, academic_session_id)
        REFERENCES academic_terms (id, academic_session_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_subject_alloc_admin FOREIGN KEY (allocated_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO teacher_class_allocations
    (teacher_id, class_id, academic_session_id, subject_id, term_id, allocated_by)
SELECT DISTINCT teacher_id, class_id, academic_session_id, subject_id, term_id, NULL
FROM results;

ALTER TABLE results
    ADD CONSTRAINT fk_results_teacher_allocation
        FOREIGN KEY (teacher_id, class_id, academic_session_id, subject_id, term_id)
        REFERENCES teacher_class_allocations
            (teacher_id, class_id, academic_session_id, subject_id, term_id)
        ON UPDATE CASCADE ON DELETE RESTRICT;
