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
		$totalPercentage = 0.0;
		$countLabsWithResults = 0;
		$labs = [];

		// Получаем список таблиц lab_*
		$tablesStmt = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'lab_%'");
		$tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];

		// Читаем конфигурацию лабораторных для названий
		$labNames = [];
		$configPath = __DIR__ . '/../config/answers.json';
		if (file_exists($configPath)) {
			$config = json_decode(file_get_contents($configPath), true);
			if (is_array($config)) {
				foreach ($config as $labCfg) {
					$labIdCfg = $labCfg['id'] ?? null;
					if ($labIdCfg !== null) {
						$name = $labCfg['name'] ?? ('Lab ' . $labIdCfg);
						$labNames[(int)$labIdCfg] = $name;
					}
				}
			}
		}

		foreach ($tables as $tableName) {
			if (strpos($tableName, 'lab_') !== 0) {
				continue;
			}
			$labId = (int)substr($tableName, 4);

			$lastStmt = $this->db->prepare("SELECT percentage, total_questions, correct_answers, timestamp FROM {$tableName} WHERE student_id = :sid ORDER BY timestamp DESC LIMIT 1");
			$lastStmt->execute([':sid' => (string)$userId]);
			$row = $lastStmt->fetch(PDO::FETCH_ASSOC);
			if (!$row) {
				continue;
			}

			$percentage = (float)$row['percentage'];
			$totalPercentage += $percentage;
			$countLabsWithResults++;

			$labs[] = [
				'labId' => $labId,
				'name' => $labNames[$labId] ?? ('Lab ' . $labId),
				'averageMark' => $percentage,
				'correctAnswers' => (int)$row['correct_answers'],
				'totalQuestions' => (int)$row['total_questions'],
				'timestamp' => $row['timestamp'],
			];
		}

		$overallAvg = $countLabsWithResults > 0 ? round($totalPercentage / $countLabsWithResults, 2) : 0.0;
		return [$overallAvg, $labs];
	}

	private function jsonResponse(array $data, int $statusCode = 200): void
	{
		header('Content-Type: application/json');
		http_response_code($statusCode);
		echo json_encode($data, JSON_UNESCAPED_UNICODE);
		exit;
	}
}
