-- Back up the existing database before applying this one-time migration.
-- Existing payment rows remain unlinked; no historical rows or sequence IDs are changed.

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

ALTER TABLE payment_records
    MODIFY COLUMN enquiry_reference VARCHAR(40) DEFAULT NULL,
    MODIFY COLUMN razorpay_order_id VARCHAR(100) DEFAULT NULL,
    ADD COLUMN patient_enquiry_id BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN order_creation_status VARCHAR(20) NOT NULL DEFAULT 'created',
    ADD UNIQUE KEY uq_payment_records_patient_enquiry (patient_enquiry_id),
    ADD CONSTRAINT fk_payment_records_patient_enquiry
        FOREIGN KEY (patient_enquiry_id) REFERENCES patient_enquiries (id);
