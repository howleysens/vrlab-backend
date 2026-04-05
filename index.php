<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

use App\Application;
use App\Services\RequestLogger;

require_once './vendor/autoload.php';

$request = file_get_contents('php://input') ?: '';
$logger = new RequestLogger();

ob_start();

register_shutdown_function(static function () use ($logger, $request): void {
    $responseBody = ob_get_level() > 0 ? (string)ob_get_contents() : '';
    $statusCode = http_response_code() ?: 200;
    $lastError = error_get_last();

    $logger->log($request, $responseBody, [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'statusCode' => $statusCode,
        'error' => $lastError ? ($lastError['message'] ?? 'Unknown error') : null,
    ]);

    if (ob_get_level() > 0) {
        ob_end_flush();
    }
});

parse_str($request, $requestArray);
$action = $requestArray['type'] ?? '';

$application = new Application($action);
$response = $application->handleAction($requestArray);

header('Content-Type: application/json');

if ($response !== null) {
    echo is_string($response)
        ? $response
        : json_encode($response, JSON_UNESCAPED_UNICODE);
}
