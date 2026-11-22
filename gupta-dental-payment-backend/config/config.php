<?php
declare(strict_types=1);

use Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load environment variables from the backend root
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Application configuration
define('APP_ENV', $_ENV['APP_ENV'] ?? 'local');
define('APP_DEBUG', filter_var(
    $_ENV['APP_DEBUG'] ?? 'false',
    FILTER_VALIDATE_BOOLEAN
));

// Razorpay configuration
define('RAZORPAY_KEY_ID', $_ENV['RAZORPAY_KEY_ID'] ?? '');
define('RAZORPAY_KEY_SECRET', $_ENV['RAZORPAY_KEY_SECRET'] ?? '');

// Razorpay webhook secret for verifying incoming webhook requests
define(
    'RAZORPAY_WEBHOOK_SECRET',
    $_ENV['RAZORPAY_WEBHOOK_SECRET'] ?? ''
);

// Database configuration
define('DB_HOST', $_ENV['DB_HOST'] ?? '127.0.0.1');
define('DB_NAME', $_ENV['DB_NAME'] ?? '');
define('DB_USER', $_ENV['DB_USER'] ?? '');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

// Payment configuration
define('ENQUIRY_FEE_PAISE', 500);
define('PAYMENT_CURRENCY', 'INR');
