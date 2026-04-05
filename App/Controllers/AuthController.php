<?php
namespace App\Controllers;

use App\Facades\Database;
use App\Services\StudentLabsService;
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

		$this->ensureRoleColumn();
	}

	private function ensureRoleColumn(): void
	{
		$columns = $this->db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
		$hasRoleColumn = false;

		foreach ($columns as $column) {
			if (($column['name'] ?? '') === 'role') {
				$hasRoleColumn = true;
				break;
			}
		}

		if (!$hasRoleColumn) {
			$this->db->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'student'");
		}

		$this->db->exec("UPDATE users SET role = 'student' WHERE role IS NULL OR role NOT IN ('student', 'teacher')");
	}

	private function normalizeRole(?string $role): string
	{
		return in_array($role, ['student', 'teacher'], true) ? $role : 'student';
	}

	public function auth(string $login, string $password): string
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
			}

			// Собираем список лабораторных работ и общий средний балл
			[$overallAvg, $labs] = $this->getLabsSummary((int)$user['id']);

			$this->jsonResponse([
				'success' => true,
				'message' => 'Успешная аутентификация',
				'students' => [
					'id' => $user['id'],
					'login' => $user['login'],
					'password' => $user['password'],
					'name' => $user['name'],
					'age' => $user['age'],
					'role' => $this->normalizeRole($user['role'] ?? null),
					'avgMark' => $overallAvg,
					'labs' => $labs,
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
		try {
			[$overallAvg] = $this->getLabsSummary((int)$userId);
			return (string)$overallAvg;
		} catch (Exception $e) {
			return '0';
		}
	}

	/**
	 * Возвращает [общая_средняя_оценка, список_лабораторных]
	 */
	private function getLabsSummary(int $userId): array
	{
		return (new StudentLabsService($this->db))->getLabsSummary($userId);
	}

	private function jsonResponse(array $data, int $statusCode = 200): void
	{
		header('Content-Type: application/json');
		http_response_code($statusCode);
		echo json_encode($data, JSON_UNESCAPED_UNICODE);
		exit;
	}
}
