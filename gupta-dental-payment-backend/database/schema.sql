
CREATE TABLE payment_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    enquiry_reference VARCHAR(40) NOT NULL UNIQUE,

    razorpay_order_id VARCHAR(100) NOT NULL UNIQUE,

    razorpay_payment_id VARCHAR(100) DEFAULT NULL UNIQUE,

    amount_paise INT UNSIGNED NOT NULL,

    currency VARCHAR(10) NOT NULL DEFAULT 'INR',

    payment_status VARCHAR(20) NOT NULL DEFAULT 'created',

    signature_verified BOOLEAN NOT NULL DEFAULT FALSE,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    verified_at TIMESTAMP NULL DEFAULT NULL,

    INDEX idx_payment_status (payment_status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
