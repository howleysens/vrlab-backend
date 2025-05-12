<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

use App\Application;

require_once './vendor/autoload.php';

$request = file_get_contents('php://input');
parse_str($request, $requestArray);
$action = $requestArray['type'];

$application = new Application($action);
$response = $application->handleAction($requestArray);

header('Content-Type: application/json');
echo json_encode($response);