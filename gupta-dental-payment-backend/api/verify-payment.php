<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/PaymentService.php';

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

// Validate required fields
if (
    !is_array($input) ||
    !isset(
        $input['razorpay_order_id'],
        $input['razorpay_payment_id'],
        $input['razorpay_signature']
    ) ||
    !is_string($input['razorpay_order_id']) ||
    !is_string($input['razorpay_payment_id']) ||
    !is_string($input['razorpay_signature']) ||
    trim($input['razorpay_order_id']) === '' ||
    trim($input['razorpay_payment_id']) === '' ||
    trim($input['razorpay_signature']) === ''
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Missing or invalid payment details.'
    ]);

    exit;
}

try {

    $paymentService = new PaymentService();

    $result = $paymentService->verifyPayment(
        $input['razorpay_order_id'],
        $input['razorpay_payment_id'],
        $input['razorpay_signature']
    );

    // A reference is assigned only after capture
    if ($result['payment_status'] !== 'captured') {

        http_response_code(202);

        echo json_encode([
            'success' => false,
            'payment_pending' => true,
            'message' => 'Payment is not captured yet. Please wait for confirmation.',
            'data' => $result
        ]);

        exit;
    }

    http_response_code(200);

    echo json_encode([
        'success' => true,
        'message' => 'Payment verified successfully.',
        'data' => $result
    ]);

} catch (Throwable $e) {

    error_log(
        'Payment verification error: ' . $e->getMessage()
    );

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Payment verification failed.'
    ]);
}
