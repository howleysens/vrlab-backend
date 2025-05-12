<?php

namespace App;

use App\Controllers\AnswerController;
use App\Controllers\AuthController;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class Application
{
    public function __construct(
        private string $action
    )
    {
    }

    public function handleAction(array $request)
    {
        switch ($this->action) {
            case 'logging':
                $authController = new AuthController();
                $response = $authController->auth($request['login'], $request['password']);
                break;
            case 'setAnswer':
                $answerController = new AnswerController();
                $response = $answerController->validateAnswers($_REQUEST);
                break;
            default:
                $response = [
                    'success' => false,
                    'message' => 'Неизвестное действие'
                ];
        }

        return $response;
    }
}