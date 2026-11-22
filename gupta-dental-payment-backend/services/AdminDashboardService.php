<?php
declare(strict_types=1);

final class AdminDashboardService
{
    private const FOLLOW_UP_STATUSES = [
        'New',
        'Contacted',
        'Confirmed',
        'Completed',
        'Cancelled',
    ];

    public function __construct(private PDO $db)
    {
    }

    public static function followUpStatuses(): array
    {
        return self::FOLLOW_UP_STATUSES;
    }

    public function getOverview(): array
    {
        $totalEnquiries = (int) $this->db->query(
            'SELECT COUNT(*) FROM patient_enquiries'
        )->fetchColumn();

        $newEnquiries = (int) $this->db->query(
            "SELECT COUNT(*)
             FROM patient_enquiries e
             LEFT JOIN enquiry_followups f ON f.patient_enquiry_id = e.id
             WHERE COALESCE(f.follow_up_status, 'New') = 'New'"
        )->fetchColumn();

        $pendingPayments = (int) $this->db->query(
            "SELECT COUNT(*)
             FROM payment_records
             WHERE payment_status IN ('created', 'authorized')"
        )->fetchColumn();

        $successfulPayments = (int) $this->db->query(
            "SELECT COUNT(*)
             FROM payment_records
             WHERE payment_status = 'captured'"
        )->fetchColumn();

        $failedPaymentAttempts = (int) $this->db->query(
            "SELECT COUNT(*)
             FROM payment_attempts
             WHERE payment_status = 'failed'"
        )->fetchColumn();

        $capturedAmountPaise = (int) $this->db->query(
            "SELECT COALESCE(SUM(amount_paise), 0)
             FROM payment_records
             WHERE payment_status = 'captured'"
        )->fetchColumn();

        return [
            'total_enquiries' => $totalEnquiries,
            'new_enquiries' => $newEnquiries,
            'pending_payments' => $pendingPayments,
            'successful_payments' => $successfulPayments,
            'failed_payment_attempts' => $failedPaymentAttempts,
            'captured_amount_paise' => $capturedAmountPaise,
        ];
    }

    public function getEnquiries(array $filters): array
    {
        [$page, $perPage] = self::pagination($filters);
        $where = [];
        $params = [];
        $search = self::optionalText($filters, 'search', 120);

        if ($search !== '') {
            $where[] = "LOWER(CONCAT_WS(
                ' ',
                e.full_name,
                e.primary_phone,
                e.email,
                p.enquiry_reference
            )) LIKE ?";
            $params[] = '%' . strtolower($search) . '%';
        }

        $status = self::optionalText($filters, 'status', 32);
        if ($status !== '' && $status !== 'all') {
            self::assertFollowUpStatus($status);
            $where[] = "COALESCE(f.follow_up_status, 'New') = ?";
            $params[] = $status;
        }

        $paymentStatus = self::optionalText($filters, 'payment_status', 20);
        if ($paymentStatus !== '' && $paymentStatus !== 'all') {
            $where[] = 'p.payment_status = ?';
            $params[] = $paymentStatus;
        }

        self::addDateFilters($where, $params, 'e.created_at', $filters);
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $count = $this->db->prepare(
            'SELECT COUNT(*)
             FROM patient_enquiries e
             LEFT JOIN payment_records p ON p.patient_enquiry_id = e.id
             LEFT JOIN enquiry_followups f ON f.patient_enquiry_id = e.id' .
            $whereSql
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sort = self::optionalText($filters, 'sort', 16);
        if ($sort === '') {
            $sort = 'newest';
        }
        if (!in_array($sort, ['newest', 'oldest'], true)) {
            throw new InvalidArgumentException('Invalid enquiry sort order.');
        }
        $orderBy = $sort === 'oldest' ? 'ASC' : 'DESC';

        $stmt = $this->db->prepare(
            "SELECT
                e.id,
                e.full_name,
                e.primary_phone,
                e.email,
                e.reason_for_visit,
                e.enquiry_status,
                e.created_at,
                p.enquiry_reference,
                p.payment_status,
                COALESCE(f.follow_up_status, 'New') AS follow_up_status
             FROM patient_enquiries e
             LEFT JOIN payment_records p ON p.patient_enquiry_id = e.id
             LEFT JOIN enquiry_followups f ON f.patient_enquiry_id = e.id" .
            $whereSql .
            " ORDER BY e.created_at $orderBy, e.id $orderBy
              LIMIT ? OFFSET ?"
        );
        self::executePaginated($stmt, $params, $page, $perPage);

        return [
            'payment_statuses' => $this->distinctValues(
                'payment_records',
                'payment_status'
            ),
            ...self::pageResult($stmt->fetchAll(), $total, $page, $perPage),
        ];
    }

    public function getEnquiry(int $id): ?array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid enquiry reference.');
        }

        $stmt = $this->db->prepare(
            "SELECT
                e.id,
                e.full_name,
                e.primary_phone,
                e.alternate_phone,
                e.complete_address,
                e.age,
                e.email,
                e.reason_for_visit,
                e.consent_given,
                e.consented_at,
                e.enquiry_status,
                e.created_at,
                e.updated_at,
                p.enquiry_reference,
                p.payment_status,
                p.razorpay_order_id,
                p.id AS payment_record_id,
                p.razorpay_payment_id,
                p.amount_paise,
                p.currency,
                p.signature_verified,
                p.created_at AS payment_created_at,
                p.updated_at AS payment_updated_at,
                p.verified_at AS payment_verified_at,
                f.follow_up_status,
                f.updated_at AS follow_up_updated_at,
                a.username AS follow_up_updated_by
             FROM patient_enquiries e
             LEFT JOIN payment_records p ON p.patient_enquiry_id = e.id
             LEFT JOIN enquiry_followups f ON f.patient_enquiry_id = e.id
             LEFT JOIN admin_users a ON a.id = f.updated_by_admin_id
             WHERE e.id = ?
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $enquiry = $stmt->fetch();

        if (!$enquiry) {
            return null;
        }

        $enquiry['follow_up_status'] = $enquiry['follow_up_status'] ?? 'New';
        $enquiry['attempts'] = [];
        if ($enquiry['payment_record_id'] !== null) {
            $attempts = $this->db->prepare(
                'SELECT
                    id,
                    razorpay_payment_id,
                    payment_status,
                    amount_paise,
                    currency,
                    method,
                    error_code,
                    error_description,
                    created_at,
                    updated_at,
                    verified_at
                 FROM payment_attempts
                 WHERE payment_record_id = ?
                 ORDER BY created_at DESC, id DESC'
            );
            $attempts->execute([(int) $enquiry['payment_record_id']]);
            $enquiry['attempts'] = $attempts->fetchAll();
        }
        $history = $this->db->prepare(
            'SELECT
                h.previous_status,
                h.new_status,
                h.changed_at,
                a.username AS changed_by
             FROM enquiry_followup_audit h
             LEFT JOIN admin_users a ON a.id = h.admin_user_id
             WHERE h.patient_enquiry_id = ?
             ORDER BY h.changed_at DESC, h.id DESC'
        );
        $history->execute([$id]);
        $enquiry['follow_up_history'] = $history->fetchAll();
        return $enquiry;
    }

    public function updateFollowUpStatus(
        int $enquiryId,
        string $status,
        int $adminId
    ): bool {
        if ($enquiryId < 1 || $adminId < 1) {
            throw new InvalidArgumentException('Invalid follow-up update.');
        }
        self::assertFollowUpStatus($status);

        $nested = $this->db->inTransaction();
        $savepoint = 'admin_followup_update';
        if ($nested) {
            $this->db->exec('SAVEPOINT ' . $savepoint);
        } else {
            $this->db->beginTransaction();
        }

        try {
            $exists = $this->db->prepare(
                'SELECT id FROM patient_enquiries WHERE id = ? FOR UPDATE'
            );
            $exists->execute([$enquiryId]);
            if (!$exists->fetchColumn()) {
                if ($nested) {
                    $this->db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
                } else {
                    $this->db->rollBack();
                }
                return false;
            }

            $current = $this->db->prepare(
                "SELECT follow_up_status
                 FROM enquiry_followups
                 WHERE patient_enquiry_id = ?
                 FOR UPDATE"
            );
            $current->execute([$enquiryId]);
            $previousStatus = $current->fetchColumn();
            $previousStatus = $previousStatus === false
                ? 'New'
                : (string) $previousStatus;

            if ($previousStatus === $status) {
                if ($nested) {
                    $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
                } else {
                    $this->db->commit();
                }
                return true;
            }

            $upsert = $this->db->prepare(
                'INSERT INTO enquiry_followups
                    (patient_enquiry_id, follow_up_status, updated_by_admin_id)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    follow_up_status = VALUES(follow_up_status),
                    updated_by_admin_id = VALUES(updated_by_admin_id),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $upsert->execute([$enquiryId, $status, $adminId]);

            $audit = $this->db->prepare(
                'INSERT INTO enquiry_followup_audit
                    (patient_enquiry_id, admin_user_id, previous_status, new_status)
                 VALUES (?, ?, ?, ?)'
            );
            $audit->execute([$enquiryId, $adminId, $previousStatus, $status]);
            if ($nested) {
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $this->db->commit();
            }

            return true;
        } catch (Throwable $e) {
            if ($nested && $this->db->inTransaction()) {
                $this->db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            } elseif ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getPayments(array $filters): array
    {
        [$page, $perPage] = self::pagination($filters);
        $where = [];
        $params = [];
        $search = self::optionalText($filters, 'search', 120);

        if ($search !== '') {
            $where[] = "LOWER(CONCAT_WS(
                ' ',
                p.enquiry_reference,
                p.razorpay_order_id,
                p.razorpay_payment_id
            )) LIKE ?";
            $params[] = '%' . strtolower($search) . '%';
        }

        $status = self::optionalText($filters, 'status', 20);
        if ($status !== '' && $status !== 'all') {
            $where[] = 'p.payment_status = ?';
            $params[] = $status;
        }

        self::addDateFilters($where, $params, 'p.created_at', $filters);
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $count = $this->db->prepare(
            'SELECT COUNT(*)
             FROM payment_records p' . $whereSql
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT
                p.id,
                p.patient_enquiry_id,
                p.enquiry_reference,
                p.razorpay_order_id,
                p.razorpay_payment_id,
                p.amount_paise,
                p.currency,
                p.payment_status,
                p.order_creation_status,
                p.signature_verified,
                p.created_at,
                p.updated_at,
                e.full_name AS patient_name,
                e.primary_phone,
                e.email,
                (SELECT COUNT(*)
                 FROM payment_attempts pa
                 WHERE pa.payment_record_id = p.id) AS attempt_count,
                (SELECT COUNT(*)
                 FROM payment_attempts pa
                 WHERE pa.payment_record_id = p.id
                   AND pa.payment_status = \'failed\') AS failed_attempt_count
             FROM payment_records p
             LEFT JOIN patient_enquiries e ON e.id = p.patient_enquiry_id' .
            $whereSql .
            ' ORDER BY p.created_at DESC, p.id DESC
              LIMIT ? OFFSET ?'
        );
        self::executePaginated($stmt, $params, $page, $perPage);

        return [
            'statuses' => $this->distinctValues(
                'payment_records',
                'payment_status'
            ),
            ...self::pageResult($stmt->fetchAll(), $total, $page, $perPage),
        ];
    }

    public function getAttempts(array $filters): array
    {
        [$page, $perPage] = self::pagination($filters);
        $where = [];
        $params = [];
        $search = self::optionalText($filters, 'search', 120);

        if ($search !== '') {
            $where[] = "LOWER(CONCAT_WS(
                ' ',
                a.id,
                a.razorpay_payment_id,
                a.payment_status,
                p.enquiry_reference,
                p.razorpay_order_id,
                e.full_name,
                e.primary_phone,
                e.email
            )) LIKE ?";
            $params[] = '%' . strtolower($search) . '%';
        }

        $status = self::optionalText($filters, 'status', 20);
        if ($status !== '' && $status !== 'all') {
            $where[] = 'a.payment_status = ?';
            $params[] = $status;
        }

        self::addDateFilters($where, $params, 'a.created_at', $filters);
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $fromSql = '
            FROM payment_attempts a
            INNER JOIN payment_records p ON p.id = a.payment_record_id
            LEFT JOIN patient_enquiries e ON e.id = p.patient_enquiry_id';

        $count = $this->db->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT
                a.id,
                a.payment_record_id,
                a.razorpay_payment_id,
                a.payment_status,
                a.amount_paise,
                a.currency,
                a.method,
                a.error_code,
                a.error_description,
                a.created_at,
                a.updated_at,
                a.verified_at,
                p.enquiry_reference,
                p.razorpay_order_id,
                e.id AS patient_enquiry_id,
                e.full_name AS patient_name,
                e.primary_phone,
                e.email' .
            $fromSql .
            $whereSql .
            ' ORDER BY a.created_at DESC, a.id DESC
              LIMIT ? OFFSET ?'
        );
        self::executePaginated($stmt, $params, $page, $perPage);

        return [
            'statuses' => $this->distinctValues(
                'payment_attempts',
                'payment_status'
            ),
            ...self::pageResult($stmt->fetchAll(), $total, $page, $perPage),
        ];
    }

    public function getAttempt(int $id): ?array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid payment attempt reference.');
        }

        $stmt = $this->db->prepare(
            'SELECT
                a.id,
                a.payment_record_id,
                a.razorpay_payment_id,
                a.payment_status,
                a.amount_paise,
                a.currency,
                a.method,
                a.error_code,
                a.error_description,
                a.created_at,
                a.updated_at,
                a.verified_at,
                p.enquiry_reference,
                p.razorpay_order_id,
                p.payment_status AS parent_payment_status,
                e.id AS patient_enquiry_id,
                e.full_name AS patient_name,
                e.primary_phone,
                e.email
             FROM payment_attempts a
             INNER JOIN payment_records p ON p.id = a.payment_record_id
             LEFT JOIN patient_enquiries e ON e.id = p.patient_enquiry_id
             WHERE a.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $attempt = $stmt->fetch();

        return $attempt ?: null;
    }

    public function getPayment(int $id): ?array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid payment reference.');
        }

        $stmt = $this->db->prepare(
            'SELECT
                p.id,
                p.patient_enquiry_id,
                p.enquiry_reference,
                p.razorpay_order_id,
                p.razorpay_payment_id,
                p.amount_paise,
                p.currency,
                p.payment_status,
                p.order_creation_status,
                p.signature_verified,
                p.created_at,
                p.updated_at,
                p.verified_at,
                e.full_name AS patient_name,
                e.primary_phone,
                e.email
             FROM payment_records p
             LEFT JOIN patient_enquiries e ON e.id = p.patient_enquiry_id
             WHERE p.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $payment = $stmt->fetch();

        if (!$payment) {
            return null;
        }

        $attempts = $this->db->prepare(
            'SELECT
                id,
                razorpay_payment_id,
                payment_status,
                amount_paise,
                currency,
                method,
                error_code,
                error_description,
                created_at,
                updated_at,
                verified_at
             FROM payment_attempts
             WHERE payment_record_id = ?
             ORDER BY created_at DESC, id DESC'
        );
        $attempts->execute([$id]);
        $payment['attempts'] = $attempts->fetchAll();

        return $payment;
    }

    public function getWebhooks(array $filters): array
    {
        [$page, $perPage] = self::pagination($filters);
        $where = [];
        $params = [];
        $search = self::optionalText($filters, 'search', 120);

        if ($search !== '') {
            $where[] = "LOWER(CONCAT_WS(
                ' ',
                w.razorpay_event_id,
                w.event_type,
                w.payment_id,
                w.order_id,
                p_order.enquiry_reference,
                p_payment.enquiry_reference
            )) LIKE ?";
            $params[] = '%' . strtolower($search) . '%';
        }

        $status = self::optionalText($filters, 'status', 20);
        if ($status !== '' && $status !== 'all') {
            $where[] = 'w.processing_status = ?';
            $params[] = $status;
        }

        self::addDateFilters($where, $params, 'w.received_at', $filters);
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $fromSql = '
            FROM webhook_events w
            LEFT JOIN payment_records p_order
                ON p_order.razorpay_order_id = w.order_id
            LEFT JOIN payment_records p_payment
                ON p_payment.razorpay_payment_id = w.payment_id';

        $count = $this->db->prepare(
            'SELECT COUNT(*)' . $fromSql . $whereSql
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT
                w.id,
                w.razorpay_event_id,
                w.event_type,
                w.payment_id,
                w.order_id,
                w.processing_status,
                w.received_at,
                w.processed_at,
                COALESCE(
                    p_order.enquiry_reference,
                    p_payment.enquiry_reference
                ) AS enquiry_reference" .
            $fromSql .
            $whereSql .
            ' ORDER BY w.received_at DESC, w.id DESC
              LIMIT ? OFFSET ?'
        );
        self::executePaginated($stmt, $params, $page, $perPage);

        return [
            'statuses' => $this->distinctValues(
                'webhook_events',
                'processing_status'
            ),
            ...self::pageResult($stmt->fetchAll(), $total, $page, $perPage),
        ];
    }

    public function getWebhook(int $id): ?array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid webhook event reference.');
        }

        $stmt = $this->db->prepare(
            'SELECT
                w.id,
                w.razorpay_event_id,
                w.event_type,
                w.payment_id,
                w.order_id,
                w.processing_status,
                w.received_at,
                w.processed_at,
                COALESCE(
                    p_order.enquiry_reference,
                    p_payment.enquiry_reference
                ) AS enquiry_reference
             FROM webhook_events w
             LEFT JOIN payment_records p_order
                ON p_order.razorpay_order_id = w.order_id
             LEFT JOIN payment_records p_payment
                ON p_payment.razorpay_payment_id = w.payment_id
             WHERE w.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $event = $stmt->fetch();

        return $event ?: null;
    }

    private function distinctValues(string $table, string $column): array
    {
        $allowed = [
            'payment_records' => ['payment_status'],
            'payment_attempts' => ['payment_status'],
            'webhook_events' => ['processing_status'],
        ];
        if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
            throw new LogicException('Unsupported status column.');
        }

        $stmt = $this->db->query(
            "SELECT DISTINCT $column FROM $table ORDER BY $column"
        );
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function pagination(array $filters): array
    {
        $page = self::positiveInteger($filters['page'] ?? 1, 'page', 100000);
        $perPage = self::positiveInteger($filters['per_page'] ?? 25, 'page size', 50);

        if (!in_array($perPage, [10, 25, 50], true)) {
            throw new InvalidArgumentException('Invalid page size.');
        }

        return [$page, $perPage];
    }

    private static function positiveInteger(
        $value,
        string $field,
        int $maximum
    ): int {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Invalid ' . $field . '.');
        }

        $integer = filter_var(
            (string) $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => $maximum]]
        );

        if ($integer === false) {
            throw new InvalidArgumentException('Invalid ' . $field . '.');
        }

        return $integer;
    }

    private static function optionalText(
        array $filters,
        string $field,
        int $maxLength
    ): string {
        $value = $filters[$field] ?? '';

        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid filter value.');
        }

        $value = trim($value);
        if (strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Invalid filter value.');
        }

        return $value;
    }

    private static function addDateFilters(
        array &$where,
        array &$params,
        string $column,
        array $filters
    ): void {
        $from = self::optionalDate($filters, 'from');
        $to = self::optionalDate($filters, 'to');

        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('The start date must not be after the end date.');
        }
        if ($from !== null) {
            $where[] = $column . ' >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $where[] = $column . ' < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $to . ' 00:00:00';
        }
    }

    private static function optionalDate(array $filters, string $field): ?string
    {
        $value = self::optionalText($filters, $field, 10);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false ||
            ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ||
            $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException('Invalid date filter.');
        }

        return $value;
    }

    private static function assertFollowUpStatus(string $status): void
    {
        if (!in_array($status, self::FOLLOW_UP_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid follow-up status.');
        }
    }

    private static function executePaginated(
        PDOStatement $stmt,
        array $params,
        int $page,
        int $perPage
    ): void {
        foreach (array_values($params) as $index => $value) {
            $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
        }

        $stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
    }

    private static function pageResult(
        array $items,
        int $total,
        int $page,
        int $perPage
    ): array {
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) ceil($total / $perPage),
        ];
    }
}
