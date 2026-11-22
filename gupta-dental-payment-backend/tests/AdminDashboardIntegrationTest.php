<?php
declare(strict_types=1);

$testDatabase = getenv('GDC_ADMIN_TEST_DB');
if (
    PHP_SAPI !== 'cli' ||
    !is_string($testDatabase) ||
    !preg_match('/^gdc_admin_dash_test_[a-f0-9]{12}$/', $testDatabase)
) {
    fwrite(STDERR, "Set GDC_ADMIN_TEST_DB to a disposable gdc_admin_dash_test_* database.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/services/AdminAuth.php';
require_once dirname(__DIR__) . '/services/AdminDashboardService.php';
require_once dirname(__DIR__) . '/services/AdminUserService.php';

$db = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . $testDatabase . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

function expectResult(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db->beginTransaction();

try {
    $suffix = bin2hex(random_bytes(5));
    $enquiryInsert = $db->prepare(
        'INSERT INTO patient_enquiries
            (request_key_hash, full_name, primary_phone, complete_address,
             age, email, reason_for_visit, consent_given)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $enquiryInsert->execute([
        hash('sha256', 'admin-test-a-' . $suffix),
        'Admin Test Alpha',
        '+919876543210',
        '1 Test Road',
        35,
        'alpha@example.test',
        'Routine checkup',
    ]);
    $capturedEnquiryId = (int) $db->lastInsertId();

    $enquiryInsert->execute([
        hash('sha256', 'admin-test-b-' . $suffix),
        'Admin Test Beta',
        '+919876543211',
        '2 Test Road',
        40,
        'beta@example.test',
        'Toothache',
    ]);
    $pendingEnquiryId = (int) $db->lastInsertId();

    $enquiryInsert->execute([
        hash('sha256', 'admin-test-c-' . $suffix),
        'Admin Test Gamma',
        '+919876543212',
        '3 Test Road',
        28,
        'gamma@example.test',
        'Dental implants',
    ]);

    $paymentInsert = $db->prepare(
        'INSERT INTO payment_records
            (patient_enquiry_id, enquiry_reference, razorpay_order_id,
             razorpay_payment_id, amount_paise, currency, payment_status,
             signature_verified)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $paymentInsert->execute([
        $capturedEnquiryId,
        'GDC-TEST-' . $suffix . '-1',
        'order_test_' . $suffix . '_1',
        'pay_test_' . $suffix . '_1',
        500,
        'INR',
        'captured',
        1,
    ]);
    $capturedPaymentId = (int) $db->lastInsertId();

    $paymentInsert->execute([
        $pendingEnquiryId,
        null,
        'order_test_' . $suffix . '_2',
        null,
        500,
        'INR',
        'created',
        0,
    ]);
    $pendingPaymentId = (int) $db->lastInsertId();

    $attempt = $db->prepare(
        'INSERT INTO payment_attempts
            (payment_record_id, razorpay_payment_id, payment_status, amount_paise)
         VALUES (?, ?, ?, ?)'
    );
    $attempt->execute([
        $capturedPaymentId,
        'pay_test_' . $suffix . '_1',
        'captured',
        500,
    ]);
    $attempt->execute([
        $pendingPaymentId,
        'pay_test_' . $suffix . '_failed',
        'failed',
        500,
    ]);

    $eventId = 'event_test_' . $suffix;
    $event = $db->prepare(
        'INSERT INTO webhook_events
            (razorpay_event_id, event_type, payment_id, order_id)
         VALUES (?, ?, ?, ?)'
    );
    $event->execute([
        $eventId,
        'payment.captured',
        'pay_test_' . $suffix . '_1',
        'order_test_' . $suffix . '_1',
    ]);
    $webhookId = (int) $db->lastInsertId();

    $username = 'admin-' . $suffix;
    $adminPassword = 'Disposable-Test-Pass-2030!';
    $adminId = AdminUserService::createFirstAdmin(
        $db,
        $username,
        $adminPassword
    );
    $backupUsername = 'backup-' . $suffix;
    $adminInsert = $db->prepare(
        'INSERT INTO admin_users (username, password_hash) VALUES (?, ?)'
    );
    $adminInsert->execute([
        $backupUsername,
        password_hash('Disposable-Backup-Pass-2030!', PASSWORD_DEFAULT),
    ]);
    $boundaryPassword = str_repeat('p', 72);
    AdminUserService::resetPassword($db, $backupUsername, $boundaryPassword);
    expectResult(
        !AdminAuth::authenticate($db, $backupUsername, $boundaryPassword . 'x'),
        'An overlong password passed authentication.'
    );
    expectResult(
        AdminAuth::authenticate($db, $backupUsername, $boundaryPassword),
        'A valid maximum-length password was rejected.'
    );
    AdminAuth::destroy();
    $duplicateInitialSetupRejected = false;
    try {
        AdminUserService::createFirstAdmin(
            $db,
            'second-initial-admin',
            'Disposable-Other-Pass-2030!'
        );
    } catch (RuntimeException $e) {
        $duplicateInitialSetupRejected = true;
    }
    expectResult(
        $duplicateInitialSetupRejected,
        'Initial account setup allowed a second first administrator.'
    );

    $dashboard = new AdminDashboardService($db);
    $overview = $dashboard->getOverview();
    expectResult($overview['total_enquiries'] === 3, 'Total enquiry count failed.');
    expectResult($overview['new_enquiries'] === 3, 'New follow-up count failed.');
    expectResult($overview['pending_payments'] === 1, 'Pending payment count failed.');
    expectResult($overview['successful_payments'] === 1, 'Captured payment count failed.');
    expectResult($overview['failed_payment_attempts'] === 1, 'Failed attempt count failed.');
    expectResult($overview['captured_amount_paise'] === 500, 'Captured amount total failed.');

    $enquiries = $dashboard->getEnquiries([
        'search' => 'alpha@example.test',
        'from' => date('Y-m-d'),
        'to' => date('Y-m-d'),
        'page' => 1,
        'per_page' => 10,
    ]);
    expectResult(
        $enquiries['total'] === 1 &&
        $enquiries['items'][0]['full_name'] === 'Admin Test Alpha',
        'Enquiry search/date filtering failed.'
    );
    expectResult(
        $dashboard->getEnquiries(['status' => 'New'])['total'] === 3,
        'Enquiry follow-up status filtering failed.'
    );
    expectResult(
        $dashboard->getEnquiries([
            'payment_status' => 'captured',
        ])['total'] === 1,
        'Enquiry payment status filtering failed.'
    );
    $capturedPayments = $dashboard->getPayments(['status' => 'captured']);
    expectResult(
        $capturedPayments['total'] === 1 &&
        $capturedPayments['items'][0]['payment_status'] === 'captured',
        'Payment status filtering failed.'
    );

    $invalidStatusRejected = false;
    try {
        $dashboard->updateFollowUpStatus(
            $capturedEnquiryId,
            'appointment_booked',
            $adminId
        );
    } catch (InvalidArgumentException $e) {
        $invalidStatusRejected = true;
    }
    expectResult($invalidStatusRejected, 'Invalid follow-up status was accepted.');

    $dashboard->updateFollowUpStatus($capturedEnquiryId, 'Contacted', $adminId);
    $captured = $dashboard->getEnquiry($capturedEnquiryId);
    expectResult($captured['follow_up_status'] === 'Contacted', 'Follow-up update failed.');
    expectResult(
        $captured['enquiry_status'] === 'pending_payment' &&
        $captured['payment_status'] === 'captured',
        'Follow-up update changed payment lifecycle state.'
    );
    $followUpAudit = $db->prepare(
        'SELECT previous_status, new_status, admin_user_id
         FROM enquiry_followup_audit
         WHERE patient_enquiry_id = ?
         ORDER BY id DESC
         LIMIT 1'
    );
    $followUpAudit->execute([$capturedEnquiryId]);
    $auditEntry = $followUpAudit->fetch();
    expectResult(
        $auditEntry['previous_status'] === 'New' &&
        $auditEntry['new_status'] === 'Contacted' &&
        (int) $auditEntry['admin_user_id'] === $adminId,
        'Follow-up status audit did not record the administrator and transition.'
    );
    expectResult(
        $captured['follow_up_history'][0]['previous_status'] === 'New' &&
        $captured['follow_up_history'][0]['new_status'] === 'Contacted' &&
        $captured['follow_up_history'][0]['changed_by'] === $username,
        'Enquiry details did not return the follow-up audit history.'
    );
    expectResult(
        count($captured['follow_up_history']) === 1 &&
        $captured['follow_up_history'][0]['previous_status'] === 'New' &&
        $captured['follow_up_history'][0]['new_status'] === 'Contacted' &&
        $captured['follow_up_history'][0]['changed_by'] === $username &&
        $captured['follow_up_history'][0]['changed_at'] !== null,
        'Enquiry details did not return the full follow-up audit trail.'
    );
    $dashboard->updateFollowUpStatus($capturedEnquiryId, 'Contacted', $adminId);
    expectResult(
        (int) $db->query(
            'SELECT COUNT(*) FROM enquiry_followup_audit WHERE patient_enquiry_id = ' .
            (int) $capturedEnquiryId
        )->fetchColumn() === 1,
        'An unchanged follow-up status created a duplicate audit entry.'
    );
    expectResult(
        $dashboard->getOverview()['new_enquiries'] === 2,
        'New enquiry count did not reflect the follow-up update.'
    );

    for ($i = 0; $i < 8; $i++) {
        $enquiryInsert->execute([
            hash('sha256', 'admin-test-pagination-' . $suffix . '-' . $i),
            'Pagination Test ' . $i,
            '+91987650000' . $i,
            'Test address ' . $i,
            30,
            'pagination' . $i . '@example.test',
            'Pagination test enquiry',
        ]);
    }

    $secondPage = $dashboard->getEnquiries([
        'page' => 2,
        'per_page' => 10,
        'sort' => 'oldest',
    ]);
    expectResult(
        count($secondPage['items']) === 1 &&
        $secondPage['total'] === 11 &&
        $secondPage['pages'] === 2,
        'Historical enquiry pagination failed.'
    );
    expectResult(
        $dashboard->getEnquiries(['search' => "' OR 1=1 --"])['total'] === 0,
        'Enquiry search did not safely bind untrusted input.'
    );

    $payments = $dashboard->getPayments([
        'search' => 'order_test_' . $suffix . '_2',
        'page' => 1,
        'per_page' => 10,
    ]);
    expectResult(
        $payments['total'] === 1 &&
        $payments['items'][0]['payment_status'] === 'created',
        'Payment search/status listing failed.'
    );
    $paymentDetail = $dashboard->getPayment($pendingPaymentId);
    expectResult(
        count($paymentDetail['attempts']) === 1 &&
        $paymentDetail['attempts'][0]['payment_status'] === 'failed',
        'Related failed payment attempt detail failed.'
    );
    $attempts = $dashboard->getAttempts(['status' => 'failed']);
    expectResult(
        $attempts['total'] === 1 &&
        $attempts['items'][0]['error_description'] === null,
        'Failed attempt listing/status filtering failed.'
    );
    expectResult(
        $dashboard->getAttempt((int) $attempts['items'][0]['id'])['parent_payment_status'] === 'created',
        'Attempt detail did not preserve the parent payment state.'
    );

    $webhooks = $dashboard->getWebhooks(['search' => $eventId]);
    $webhookDetail = $dashboard->getWebhook($webhookId);
    expectResult(
        $webhooks['total'] === 1 &&
        $webhooks['items'][0]['enquiry_reference'] === 'GDC-TEST-' . $suffix . '-1' &&
        $webhookDetail['event_type'] === 'payment.captured',
        'Webhook event listing or related reference failed.'
    );
    expectResult(
        (int) $db->query('SELECT COUNT(*) FROM patient_enquiries')->fetchColumn() === 11 &&
        (int) $db->query('SELECT COUNT(*) FROM payment_records')->fetchColumn() === 2 &&
        (int) $db->query('SELECT COUNT(*) FROM payment_attempts')->fetchColumn() === 2 &&
        (int) $db->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn() === 1,
        'Dashboard data retrieval changed existing record counts.'
    );

    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    AdminAuth::start();
    $beforeLoginSession = session_id();
    expectResult(
        !AdminAuth::authenticate($db, $username, 'incorrect-password'),
        'Invalid administrator password was accepted.'
    );
    expectResult(
        !AdminAuth::authenticate($db, 'bad username', 'incorrect-password'),
        'Invalid administrator username was accepted.'
    );
    expectResult(
        (int) $db->query('SELECT COUNT(*) FROM admin_login_attempts')->fetchColumn() === 2,
        'Malformed username attempts were not recorded for throttling.'
    );
    expectResult(
        AdminAuth::authenticate($db, strtoupper($username), $adminPassword),
        'Valid administrator password was rejected.'
    );
    expectResult(
        session_id() !== $beforeLoginSession && AdminAuth::isAuthenticated($db),
        'Successful login did not rotate/authenticate the session.'
    );
    $csrf = AdminAuth::csrfToken();
    expectResult(
        AdminAuth::verifyCsrf($csrf) && !AdminAuth::verifyCsrf('invalid'),
        'CSRF token verification failed.'
    );

    $renamedUsername = 'updated-' . $suffix;
    expectResult(
        AdminUserService::changeUsername($db, $username, $renamedUsername) === $renamedUsername &&
        AdminAuth::isAuthenticated($db) &&
        AdminAuth::adminUsername() === $renamedUsername,
        'Username change or active session update failed.'
    );

    $duplicateUsernameRejected = false;
    try {
        AdminUserService::changeUsername($db, $renamedUsername, $backupUsername);
    } catch (InvalidArgumentException $e) {
        $duplicateUsernameRejected = true;
    }
    expectResult($duplicateUsernameRejected, 'Duplicate administrator username was accepted.');

    $newPassword = 'Disposable-Test-Pass-2031!';
    AdminUserService::resetPassword($db, $renamedUsername, $newPassword);
    expectResult(!AdminAuth::isAuthenticated($db), 'Password reset did not invalidate the session.');
    expectResult(
        !AdminAuth::authenticate($db, $renamedUsername, $adminPassword) &&
        AdminAuth::authenticate($db, $renamedUsername, $newPassword),
        'Password reset did not replace the administrator password.'
    );

    expectResult(
        AdminUserService::setActive($db, $renamedUsername, false) &&
        !AdminAuth::isAuthenticated($db) &&
        !AdminAuth::authenticate($db, $renamedUsername, $newPassword),
        'Deactivation did not revoke administrator access.'
    );
    expectResult(
        AdminUserService::setActive($db, $renamedUsername, true) &&
        AdminAuth::authenticate($db, $renamedUsername, $newPassword),
        'Administrator activation failed.'
    );

    AdminUserService::setActive($db, $backupUsername, false);
    $lastAdminProtection = false;
    try {
        AdminUserService::setActive($db, $renamedUsername, false);
    } catch (RuntimeException $e) {
        $lastAdminProtection = str_contains($e->getMessage(), 'last active administrator');
    }
    expectResult($lastAdminProtection, 'The last active administrator could be deactivated.');
    AdminUserService::setActive($db, $backupUsername, true);

    $_SESSION['admin_last_activity'] = time() - 1801;
    session_write_close();
    expectResult(!AdminAuth::isAuthenticated($db), 'Expired session remained authenticated.');
    AdminAuth::destroy();
    expectResult(!AdminAuth::hasAdminSession(), 'Logout left the session authenticated.');
    $db->exec('DELETE FROM admin_login_attempts');
    AdminAuth::start();
    for ($i = 0; $i < 5; $i++) {
        expectResult(
            !AdminAuth::authenticate($db, $renamedUsername, 'incorrect-password'),
            'Invalid password was accepted during rate-limit test.'
        );
    }
    expectResult(
        !AdminAuth::authenticate($db, $renamedUsername, $newPassword),
        'Login rate limit did not block repeated failures.'
    );

    $db->exec('DELETE FROM admin_login_attempts');
    $seedAttempt = $db->prepare(
        'INSERT INTO admin_login_attempts (username_hash, client_ip)
         VALUES (?, ?)'
    );
    for ($i = 0; $i < 30; $i++) {
        $seedAttempt->execute([
            hash('sha256', 'shared-ip-' . ($i % 7)),
            '192.0.2.20',
        ]);
    }
    $_SERVER['REMOTE_ADDR'] = '192.0.2.20';
    expectResult(
        !AdminAuth::authenticate($db, $backupUsername, $boundaryPassword),
        'Shared-IP authentication throttling was bypassed.'
    );

    $db->exec('DELETE FROM admin_login_attempts');
    for ($i = 0; $i < 5; $i++) {
        $seedAttempt->execute([
            hash('sha256', $renamedUsername),
            '192.0.2.' . (30 + $i),
        ]);
    }
    $_SERVER['REMOTE_ADDR'] = '192.0.2.40';
    expectResult(
        !AdminAuth::authenticate($db, $renamedUsername, $newPassword),
        'Distributed attempts against one username bypassed throttling.'
    );

    $db->exec('DELETE FROM admin_login_attempts');
    for ($i = 0; $i < 100; $i++) {
        $seedAttempt->execute([
            hash('sha256', 'distributed-user-' . $i),
            '198.51.' . (intdiv($i, 254) + 1) . '.' . (($i % 254) + 1),
        ]);
    }
    $_SERVER['REMOTE_ADDR'] = '203.0.113.250';
    expectResult(
        !AdminAuth::authenticate($db, $backupUsername, $boundaryPassword),
        'Distributed attempts across usernames and IPs bypassed the global throttle.'
    );

    $db->exec('DELETE FROM admin_login_attempts');
    for ($i = 0; $i < 8; $i++) {
        $seedAttempt->execute([
            hash('sha256', 'shared-ip-user-' . $i),
            '192.0.2.60',
        ]);
    }
    $_SERVER['REMOTE_ADDR'] = '192.0.2.60';
    expectResult(
        !AdminAuth::authenticate($db, $backupUsername, $boundaryPassword),
        'Shared-IP username-bucket throttling was bypassed.'
    );

    $db->exec('DELETE FROM admin_login_attempts');
    $expiredAttempt = $db->prepare(
        'INSERT INTO admin_login_attempts
            (username_hash, client_ip, attempted_at)
         VALUES (?, ?, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 DAY))'
    );
    $expiredAttempt->execute([hash('sha256', 'expired-login'), '192.0.2.77']);
    $_SERVER['REMOTE_ADDR'] = '192.0.2.77';
    expectResult(
        !AdminAuth::authenticate($db, 'cleanup-check', 'incorrect-password'),
        'Unknown account unexpectedly authenticated.'
    );
    expectResult(
        (int) $db->query(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE attempted_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)'
        )->fetchColumn() === 1,
        'Login authentication performed expired-attempt cleanup.'
    );
    expectResult(
        AdminAuth::pruneExpiredLoginAttempts($db) === 1 &&
        (int) $db->query(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE attempted_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)'
        )->fetchColumn() === 0,
        'Explicit login-attempt cleanup did not remove expired rows.'
    );

    $auditCount = $db->prepare(
        'SELECT COUNT(*) FROM admin_audit_log WHERE target_admin_id = ?'
    );
    $auditCount->execute([$adminId]);
    expectResult(
        (int) $auditCount->fetchColumn() >= 5,
        'Credential changes were not recorded in the admin audit log.'
    );

} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        AdminAuth::destroy();
    }
}

echo "Admin dashboard integration tests passed on the disposable database.\n";
