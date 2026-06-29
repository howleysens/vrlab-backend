<?php

namespace App;

use App\Controllers\AnswerController;
use App\Controllers\AuthController;
use App\Controllers\ClassController;
use App\Controllers\ProgressController;
use App\Controllers\StatisticsController;

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
            case 'login':
            case 'logging':
                $authController = new AuthController();
                $response = $authController->auth((string)($request['login'] ?? ''), (string)($request['password'] ?? ''));
                break;
            case 'setAnswer':
                $answerController = new AnswerController();
                $response = $answerController->validateAnswers($request);
                break;
            case 'getUserStatistic':
                $statisticController = new StatisticsController((int)($request['id'] ?? 0));
                $response = $statisticController->getUserStatistic();
                break;
            case 'getProgress':
                $progressController = new ProgressController((string)($request['id'] ?? ''));
                $response = $progressController->getStudentProgress();
                break;
            case 'getClasses':
                $classController = new ClassController();
                $teacherId = isset($request['teacher_id']) ? (int)$request['teacher_id'] : null;
                $classController->getClasses($teacherId);
                break;
            case 'getClassStudents':
                $classController = new ClassController();
                $classController->getClassStudents((int)($request['class_id'] ?? 0));
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
