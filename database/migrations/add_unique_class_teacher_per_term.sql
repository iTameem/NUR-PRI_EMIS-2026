ALTER TABLE class_teacher_allocations
    ADD UNIQUE KEY uq_class_teacher_class_term (class_id, academic_session_id, term_id);
