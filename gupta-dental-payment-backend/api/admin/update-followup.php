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

    $input = ApiResponse::readJsonBody(2048);
    $idValue = $input['id'] ?? null;
    if (!is_string($idValue) && !is_int($idValue)) {
        throw new ApiException('Invalid follow-up update.', 400);
    }
    $id = filter_var(
        $idValue,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $status = $input['status'] ?? null;

    if ($id === false || !is_string($status)) {
        throw new ApiException('Invalid follow-up update.', 400);
    }

    $db = Database::connect();
    if (!AdminAuth::isAuthenticated($db)) {
        throw new ApiException('Authentication required.', 401);
    }

    $dashboard = new AdminDashboardService($db);
    if (!$dashboard->updateFollowUpStatus($id, $status, AdminAuth::adminId())) {
        throw new ApiException('Enquiry not found.', 404);
    }

    ApiResponse::send(200, [
        'success' => true,
        'message' => 'Follow-up status updated. This does not change payment status or confirm an appointment.',
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
        error_log('Administrator follow-up update failed.');
        ApiResponse::send(500, [
            'success' => false,
            'message' => 'The follow-up status could not be updated.',
        ]);
    }
} catch (Throwable $e) {
    error_log('Administrator follow-up update failed.');
    ApiResponse::send(500, [
        'success' => false,
        'message' => 'The follow-up status could not be updated.',
    ]);
}
