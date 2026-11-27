CREATE DATABASE IF NOT EXISTS gmmc_result_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE gmmc_result_db;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fullname VARCHAR(150) NOT NULL,
    phone_No VARCHAR(30) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'teacher') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_phone_no (phone_No),
    KEY idx_users_role_active (role, is_active)
) ENGINE=InnoDB;

CREATE TABLE academic_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_name VARCHAR(50) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_academic_sessions_name (session_name),
    CONSTRAINT chk_academic_session_dates CHECK (ends_on >= starts_on)
) ENGINE=InnoDB;

CREATE TABLE classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_classes_name (class_name)
) ENGINE=InnoDB;

CREATE TABLE students (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fullname VARCHAR(200) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_students_fullname (fullname)
) ENGINE=InnoDB;

CREATE TABLE student_class_allocations (
    student_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    academic_session_id BIGINT UNSIGNED NOT NULL,
    allocated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, academic_session_id),
    UNIQUE KEY uq_student_class_session (student_id, class_id, academic_session_id),
    KEY idx_student_class_session_class (class_id, academic_session_id),
    CONSTRAINT fk_student_alloc_student FOREIGN KEY (student_id)
        REFERENCES students (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_student_alloc_class FOREIGN KEY (class_id)
        REFERENCES classes (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_student_alloc_session FOREIGN KEY (academic_session_id)
        REFERENCES academic_sessions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_student_alloc_admin FOREIGN KEY (allocated_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_name VARCHAR(150) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE academic_terms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    academic_session_id BIGINT UNSIGNED NOT NULL,
    term_name VARCHAR(100) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_terms_session_name (academic_session_id, term_name),
    UNIQUE KEY uq_terms_id_session (id, academic_session_id),
    KEY idx_terms_session_active_dates (academic_session_id, is_active, starts_on, ends_on),
    CONSTRAINT fk_terms_session FOREIGN KEY (academic_session_id)
        REFERENCES academic_sessions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_term_dates CHECK (ends_on >= starts_on)
) ENGINE=InnoDB;

-- A teacher can have multiple subject/class assignments in a term.
-- The unique class-subject-term key below ensures only one teacher per class and subject.
CREATE TABLE teacher_class_allocations (
    teacher_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    academic_session_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    allocated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (teacher_id, class_id, academic_session_id, subject_id, term_id),
    UNIQUE KEY uq_class_subject_term (class_id, academic_session_id, subject_id, term_id),
    KEY idx_teacher_allocation_term (teacher_id, academic_session_id, term_id),
    KEY idx_class_allocation_term (class_id, academic_session_id, term_id),
    CONSTRAINT fk_teacher_alloc_teacher FOREIGN KEY (teacher_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_alloc_class FOREIGN KEY (class_id)
        REFERENCES classes (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_alloc_session FOREIGN KEY (academic_session_id)
        REFERENCES academic_sessions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_alloc_subject FOREIGN KEY (subject_id)
        REFERENCES subjects (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_alloc_term_session FOREIGN KEY (term_id, academic_session_id)
        REFERENCES academic_terms (id, academic_session_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_teacher_alloc_admin FOREIGN KEY (allocated_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE class_teacher_allocations (
    teacher_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    academic_session_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    allocated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (teacher_id, class_id, academic_session_id, term_id),
    UNIQUE KEY uq_class_teacher_class_term (class_id, academic_session_id, term_id),
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

CREATE TABLE results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    academic_session_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    term_id BIGINT UNSIGNED NOT NULL,
    teacher_id BIGINT UNSIGNED NOT NULL,
    first_test DECIMAL(5,2) NULL,
    second_test DECIMAL(5,2) NULL,
    exam DECIMAL(5,2) NULL,
    score DECIMAL(5,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_result_student_subject_term (student_id, subject_id, term_id),
    KEY idx_results_term_created (term_id, created_at),
    KEY idx_results_teacher_created (teacher_id, created_at),
    KEY idx_results_student_class_session (student_id, class_id, academic_session_id),
    KEY idx_results_teacher_class_session (teacher_id, class_id, academic_session_id),
    CONSTRAINT chk_result_score CHECK (score >= 0 AND score <= 100),
    CONSTRAINT chk_result_first_test CHECK (first_test IS NULL OR first_test BETWEEN 0 AND 20),
    CONSTRAINT chk_result_second_test CHECK (second_test IS NULL OR second_test BETWEEN 0 AND 20),
    CONSTRAINT chk_result_exam CHECK (exam IS NULL OR exam BETWEEN 0 AND 60),
    CONSTRAINT fk_results_student_class_session
        FOREIGN KEY (student_id, class_id, academic_session_id)
        REFERENCES student_class_allocations (student_id, class_id, academic_session_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_results_teacher_allocation
        FOREIGN KEY (teacher_id, class_id, academic_session_id, subject_id, term_id)
        REFERENCES teacher_class_allocations
            (teacher_id, class_id, academic_session_id, subject_id, term_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_results_subject FOREIGN KEY (subject_id)
        REFERENCES subjects (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_results_term_session FOREIGN KEY (term_id, academic_session_id)
        REFERENCES academic_terms (id, academic_session_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_results_teacher FOREIGN KEY (teacher_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

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

CREATE TABLE teacher_activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    teacher_id BIGINT UNSIGNED NOT NULL,
    teacher_name VARCHAR(150) NOT NULL,
    event_type ENUM('login', 'score_upload') NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NOT NULL DEFAULT '',
    class_id BIGINT UNSIGNED NULL,
    subject_id BIGINT UNSIGNED NULL,
    term_id BIGINT UNSIGNED NULL,
    class_name VARCHAR(100) NULL,
    subject_name VARCHAR(150) NULL,
    term_name VARCHAR(100) NULL,
    session_name VARCHAR(50) NULL,
    student_entry_count INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_teacher_activity_type_created (event_type, created_at),
    KEY idx_teacher_activity_teacher_type_created (teacher_id, event_type, created_at)
) ENGINE=InnoDB;
