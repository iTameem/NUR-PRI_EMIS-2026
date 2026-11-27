CREATE TABLE class_teacher_allocations (
    teacher_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    academic_session_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    allocated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (teacher_id, class_id, academic_session_id, term_id),
    KEY idx_class_teacher_class_term (class_id, academic_session_id, term_id),
    KEY idx_class_teacher_teacher_term (teacher_id, academic_session_id, term_id),
    CONSTRAINT fk_class_teacher_teacher FOREIGN KEY (teacher_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_class_teacher_class FOREIGN KEY (class_id)
        REFERENCES classes (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_class_teacher_session FOREIGN KEY (academic_session_id)
        REFERENCES academic_sessions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_class_teacher_term_session FOREIGN KEY (term_id, academic_session_id)
        REFERENCES academic_terms (id, academic_session_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_class_teacher_admin FOREIGN KEY (allocated_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;
