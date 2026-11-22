<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/PaymentService.php';

// Return JSON responses
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Allow POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);

    exit;
}

// Read JSON request body
$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid request.'
    ]);

    exit;
}

try {
    // Create payment order using server-controlled amount
    $paymentService = new PaymentService();

    $order = $paymentService->createOrder();

    http_response_code(201);

    echo json_encode([
        'success' => true,
        'message' => 'Payment order created successfully.',
        'data' => $order
    ]);

} catch (Throwable $e) {

    // Keep technical errors in server logs
    error_log('Create order error: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to create payment order. Please try again.'
    ]);
}
