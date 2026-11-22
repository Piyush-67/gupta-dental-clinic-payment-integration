<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/Database.php';

use Razorpay\Api\Api;

class PaymentService
{
    private Api $razorpay;
    private PDO $db;

    public function __construct()
    {
        if (RAZORPAY_KEY_ID === '' || RAZORPAY_KEY_SECRET === '') {
            throw new RuntimeException('Razorpay API credentials are not configured.');
        }

        $this->razorpay = new Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);
        $this->db = Database::connect();
    }

    public function createOrder(): array
    {
        $receipt = 'GDC-ORD-' . strtoupper(bin2hex(random_bytes(6)));

        $order = $this->razorpay->order->create([
            'receipt' => $receipt,
            'amount' => ENQUIRY_FEE_PAISE,
            'currency' => PAYMENT_CURRENCY,
            'notes' => ['purpose' => 'Dental clinic enquiry fee'],
        ]);

        $orderId = (string) $order['id'];

        $stmt = $this->db->prepare(
            'INSERT INTO payment_records
                (razorpay_order_id, amount_paise, currency, payment_status)
             VALUES (?, ?, ?, ?)'
        );

        $stmt->execute([
            $orderId,
            ENQUIRY_FEE_PAISE,
            PAYMENT_CURRENCY,
            'created',
        ]);

        return [
            'order_id' => $orderId,
            'amount' => ENQUIRY_FEE_PAISE,
            'currency' => PAYMENT_CURRENCY,
            'key_id' => RAZORPAY_KEY_ID,
        ];
    }

    public function verifyPayment(
        string $orderId,
        string $paymentId,
        string $signature
    ): array {
        if ($orderId === '' || $paymentId === '' || $signature === '') {
            throw new InvalidArgumentException('Payment verification details are incomplete.');
        }

        $this->razorpay->utility->verifyPaymentSignature([
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ]);

        $paymentEntity = $this->razorpay->payment->fetch($paymentId);
        $payment = $paymentEntity->toArray();
        $paymentStatus = (string) ($payment['status'] ?? '');

        if (!in_array($paymentStatus, ['authorized', 'captured', 'failed'], true)) {
            throw new RuntimeException('Payment is not in a verifiable state.');
        }

        if ((string) ($payment['order_id'] ?? '') !== $orderId) {
            throw new RuntimeException('Payment does not belong to this order.');
        }

        $this->db->beginTransaction();

        try {
            $record = $this->lockOrderRecord($orderId);
            $this->validatePaymentAgainstOrder($payment, $record);
            $this->savePaymentAttempt((int) $record['id'], $payment, true);

            $reference = $record['enquiry_reference'];

            if ($paymentStatus === 'captured') {
                if ($reference === null) {
                    $reference = $this->allocateEnquiryReference((int) $record['id']);

                    $stmt = $this->db->prepare(
                        'UPDATE payment_records
                         SET enquiry_reference = ?,
                             razorpay_payment_id = ?,
                             payment_status = ?,
                             signature_verified = TRUE,
                             verified_at = CURRENT_TIMESTAMP
                         WHERE id = ?'
                    );

                    $stmt->execute([
                        $reference,
                        $paymentId,
                        'captured',
                        $record['id'],
                    ]);
                } else {
                    $stmt = $this->db->prepare(
                        'UPDATE payment_records
                         SET payment_status = ?,
                             signature_verified = TRUE,
                             verified_at = CURRENT_TIMESTAMP
                         WHERE id = ?'
                    );

                    $stmt->execute(['captured', $record['id']]);
                }
            } elseif ($record['payment_status'] !== 'captured') {
                // A failed attempt does not permanently fail the order.
                $orderStatus = $paymentStatus === 'authorized' ? 'authorized' : 'created';

                $stmt = $this->db->prepare(
                    'UPDATE payment_records SET payment_status = ? WHERE id = ?'
                );

                $stmt->execute([$orderStatus, $record['id']]);
            }

            $this->db->commit();

            return [
                'reference' => $reference,
                'payment_id' => $paymentId,
                'payment_status' => $paymentStatus,
                'order_status' => $paymentStatus === 'captured'
                    ? 'captured'
                    : ($record['payment_status'] === 'captured'
                        ? 'captured'
                        : ($paymentStatus === 'authorized' ? 'authorized' : 'created')),
                'signature_verified' => true,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function processWebhookEvent(
        string $eventId,
        string $eventType,
        array $payment
    ): array {
        $paymentId = (string) ($payment['id'] ?? '');
        $orderId = (string) ($payment['order_id'] ?? '');
        $paymentStatus = (string) ($payment['status'] ?? '');

        if ($eventId === '' || $paymentId === '' || $orderId === '') {
            throw new InvalidArgumentException('Invalid webhook payment details.');
        }

        if (!in_array($eventType, ['payment.captured', 'payment.failed'], true)) {
            throw new InvalidArgumentException('Unsupported webhook event.');
        }

        $expectedStatus = $eventType === 'payment.captured' ? 'captured' : 'failed';

        if ($paymentStatus !== $expectedStatus) {
            throw new InvalidArgumentException('Webhook event and payment status do not match.');
        }

        $this->db->beginTransaction();

        try {
            // Serialize all attempts belonging to this order.
            $record = $this->lockOrderRecord($orderId);
            $this->validatePaymentAgainstOrder($payment, $record);

            try {
                $stmt = $this->db->prepare(
                    'INSERT INTO webhook_events
                        (razorpay_event_id, event_type, payment_id, order_id, processing_status)
                     VALUES (?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $eventId,
                    $eventType,
                    $paymentId,
                    $orderId,
                    'processing',
                ]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $check = $this->db->prepare(
                        'SELECT id FROM webhook_events
                         WHERE razorpay_event_id = ? LIMIT 1'
                    );

                    $check->execute([$eventId]);

                    if ($check->fetch()) {
                        $this->db->rollBack();

                        return [
                            'duplicate' => true,
                            'message' => 'Webhook event already processed.',
                        ];
                    }
                }

                throw $e;
            }

            $this->savePaymentAttempt((int) $record['id'], $payment, false);
            $reference = $record['enquiry_reference'];

            if ($eventType === 'payment.captured') {
                if ($reference === null) {
                    $reference = $this->allocateEnquiryReference((int) $record['id']);

                    $stmt = $this->db->prepare(
                        'UPDATE payment_records
                         SET enquiry_reference = ?,
                             razorpay_payment_id = ?,
                             payment_status = ?,
                             verified_at = CURRENT_TIMESTAMP
                         WHERE id = ?'
                    );

                    $stmt->execute([
                        $reference,
                        $paymentId,
                        'captured',
                        $record['id'],
                    ]);
                } else {
                    $stmt = $this->db->prepare(
                        'UPDATE payment_records SET payment_status = ? WHERE id = ?'
                    );

                    $stmt->execute(['captured', $record['id']]);
                }
            } elseif ($record['payment_status'] !== 'captured') {
                // Keep the order retryable after an individual failed attempt.
                $stmt = $this->db->prepare(
                    'UPDATE payment_records SET payment_status = ? WHERE id = ?'
                );

                $stmt->execute(['created', $record['id']]);
            }

            $stmt = $this->db->prepare(
                'UPDATE webhook_events
                 SET processing_status = ?, processed_at = CURRENT_TIMESTAMP
                 WHERE razorpay_event_id = ?'
            );

            $stmt->execute(['processed', $eventId]);

            $this->db->commit();

            return [
                'duplicate' => false,
                'reference' => $reference,
                'payment_id' => $paymentId,
                'payment_status' => $paymentStatus,
                'message' => $paymentStatus === 'captured'
                    ? 'Captured payment processed.'
                    : 'Failed payment attempt recorded; another attempt may be made.',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    private function lockOrderRecord(string $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM payment_records
             WHERE razorpay_order_id = ?
             LIMIT 1
             FOR UPDATE'
        );

        $stmt->execute([$orderId]);
        $record = $stmt->fetch();

        if (!$record) {
            throw new RuntimeException('Payment order not found.');
        }

        return $record;
    }

    private function validatePaymentAgainstOrder(array $payment, array $record): void
    {
        if (
            (int) ($payment['amount'] ?? 0) !== (int) $record['amount_paise'] ||
            (string) ($payment['currency'] ?? '') !== (string) $record['currency'] ||
            (int) $record['amount_paise'] !== ENQUIRY_FEE_PAISE ||
            (string) $record['currency'] !== PAYMENT_CURRENCY
        ) {
            throw new RuntimeException(
                'Payment amount or currency does not match the saved order.'
            );
        }
    }

    private function savePaymentAttempt(
        int $paymentRecordId,
        array $payment,
        bool $verifiedByCheckout
    ): void {
        $paymentId = (string) ($payment['id'] ?? '');
        $incomingStatus = (string) ($payment['status'] ?? '');

        if ($paymentId === '' || $incomingStatus === '') {
            throw new InvalidArgumentException('Payment attempt details are incomplete.');
        }

        $stmt = $this->db->prepare(
            'SELECT id, payment_record_id, payment_status
             FROM payment_attempts
             WHERE razorpay_payment_id = ?
             LIMIT 1
             FOR UPDATE'
        );

        $stmt->execute([$paymentId]);
        $existing = $stmt->fetch();

        $method = isset($payment['method']) ? (string) $payment['method'] : null;
        $errorCode = isset($payment['error_code']) ? (string) $payment['error_code'] : null;

        $errorDescription = isset($payment['error_description'])
            ? substr((string) $payment['error_description'], 0, 255)
            : null;

        if ($existing) {
            if ((int) $existing['payment_record_id'] !== $paymentRecordId) {
                throw new RuntimeException('Payment ID is already linked to a different order.');
            }

            // A delayed failure event must never downgrade a captured attempt.
            $finalStatus = $existing['payment_status'] === 'captured'
                ? 'captured'
                : $incomingStatus;

            $stmt = $this->db->prepare(
                'UPDATE payment_attempts
                 SET payment_status = ?,
                     method = COALESCE(?, method),
                     error_code = ?,
                     error_description = ?,
                     verified_at = CASE WHEN ? = 1
                         THEN CURRENT_TIMESTAMP ELSE verified_at END
                 WHERE id = ?'
            );

            $stmt->execute([
                $finalStatus,
                $method,
                $errorCode,
                $errorDescription,
                $verifiedByCheckout ? 1 : 0,
                $existing['id'],
            ]);

            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO payment_attempts
                (payment_record_id, razorpay_payment_id, payment_status,
                 amount_paise, currency, method, error_code, error_description, verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $paymentRecordId,
            $paymentId,
            $incomingStatus,
            (int) $payment['amount'],
            (string) $payment['currency'],
            $method,
            $errorCode,
            $errorDescription,
            $verifiedByCheckout ? date('Y-m-d H:i:s') : null,
        ]);
    }

    private function allocateEnquiryReference(int $paymentRecordId): string
    {
        $stmt = $this->db->prepare(
            'INSERT INTO enquiry_sequences (payment_record_id) VALUES (?)'
        );

        $stmt->execute([$paymentRecordId]);

        $sequenceId = (int) $this->db->lastInsertId();

        return 'GDC-' . str_pad(
            (string) $sequenceId,
            6,
            '0',
            STR_PAD_LEFT
        );
    }
}