<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ApiResponse.php';
require_once dirname(__DIR__, 2) . '/services/AdminAuth.php';
require_once dirname(__DIR__, 2) . '/services/Database.php';

ApiResponse::initialize();

try {
    AdminAuth::start();
    $action = $_GET['action'] ?? '';

    if (!is_string($action)) {
        throw new ApiException('Invalid authentication action.', 400);
    }

    if ($action === 'session' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        if (
            !AdminAuth::hasAdminSession() ||
            !AdminAuth::isAuthenticated(Database::connect())
        ) {
            throw new ApiException('Authentication required.', 401);
        }

        ApiResponse::send(200, [
            'success' => true,
            'data' => [
                'username' => AdminAuth::adminUsername(),
                'csrf_token' => AdminAuth::csrfToken(),
            ],
        ]);
        exit;
    }

    ApiResponse::requirePost();
    if (!AdminAuth::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        throw new ApiException('The security token is invalid. Refresh and try again.', 403);
    }

    if ($action === 'login') {
        $input = ApiResponse::readJsonBody(2048);
        $username = $input['username'] ?? null;
        $password = $input['password'] ?? null;

        if (!is_string($username) || !is_string($password)) {
            throw new ApiException('Enter a valid username and password.', 400);
        }

        if (!AdminAuth::authenticate(Database::connect(), $username, $password)) {
            throw new ApiException(
                'Unable to sign in. Check your credentials or wait before trying again.',
                401
            );
        }

        ApiResponse::send(200, [
            'success' => true,
            'data' => [
                'username' => AdminAuth::adminUsername(),
                'csrf_token' => AdminAuth::csrfToken(),
            ],
        ]);
        exit;
    }

    if ($action === 'logout') {
        if (
            !AdminAuth::hasAdminSession() ||
            !AdminAuth::isAuthenticated(Database::connect())
        ) {
            throw new ApiException('Authentication required.', 401);
        }

        AdminAuth::destroy();
        ApiResponse::send(200, [
            'success' => true,
            'message' => 'You have been signed out.',
        ]);
        exit;
    }

    throw new ApiException('Authentication action not found.', 404);
} catch (ApiException $e) {
    ApiResponse::send($e->httpStatus, [
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
        error_log('Administrator authentication request failed.');
        ApiResponse::send(500, [
            'success' => false,
            'message' => 'Authentication is temporarily unavailable.',
        ]);
    }
} catch (Throwable $e) {
    error_log('Administrator authentication request failed.');
    ApiResponse::send(500, [
        'success' => false,
        'message' => 'Authentication is temporarily unavailable.',
    ]);
}
