<?php
declare(strict_types=1);

final class PatientEnquiryService
{
    private const REASONS = [
        'Toothache',
        'Tooth decay / cavity',
        'Bleeding gums',
        'Swollen gums',
        'Missing teeth',
        'Teeth alignment',
        'Teeth whitening',
        'Dental implants',
        'Routine checkup',
        'Other',
    ];

    private PDO $db;

    public function __construct()
    {
        require_once __DIR__ . '/Database.php';
        $this->db = Database::connect();
    }

    public function createOrFind(array $input, string $requestToken): array
    {
        $values = self::validate($input);
        $tokenHash = self::hashRequestToken($requestToken);

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO patient_enquiries
                    (request_key_hash, full_name, primary_phone, alternate_phone,
                     complete_address, age, email, reason_for_visit, consent_given)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $tokenHash,
                $values['full_name'],
                $values['primary_phone'],
                $values['alternate_phone'],
                $values['complete_address'],
                $values['age'],
                $values['email'],
                $values['reason_for_visit'],
                1,
            ]);

            $id = (int) $this->db->lastInsertId();
            $this->db->commit();

            return [
                'id' => $id,
                'enquiry_status' => 'pending_payment',
            ];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($e->getCode() !== '23000') {
                throw $e;
            }

            $stmt = $this->db->prepare(
                'SELECT id, full_name, primary_phone, alternate_phone,
                        complete_address, age, email, reason_for_visit,
                        consent_given, enquiry_status
                 FROM patient_enquiries
                 WHERE request_key_hash = ?
                 LIMIT 1'
            );
            $stmt->execute([$tokenHash]);
            $existing = $stmt->fetch();

            if (!$existing) {
                throw $e;
            }

            self::assertSameEnquiry($values, $existing);

            return [
                'id' => (int) $existing['id'],
                'enquiry_status' => (string) $existing['enquiry_status'],
            ];
        }
    }

    public function findByRequestToken(string $requestToken): ?array
    {
        $tokenHash = self::hashRequestToken($requestToken);
        $stmt = $this->db->prepare(
            'SELECT id, enquiry_status
             FROM patient_enquiries
             WHERE request_key_hash = ?
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $enquiry = $stmt->fetch();

        if (!$enquiry) {
            return null;
        }

        return [
            'id' => (int) $enquiry['id'],
            'enquiry_status' => (string) $enquiry['enquiry_status'],
        ];
    }

    public static function validate(array $input): array
    {
        $fullName = self::requiredString($input, 'full_name', 120);
        $primaryPhone = self::normalizePhone(
            self::requiredString($input, 'primary_phone', 20),
            'primary_phone'
        );
        $alternatePhone = self::optionalString($input, 'alternate_phone', 20);
        $alternatePhone = $alternatePhone === ''
            ? null
            : self::normalizePhone($alternatePhone, 'alternate_phone');
        $address = self::requiredString($input, 'complete_address', 500);
        $ageValue = $input['age'] ?? null;

        if (!is_string($ageValue) && !is_int($ageValue)) {
            throw new InvalidArgumentException('Invalid age.');
        }

        $age = filter_var(
            (string) $ageValue,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 120]]
        );

        if ($age === false) {
            throw new InvalidArgumentException('Invalid age.');
        }

        $email = self::optionalString($input, 'email', 254);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address.');
        }

        $reason = self::requiredString($input, 'reason_for_visit', 80);

        if (!in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Invalid reason for visit.');
        }

        $consent = $input['consent'] ?? null;

        if (!in_array($consent, [true, 1, '1'], true)) {
            throw new InvalidArgumentException('Consent is required.');
        }

        return [
            'full_name' => $fullName,
            'primary_phone' => $primaryPhone,
            'alternate_phone' => $alternatePhone,
            'complete_address' => $address,
            'age' => $age,
            'email' => $email === '' ? null : $email,
            'reason_for_visit' => $reason,
        ];
    }

    public static function hashRequestToken(string $requestToken): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $requestToken)) {
            throw new InvalidArgumentException('Invalid enquiry request token.');
        }

        return hash('sha256', $requestToken);
    }

    private static function requiredString(
        array $input,
        string $field,
        int $maxLength
    ): string {
        $value = $input[$field] ?? null;

        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid enquiry details.');
        }

        $value = trim($value);

        if (
            $value === '' ||
            mb_strlen($value, 'UTF-8') > $maxLength ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)
        ) {
            throw new InvalidArgumentException('Invalid enquiry details.');
        }

        return $value;
    }

    private static function optionalString(
        array $input,
        string $field,
        int $maxLength
    ): string {
        $value = $input[$field] ?? '';

        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid enquiry details.');
        }

        $value = trim($value);

        if (
            mb_strlen($value, 'UTF-8') > $maxLength ||
            preg_match('/[\x00-\x1F\x7F]/u', $value)
        ) {
            throw new InvalidArgumentException('Invalid enquiry details.');
        }

        return $value;
    }

    private static function normalizePhone(string $value, string $field): string
    {
        $normalized = preg_replace('/[\s().-]/u', '', $value);

        if (
            !is_string($normalized) ||
            !preg_match('/^(?:\+?91)?[6-9][0-9]{9}$/', $normalized)
        ) {
            throw new InvalidArgumentException('Invalid ' . $field . '.');
        }

        return $normalized;
    }

    private static function assertSameEnquiry(
        array $values,
        array $existing
    ): void {
        foreach ($values as $field => $value) {
            $stored = $existing[$field];

            if ($field === 'age') {
                $stored = (int) $stored;
            }

            if ($value !== $stored) {
                throw new DomainException(
                    'This request token is already associated with different details.',
                );
            }
        }

        if ((int) $existing['consent_given'] !== 1) {
            throw new RuntimeException('Stored enquiry consent is invalid.');
        }
    }
}
