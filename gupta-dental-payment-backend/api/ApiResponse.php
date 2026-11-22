<?php
declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus
    ) {
        parent::__construct($message);
    }
}

final class ApiResponse
{
    public static function initialize(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }

    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new ApiException('Method not allowed.', 405);
        }
    }

    public static function readJsonBody(int $maxBytes = 16384): array
    {
        $contentType = strtolower(trim(explode(
            ';',
            $_SERVER['CONTENT_TYPE'] ?? ''
        )[0]));

        if ($contentType !== 'application/json') {
            throw new ApiException('JSON request body required.', 415);
        }

        $body = file_get_contents('php://input', false, null, 0, $maxBytes + 1);

        if ($body === false || strlen($body) > $maxBytes) {
            throw new ApiException('Request body is too large.', 413);
        }

        try {
            $decoded = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException('Invalid JSON request body.', 400);
        }

        if (!$decoded instanceof stdClass) {
            throw new ApiException('JSON object required.', 400);
        }

        return get_object_vars($decoded);
    }

    public static function send(int $status, array $response): void
    {
        http_response_code($status);

        echo json_encode(
            $response,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
