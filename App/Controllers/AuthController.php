<?php
namespace App\Controllers;

use App\Facades\Database;
use PDO;
use Exception;

class AuthController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->initDatabase();
    }

    private function initDatabase(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                login TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                name TEXT NOT NULL,
                age INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    public function auth(string $login, string $password): void
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM users WHERE login = :login');
            $stmt->execute(['login' => $login]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Неверный логин или пароль',
                    'error' => [
                        'isError' => true,
                        'errorText' => 'User'
                    ]
                ]);
                return;
            }

            if (!($password === $user['password'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Неверный логин или пароль',
                    'error' => [
                        'isError' => true,
                        'errorText' => 'Password'
                    ]
                ]);
                return;
            }
            $avgMark = $this->getAvgMark($user['id']);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Успешная аутентификация',
                'students' => [
                    'id' => $user['id'],
                    'login' => $user['login'],
                    'password' => $user['password'],
                    'name' => $user['name'],
                    'age' => $user['age']
                ],
                'error' => [
                    'isError' => false,
                    'errorText' => 'Success'
                ]
            ]);
        } catch (Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Ошибка сервера',
                'error' => [
                    'isError' => true,
                    'errorText' => 'Server'
                ]
            ], 500);
        }
    }

    private function getAvgMark($userId): string
    {
        return '';
    }

    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
