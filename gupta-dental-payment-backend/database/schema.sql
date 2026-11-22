CREATE TABLE patient_enquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_key_hash CHAR(64) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    primary_phone VARCHAR(20) NOT NULL,
    alternate_phone VARCHAR(20) DEFAULT NULL,
    complete_address VARCHAR(500) NOT NULL,
    age TINYINT UNSIGNED NOT NULL,
    email VARCHAR(254) DEFAULT NULL,
    reason_for_visit VARCHAR(80) NOT NULL,
    consent_given TINYINT(1) NOT NULL,
    enquiry_status VARCHAR(32) NOT NULL DEFAULT 'pending_payment',
    consented_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_patient_enquiries_request_key (request_key_hash),
    KEY idx_patient_enquiries_status_created (enquiry_status, created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_enquiry_id BIGINT UNSIGNED DEFAULT NULL,
    enquiry_reference VARCHAR(40) DEFAULT NULL,
    razorpay_order_id VARCHAR(100) DEFAULT NULL,
    razorpay_payment_id VARCHAR(100) DEFAULT NULL,
    amount_paise INT UNSIGNED NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'INR',
    payment_status VARCHAR(20) NOT NULL DEFAULT 'created',
    order_creation_status VARCHAR(20) NOT NULL DEFAULT 'created',
    signature_verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY enquiry_reference (enquiry_reference),
    UNIQUE KEY razorpay_order_id (razorpay_order_id),
    UNIQUE KEY razorpay_payment_id (razorpay_payment_id),
    UNIQUE KEY uq_payment_records_patient_enquiry (patient_enquiry_id),
    KEY idx_payment_status (payment_status),
    KEY idx_created_at (created_at),
    CONSTRAINT fk_payment_records_patient_enquiry
        FOREIGN KEY (patient_enquiry_id) REFERENCES patient_enquiries (id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enquiry_sequences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_record_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY payment_record_id (payment_record_id),
    CONSTRAINT fk_sequence_payment
        FOREIGN KEY (payment_record_id) REFERENCES payment_records (id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_record_id BIGINT UNSIGNED NOT NULL,
    razorpay_payment_id VARCHAR(100) NOT NULL,
    payment_status VARCHAR(20) NOT NULL,
    amount_paise INT UNSIGNED NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'INR',
    method VARCHAR(30) DEFAULT NULL,
    error_code VARCHAR(100) DEFAULT NULL,
    error_description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY razorpay_payment_id (razorpay_payment_id),
    KEY idx_attempt_record_status (payment_record_id, payment_status),
    CONSTRAINT fk_attempt_payment_record
        FOREIGN KEY (payment_record_id) REFERENCES payment_records (id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    razorpay_event_id VARCHAR(100) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payment_id VARCHAR(100) DEFAULT NULL,
    order_id VARCHAR(100) DEFAULT NULL,
    processing_status VARCHAR(20) NOT NULL DEFAULT 'processed',
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY razorpay_event_id (razorpay_event_id),
    KEY idx_webhook_order_id (order_id),
    KEY idx_webhook_payment_id (payment_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
