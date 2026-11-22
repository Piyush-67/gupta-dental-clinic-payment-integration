<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ApiResponse.php';
require_once dirname(__DIR__, 2) . '/services/AdminAuth.php';
require_once dirname(__DIR__, 2) . '/services/AdminDashboardService.php';
require_once dirname(__DIR__, 2) . '/services/Database.php';

ApiResponse::initialize();

try {
    ApiResponse::requirePost();

    if (!AdminAuth::hasAdminSession()) {
        throw new ApiException('Authentication required.', 401);
    }
    if (!AdminAuth::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        throw new ApiException('The security token is invalid. Refresh and try again.', 403);
    }

    $filters = ApiResponse::readJsonBody(8192);

    $action = $_GET['action'] ?? '';
    if (!is_string($action)) {
        throw new ApiException('Invalid dashboard action.', 400);
    }

    $db = Database::connect();
    if (!AdminAuth::isAuthenticated($db)) {
        throw new ApiException('Authentication required.', 401);
    }
    $dashboard = new AdminDashboardService($db);

    switch ($action) {
        case 'overview':
            $data = $dashboard->getOverview();
            break;
        case 'enquiries':
            $data = $dashboard->getEnquiries($filters);
            break;
        case 'enquiry':
            $idValue = $filters['id'] ?? null;
            if (!is_string($idValue) && !is_int($idValue)) {
                throw new ApiException('Invalid enquiry reference.', 400);
            }
            $id = filter_var(
                $idValue,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($id === false) {
                throw new ApiException('Invalid enquiry reference.', 400);
            }
            $data = $dashboard->getEnquiry($id);
            if ($data === null) {
                throw new ApiException('Enquiry not found.', 404);
            }
            break;
        case 'payments':
            $data = $dashboard->getPayments($filters);
            break;
        case 'attempts':
            $data = $dashboard->getAttempts($filters);
            break;
        case 'payment':
            $idValue = $filters['id'] ?? null;
            if (!is_string($idValue) && !is_int($idValue)) {
                throw new ApiException('Invalid payment reference.', 400);
            }
            $id = filter_var(
                $idValue,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($id === false) {
                throw new ApiException('Invalid payment reference.', 400);
            }
            $data = $dashboard->getPayment($id);
            if ($data === null) {
                throw new ApiException('Payment record not found.', 404);
            }
            break;
        case 'attempt':
            $idValue = $filters['id'] ?? null;
            if (!is_string($idValue) && !is_int($idValue)) {
                throw new ApiException('Invalid payment attempt reference.', 400);
            }
            $id = filter_var(
                $idValue,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($id === false) {
                throw new ApiException('Invalid payment attempt reference.', 400);
            }
            $data = $dashboard->getAttempt($id);
            if ($data === null) {
                throw new ApiException('Payment attempt not found.', 404);
            }
            break;
        case 'webhooks':
            $data = $dashboard->getWebhooks($filters);
            break;
        case 'webhook':
            $idValue = $filters['id'] ?? null;
            if (!is_string($idValue) && !is_int($idValue)) {
                throw new ApiException('Invalid webhook event reference.', 400);
            }
            $id = filter_var(
                $idValue,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($id === false) {
                throw new ApiException('Invalid webhook event reference.', 400);
            }
            $data = $dashboard->getWebhook($id);
            if ($data === null) {
                throw new ApiException('Webhook event not found.', 404);
            }
            break;
        default:
            throw new ApiException('Dashboard data action not found.', 404);
    }

    ApiResponse::send(200, [
        'success' => true,
        'data' => $data,
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
} catch (PDOException $e) {
    if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
        error_log('Administrator schema is missing or outdated.');
        ApiResponse::send(503, [
            'success' => false,
            'message' => 'Administrator setup is incomplete. Contact the clinic operator.',
        ]);
    } else {
        error_log('Administrator data request failed.');
        ApiResponse::send(500, [
            'success' => false,
            'message' => 'Dashboard data is temporarily unavailable.',
        ]);
    }
} catch (Throwable $e) {
    error_log('Administrator data request failed.');
    ApiResponse::send(500, [
        'success' => false,
        'message' => 'Dashboard data is temporarily unavailable.',
    ]);
}
