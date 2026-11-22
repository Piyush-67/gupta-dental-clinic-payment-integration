-- Additive admin dashboard schema.
-- Existing patient, payment, sequence, and webhook tables are not modified.

CREATE TABLE admin_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_username (username)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username_hash CHAR(64) NOT NULL,
    client_ip VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_login_username_attempted (username_hash, attempted_at),
    KEY idx_admin_login_ip_attempted (client_ip, attempted_at),
    KEY idx_admin_login_attempted (attempted_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_admin_id BIGINT UNSIGNED DEFAULT NULL,
    action ENUM(
        'created',
        'username_changed',
        'password_reset',
        'activated',
        'deactivated'
    ) NOT NULL,
    actor_source VARCHAR(16) NOT NULL DEFAULT 'cli',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_audit_target_created (target_admin_id, created_at),
    CONSTRAINT fk_admin_audit_target
        FOREIGN KEY (target_admin_id) REFERENCES admin_users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enquiry_followups (
    patient_enquiry_id BIGINT UNSIGNED NOT NULL,
    follow_up_status ENUM(
        'New',
        'Contacted',
        'Confirmed',
        'Completed',
        'Cancelled'
    ) NOT NULL DEFAULT 'New',
    updated_by_admin_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (patient_enquiry_id),
    KEY idx_enquiry_followups_status_updated (follow_up_status, updated_at),
    CONSTRAINT fk_enquiry_followups_patient
        FOREIGN KEY (patient_enquiry_id) REFERENCES patient_enquiries (id),
    CONSTRAINT fk_enquiry_followups_admin
        FOREIGN KEY (updated_by_admin_id) REFERENCES admin_users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
