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

    public function createOrder(int $patientEnquiryId): array
    {
        $this->db->beginTransaction();

        try {
            $enquiryStmt = $this->db->prepare(
                'SELECT id, request_key_hash, enquiry_status
                 FROM patient_enquiries
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $enquiryStmt->execute([$patientEnquiryId]);
            $enquiry = $enquiryStmt->fetch();

            if (!$enquiry) {
                throw new RuntimeException('Patient enquiry not found.');
            }

            $record = $this->findPaymentRecordForEnquiry($patientEnquiryId, true);

            if ($record) {
                $this->db->commit();

                if ($record['razorpay_order_id'] === null) {
                    return $this->recoverUnlinkedOrder(
                        $record,
                        $this->receiptForEnquiry($enquiry)
                    );
                }

                return $this->formatOrderResponse($record);
            }

            if ($enquiry['enquiry_status'] === 'payment_confirmed') {
                throw new RuntimeException(
                    'Confirmed enquiry has no linked payment record.'
                );
            }

            $stmt = $this->db->prepare(
                'INSERT INTO payment_records
                    (patient_enquiry_id, amount_paise, currency, payment_status,
                     order_creation_status)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $patientEnquiryId,
                ENQUIRY_FEE_PAISE,
                PAYMENT_CURRENCY,
                'created',
                'creating',
            ]);
            $recordId = (int) $this->db->lastInsertId();
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        $receipt = $this->receiptForEnquiry($enquiry);

        try {
            $order = $this->razorpay->order->create([
                'receipt' => $receipt,
                'amount' => ENQUIRY_FEE_PAISE,
                'currency' => PAYMENT_CURRENCY,
                'notes' => ['purpose' => 'Dental clinic enquiry fee'],
            ])->toArray();

            return $this->saveCreatedOrder($recordId, $receipt, $order);
        } catch (Throwable $creationError) {
            $this->markOrderCreationUnknown($recordId);
            $record = $this->findPaymentRecordForEnquiry($patientEnquiryId);

            if (!$record) {
                throw new RuntimeException(
                    'The payment order could not be saved. Check its status before retrying.',
                    0,
                    $creationError
                );
            }

            try {
                $recovered = $this->recoverUnlinkedOrder($record, $receipt);
            } catch (Throwable $lookupError) {
                throw new RuntimeException(
                    'Razorpay order creation could not be confirmed. Check its status before retrying.',
                    0,
                    $lookupError
                );
            }

            if ($recovered['order_id'] !== null) {
                return $recovered;
            }

            throw new RuntimeException(
                'Razorpay order creation could not be confirmed. Check its status before retrying.',
                0,
                $creationError
            );
        }
    }

    public function getPaymentStateForEnquiry(int $patientEnquiryId): array
    {
        $this->createOrder($patientEnquiryId);
        $record = $this->findPaymentRecordForEnquiry($patientEnquiryId);

        if (!$record) {
            throw new RuntimeException('Payment order is not available.');
        }

        if ($record['razorpay_order_id'] === null) {
            return $this->formatOrderResponse($record);
        }

        if ($record['payment_status'] !== 'captured') {
            $this->reconcileOrderPayments((string) $record['razorpay_order_id']);
            $record = $this->findPaymentRecordForEnquiry($patientEnquiryId);
        }

        if (!$record) {
            throw new RuntimeException('Payment order is not available.');
        }

        return $this->formatOrderResponse($record);
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

        if (
            (string) ($payment['id'] ?? '') !== $paymentId ||
            (string) ($payment['order_id'] ?? '') !== $orderId
        ) {
            throw new RuntimeException('Payment does not belong to this order.');
        }

        return $this->recordPayment($orderId, $payment, true);
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

            $result = $this->applyPaymentInTransaction($orderId, $payment, false);

            $stmt = $this->db->prepare(
                'UPDATE webhook_events
                 SET processing_status = ?, processed_at = CURRENT_TIMESTAMP
                 WHERE razorpay_event_id = ?'
            );

            $stmt->execute(['processed', $eventId]);

            $this->db->commit();

            return [
                'duplicate' => false,
                'reference' => $result['reference'],
                'payment_id' => $paymentId,
                'payment_status' => $paymentStatus,
                'order_status' => $result['order_status'],
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

    private function recordPayment(
        string $orderId,
        array $payment,
        bool $verifiedByCheckout
    ): array {
        $this->db->beginTransaction();

        try {
            $result = $this->applyPaymentInTransaction(
                $orderId,
                $payment,
                $verifiedByCheckout
            );
            $this->db->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    private function applyPaymentInTransaction(
        string $orderId,
        array $payment,
        bool $verifiedByCheckout
    ): array {
        $paymentId = (string) ($payment['id'] ?? '');
        $paymentStatus = (string) ($payment['status'] ?? '');

        if (
            $paymentId === '' ||
            !in_array($paymentStatus, ['authorized', 'captured', 'failed'], true)
        ) {
            throw new InvalidArgumentException('Payment attempt details are invalid.');
        }

        $record = $this->lockOrderRecord($orderId);
        $this->validatePaymentAgainstOrder($payment, $record);
        $this->savePaymentAttempt((int) $record['id'], $payment, $verifiedByCheckout);

        $reference = $record['enquiry_reference'];

        if ($paymentStatus === 'captured') {
            if ($reference === null) {
                $reference = $this->allocateEnquiryReference((int) $record['id']);
                $stmt = $this->db->prepare(
                    'UPDATE payment_records
                     SET enquiry_reference = ?,
                         razorpay_payment_id = ?,
                         payment_status = ?,
                         signature_verified = IF(?, TRUE, signature_verified),
                         verified_at = CURRENT_TIMESTAMP
                     WHERE id = ?'
                );
                $stmt->execute([
                    $reference,
                    $paymentId,
                    'captured',
                    $verifiedByCheckout ? 1 : 0,
                    $record['id'],
                ]);
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE payment_records
                     SET payment_status = ?,
                         signature_verified = IF(?, TRUE, signature_verified),
                         verified_at = COALESCE(verified_at, CURRENT_TIMESTAMP)
                     WHERE id = ?'
                );
                $stmt->execute([
                    'captured',
                    $verifiedByCheckout ? 1 : 0,
                    $record['id'],
                ]);
            }

            $this->markPatientEnquiryConfirmed($record);
        } elseif ($record['payment_status'] !== 'captured') {
            $orderStatus = $paymentStatus === 'authorized'
                ? 'authorized'
                : 'created';
            $stmt = $this->db->prepare(
                'UPDATE payment_records SET payment_status = ? WHERE id = ?'
            );
            $stmt->execute([$orderStatus, $record['id']]);
        }

        $orderStatus = $record['payment_status'] === 'captured'
            ? 'captured'
            : ($paymentStatus === 'captured'
                ? 'captured'
                : ($paymentStatus === 'authorized' ? 'authorized' : 'created'));

        return [
            'reference' => $reference,
            'payment_id' => $paymentId,
            'payment_status' => $paymentStatus,
            'order_status' => $orderStatus,
            'signature_verified' => $verifiedByCheckout,
        ];
    }

    private function reconcileOrderPayments(string $orderId): void
    {
        $order = $this->razorpay->order->fetch($orderId);
        $payments = $order->payments()->toArray();
        $items = $payments['items'] ?? [];

        if (!is_array($items)) {
            throw new RuntimeException('Unexpected Razorpay payment list response.');
        }

        foreach ($items as $payment) {
            if (
                !is_array($payment) ||
                (string) ($payment['order_id'] ?? '') !== $orderId ||
                !in_array(
                    (string) ($payment['status'] ?? ''),
                    ['authorized', 'captured', 'failed'],
                    true
                )
            ) {
                continue;
            }

            $this->recordPayment($orderId, $payment, false);
        }
    }

    private function findPaymentRecordForEnquiry(
        int $patientEnquiryId,
        bool $forUpdate = false
    ): ?array {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM payment_records
             WHERE patient_enquiry_id = ?
             LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$patientEnquiryId]);
        $record = $stmt->fetch();

        return $record ?: null;
    }

    private function receiptForEnquiry(array $enquiry): string
    {
        return 'GDC-E-' . strtoupper(
            substr((string) $enquiry['request_key_hash'], 0, 24)
        );
    }

    private function recoverUnlinkedOrder(array $record, string $receipt): array
    {
        if ($record['razorpay_order_id'] !== null) {
            return $this->formatOrderResponse($record);
        }

        $order = $this->findOrderByReceipt($receipt);

        if ($order !== null) {
            return $this->saveCreatedOrder((int) $record['id'], $receipt, $order);
        }

        if ($record['order_creation_status'] === 'creating') {
            $stmt = $this->db->prepare(
                'UPDATE payment_records
                 SET order_creation_status = ?
                 WHERE id = ?
                   AND razorpay_order_id IS NULL
                   AND order_creation_status = ?
                   AND created_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 MINUTE)'
            );
            $stmt->execute(['unknown', $record['id'], 'creating']);

            if ($stmt->rowCount() > 0) {
                $stmt = $this->db->prepare(
                    'SELECT *
                     FROM payment_records
                     WHERE id = ?
                     LIMIT 1'
                );
                $stmt->execute([$record['id']]);
                $record = $stmt->fetch() ?: $record;
            }
        }

        return $this->formatOrderResponse($record);
    }

    private function saveCreatedOrder(
        int $recordId,
        string $receipt,
        array $order
    ): array {
        $orderId = (string) ($order['id'] ?? '');

        if (
            $orderId === '' ||
            (string) ($order['receipt'] ?? '') !== $receipt ||
            (int) ($order['amount'] ?? 0) !== ENQUIRY_FEE_PAISE ||
            (string) ($order['currency'] ?? '') !== PAYMENT_CURRENCY
        ) {
            throw new RuntimeException('Razorpay returned an unexpected order.');
        }

        $stmt = $this->db->prepare(
            'UPDATE payment_records
             SET razorpay_order_id = ?, order_creation_status = ?
             WHERE id = ? AND razorpay_order_id IS NULL'
        );
        $stmt->execute([$orderId, 'created', $recordId]);

        $stmt = $this->db->prepare(
            'SELECT *
             FROM payment_records
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$recordId]);
        $record = $stmt->fetch();

        if (!$record || (string) $record['razorpay_order_id'] !== $orderId) {
            throw new RuntimeException('Payment order was not saved.');
        }

        return $this->formatOrderResponse($record);
    }

    private function markOrderCreationUnknown(int $recordId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE payment_records
             SET order_creation_status = ?
             WHERE id = ? AND razorpay_order_id IS NULL'
        );
        $stmt->execute(['unknown', $recordId]);
    }

    private function findOrderByReceipt(string $receipt): ?array
    {
        $orders = $this->razorpay->order->all([
            'receipt' => $receipt,
            'count' => 2,
        ])->toArray();
        $items = $orders['items'] ?? [];

        if (!is_array($items)) {
            throw new RuntimeException('Unexpected Razorpay order lookup response.');
        }

        $matches = array_values(array_filter(
            $items,
            static fn ($order): bool =>
                is_array($order) &&
                (string) ($order['receipt'] ?? '') === $receipt
        ));

        if (count($matches) > 1) {
            throw new RuntimeException('Razorpay returned duplicate order receipts.');
        }

        return $matches[0] ?? null;
    }

    private function formatOrderResponse(array $record): array
    {
        return [
            'order_id' => $record['razorpay_order_id'] === null
                ? null
                : (string) $record['razorpay_order_id'],
            'amount' => (int) $record['amount_paise'],
            'currency' => (string) $record['currency'],
            'key_id' => RAZORPAY_KEY_ID,
            'payment_status' => (string) $record['payment_status'],
            'order_creation_status' => (string) $record['order_creation_status'],
            'reference' => $record['enquiry_reference'],
        ];
    }

    private function markPatientEnquiryConfirmed(array $record): void
    {
        if ($record['patient_enquiry_id'] === null) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE patient_enquiries
             SET enquiry_status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([
            'payment_confirmed',
            $record['patient_enquiry_id'],
        ]);
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
            (string) ($payment['order_id'] ?? '') !== (string) $record['razorpay_order_id'] ||
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

        $method = $method === null
            ? null
            : mb_substr($method, 0, 30, 'UTF-8');
        $errorCode = $errorCode === null
            ? null
            : mb_substr($errorCode, 0, 100, 'UTF-8');
        $errorDescription = isset($payment['error_description'])
            ? mb_substr((string) $payment['error_description'], 0, 255, 'UTF-8')
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