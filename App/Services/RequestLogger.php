<?php

namespace App\Services;

class RequestLogger
{
	private string $logFilePath;

	public function __construct(?string $logFilePath = null)
	{
		$this->logFilePath = $logFilePath ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'log.txt';
	}

	public function log(string $requestBody, string $responseBody, array $context = []): void
	{
		$timestamp = date('Y-m-d H:i:s');
		$method = $context['method'] ?? 'UNKNOWN';
		$uri = $context['uri'] ?? '';
		$statusCode = $context['statusCode'] ?? 200;
		$error = $context['error'] ?? null;

		$entry = [
			'Timestamp: ' . $timestamp,
			'Method: ' . $method,
			'URI: ' . $uri,
			'StatusCode: ' . $statusCode,
			'RequestBody: ' . $requestBody,
			'ResponseBody: ' . $responseBody,
		];

		if ($error) {
			$entry[] = 'Error: ' . $error;
		}

		$entry[] = str_repeat('-', 80);

		file_put_contents(
			$this->logFilePath,
			implode(PHP_EOL, $entry) . PHP_EOL,
			FILE_APPEND
		);
	}
}
