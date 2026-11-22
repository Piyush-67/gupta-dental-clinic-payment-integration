<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiResponse.php';
ApiResponse::initialize();

try {
    ApiResponse::requirePost();
    $input = ApiResponse::readJsonBody();
    $requestToken = $input['request_token'] ?? null;

    if (!is_string($requestToken)) {
        throw new InvalidArgumentException('Invalid enquiry request token.');
    }

    require_once dirname(__DIR__) . '/services/PatientEnquiryService.php';
    require_once dirname(__DIR__) . '/services/PaymentService.php';

    $enquiry = (new PatientEnquiryService())->createOrFind(
        $input,
        $requestToken
    );
    $order = (new PaymentService())->createOrder($enquiry['id']);
    $hasOrder = $order['order_id'] !== null;
    $orderReady = $hasOrder && $order['order_creation_status'] === 'created';
    $status = $order['payment_status'] === 'captured'
        ? 200
        : ($orderReady ? 201 : 202);

    ApiResponse::send($status, [
        'success' => true,
        'message' => $status === 200
            ? 'Payment was already confirmed.'
            : ($orderReady
                ? 'Payment order created successfully.'
                : 'Payment order status is being checked. Do not submit a new enquiry.'),
        'data' => $order,
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
} catch (DomainException $e) {
    ApiResponse::send(409, [
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    error_log('Create enquiry order error: ' . $e->getMessage());
    ApiResponse::send(502, [
        'success' => false,
        'message' => 'Unable to confirm a payment order. Retry with the same enquiry and do not submit new details.',
    ]);
}
