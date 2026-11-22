<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiResponse.php';
ApiResponse::initialize();

try {
    ApiResponse::requirePost();
    $input = ApiResponse::readJsonBody(4096);
    $requestToken = $input['request_token'] ?? null;

    if (!is_string($requestToken)) {
        throw new ApiException('Invalid enquiry request token.', 400);
    }

    require_once dirname(__DIR__) . '/services/PatientEnquiryService.php';
    require_once dirname(__DIR__) . '/services/PaymentService.php';

    $enquiry = (new PatientEnquiryService())->findByRequestToken($requestToken);

    if (!$enquiry) {
        throw new ApiException('Enquiry was not found.', 404);
    }

    $paymentService = new PaymentService();
    $payment = $paymentService->getPaymentStateForEnquiry($enquiry['id']);
    $isCaptured = $payment['payment_status'] === 'captured';

    ApiResponse::send(200, [
        'success' => true,
        'data' => [
            'enquiry_status' => $enquiry['enquiry_status'],
            'payment_status' => $payment['payment_status'],
            'order_creation_status' => $payment['order_creation_status'],
            'reference' => $payment['reference'],
            'confirmation' => $isCaptured && $payment['order_id'] !== null
                ? $paymentService->getCapturedConfirmationForOrder(
                    $payment['order_id']
                )
                : null,
            'order' => $isCaptured || $payment['order_id'] === null ? null : [
                'order_id' => $payment['order_id'],
                'amount' => $payment['amount'],
                'currency' => $payment['currency'],
                'key_id' => $payment['key_id'],
            ],
        ],
    ]);
} catch (ApiException $e) {
    ApiResponse::send($e->httpStatus, [
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (InvalidArgumentException $e) {
    ApiResponse::send(400, [
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    error_log('Enquiry status error: ' . $e->getMessage());
    ApiResponse::send(503, [
        'success' => false,
        'message' => 'Enquiry status is temporarily unavailable.',
    ]);
}
