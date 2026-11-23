<?php declare(strict_types=1);

namespace Quermy\Http;

class Json
{
    public static function send(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        exit;
    }

    public static function error(string $message, int $status = 400): void
    {
        self::send(['error' => $message], $status);
    }

    public static function readBody(): array
    {
        $raw = file_get_contents('php://input');

        if (!$raw)
            return [];

        // Only accept bodies declared as JSON. A plain HTML form on another
        // site can POST text/plain or form-encoded data without the browser
        // asking permission first; it can never send application/json that
        // way. Refusing everything else closes that route.
        $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') {
            self::error('Request body must be sent as Content-Type: application/json', 415);
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}
