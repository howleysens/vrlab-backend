<?php

namespace App\Controllers;
class StatisticsController
{
	public function __construct(
		public int $id,
	)
	{
		$this->id = $id;
	}

	public function getUserStatistic()
	{
		$db = \App\Facades\Database::getInstance();
		$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
		$stmt->execute([':id' => $this->id]);
		$userData = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (!$userData) {
			return json_encode([
				'students' => [
				],
				'error' => [
					'isError' => true,
					'errorText' => 'User not found',
				]
			]);
		} else {
			$labs = [];
			$totalPercentage = 0;
			$countLabsWithResults = 0;

			$tablesStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'lab_%'");
			$tables = $tablesStmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

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

				$lastStmt = $db->prepare("SELECT percentage, total_questions, correct_answers, timestamp FROM {$tableName} WHERE student_id = :sid ORDER BY timestamp DESC LIMIT 1");
				$lastStmt->execute([':sid' => (string)$this->id]);
				$row = $lastStmt->fetch(\PDO::FETCH_ASSOC);
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

			$overallAvg = $countLabsWithResults > 0 ? round($totalPercentage / $countLabsWithResults, 2) : 0;

			return json_encode([
				'students' => [
					'id' => $this->id,
					'name' => $userData['name'],
					'login' => $userData['login'],
					'password' => $userData['password'],
					'age' => $userData['age'],
					'avgMark' => $overallAvg,
					'labs' => $labs,
				],
				'error' => [
					'isError' => false,
					'errorText' => '',
				]
			]);
		}
	}
}