ALTER TABLE teacher_class_allocations
    ADD UNIQUE KEY uq_class_subject_term (class_id, academic_session_id, subject_id, term_id);
