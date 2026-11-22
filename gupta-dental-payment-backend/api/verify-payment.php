<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiResponse.php';
ApiResponse::initialize();

try {
    ApiResponse::requirePost();
    $input = ApiResponse::readJsonBody(4096);

    foreach (
        ['razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature']
        as $field
    ) {
        if (!isset($input[$field]) || !is_string($input[$field])) {
            throw new ApiException('Missing or invalid payment details.', 400);
        }
    }

    if (
        strlen($input['razorpay_order_id']) > 100 ||
        strlen($input['razorpay_payment_id']) > 100 ||
        !preg_match('/^[a-f0-9]{64}$/i', $input['razorpay_signature'])
    ) {
        throw new ApiException('Missing or invalid payment details.', 400);
    }

    require_once dirname(__DIR__) . '/services/PaymentService.php';
    $result = (new PaymentService())->verifyPayment(
        $input['razorpay_order_id'],
        $input['razorpay_payment_id'],
        $input['razorpay_signature']
    );

    if ($result['payment_status'] !== 'captured') {
        ApiResponse::send(202, [
            'success' => false,
            'payment_pending' => true,
            'message' => 'Payment is not captured yet. Please wait for confirmation.',
            'data' => $result,
        ]);
        exit;
    }

    ApiResponse::send(200, [
        'success' => true,
        'message' => 'Payment verified successfully.',
        'data' => $result,
    ]);
} catch (ApiException $e) {
    ApiResponse::send($e->httpStatus, [
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (\Razorpay\Api\Errors\SignatureVerificationError $e) {
    ApiResponse::send(400, [
        'success' => false,
        'message' => 'Payment signature is invalid.',
    ]);
} catch (InvalidArgumentException $e) {
    ApiResponse::send(400, [
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    error_log('Payment verification error: ' . $e->getMessage());
    ApiResponse::send(502, [
        'success' => false,
        'message' => 'Payment verification is unavailable. Check the enquiry status before retrying.',
    ]);
}
