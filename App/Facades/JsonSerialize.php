<?php
namespace App\Facades;
class JsonSerialize
{
    public static function jsonResponse(array $data, int $statusCode = 200): string
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}