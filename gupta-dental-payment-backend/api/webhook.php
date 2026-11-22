<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/PaymentService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Accept POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);

    exit;
}

// Ensure webhook secret is configured
if (RAZORPAY_WEBHOOK_SECRET === '') {
    error_log('Razorpay webhook secret is not configured.');

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Webhook configuration error.'
    ]);

    exit;
}

// Read the original, unmodified request body
$payload = file_get_contents('php://input');

// Read Razorpay signature and unique event ID
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
$eventId = $_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '';

if (
    $payload === false ||
    $payload === '' ||
    $signature === '' ||
    $eventId === ''
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid webhook request.'
    ]);

    exit;
}

// Verify the webhook signature
$expectedSignature = hash_hmac(
    'sha256',
    $payload,
    RAZORPAY_WEBHOOK_SECRET
);

if (!hash_equals($expectedSignature, $signature)) {
    error_log('Razorpay webhook signature verification failed.');

    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid webhook signature.'
    ]);

    exit;
}

// Decode JSON only after signature verification
$eventData = json_decode($payload, true);

if (
    !is_array($eventData) ||
    !isset($eventData['event'])
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid webhook payload.'
    ]);

    exit;
}

$eventType = $eventData['event'];

// Ignore events we have not subscribed to process
if (!in_array(
    $eventType,
    ['payment.captured', 'payment.failed'],
    true
)) {
    http_response_code(200);

    echo json_encode([
        'success' => true,
        'message' => 'Event acknowledged but not processed.'
    ]);

    exit;
}

// Extract payment entity
$payment = $eventData['payload']['payment']['entity'] ?? null;

if (!is_array($payment)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Payment details are missing.'
    ]);

    exit;
}

try {

    $paymentService = new PaymentService();

    $result = $paymentService->processWebhookEvent(
        $eventId,
        $eventType,
        $payment
    );

    // Acknowledge successful or already-processed events
    http_response_code(200);

    echo json_encode([
        'success' => true,
        'message' => $result['message'] ?? 'Webhook processed.',
        'data' => $result
    ]);

} catch (Throwable $e) {

    // Log technical details privately
    error_log(
        'Razorpay webhook processing error: ' . $e->getMessage()
    );

    // Non-2xx response allows Razorpay to retry delivery
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Webhook processing failed.'
    ]);
}
