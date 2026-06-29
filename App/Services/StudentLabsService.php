<?php

namespace App\Services;

use PDO;

class StudentLabsService
{
	private array $labConfigs = [];

	public function __construct(
		private PDO $db
	)
	{
		$this->labConfigs = $this->loadLabConfigs();
	}

	public function getLabsSummary(int $userId): array
	{
		$totalLabAvgMark = 0.0;
		$countLabsWithResults = 0;
		$labs = [];

		$tablesStmt = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'lab_%'");
		$tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];

		foreach ($tables as $tableName) {
			if (!preg_match('/^lab_(\d+)$/', $tableName, $matches)) {
				continue;
			}

			$labId = (int)$matches[1];
			$row = $this->getLatestLabRow($tableName, $userId);
			if (!$row) {
				continue;
			}

			$labAvgMark = $this->resolveLabScore($row, $labId);
			$totalLabAvgMark += $labAvgMark;
			$countLabsWithResults++;

			$labs[] = [
				'labId' => $labId,
				'name' => $this->getLabName($labId),
				'methodReadSeconds' => $this->getDurationSeconds($row, ['methodReadSeconds', 'method_read_seconds']),
				'videoWatchSeconds' => $this->getDurationSeconds($row, ['videoWatchSeconds', 'video_watch_seconds']),
				'labAvgMark' => $labAvgMark,
				'questions' => $this->buildQuestions($row, $labId),
				'averageMark' => $labAvgMark,
				'correctAnswers' => (int)($row['correct_answers'] ?? 0),
				'totalQuestions' => (int)($row['total_questions'] ?? 0),
				'timestamp' => $row['timestamp'] ?? null,
			];
		}

		$overallAvg = $countLabsWithResults > 0
			? round($totalLabAvgMark / $countLabsWithResults, 2)
			: 0.0;

		return [$overallAvg, $labs];
	}

	private function getLatestLabRow(string $tableName, int $userId): ?array
	{
		$stmt = $this->db->prepare("SELECT * FROM {$tableName} WHERE student_id = :sid ORDER BY timestamp DESC, id DESC LIMIT 1");
		$stmt->execute([':sid' => (string)$userId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	private function getLabName(int $labId): string
	{
		return $this->labConfigs[$labId]['name'] ?? ('Lab ' . $labId);
	}

	private function buildQuestions(array $row, int $labId): array
	{
		$questions = [];
		$answerConfigs = $this->labConfigs[$labId]['answers'] ?? [];

		if (is_array($answerConfigs) && $answerConfigs !== []) {
			foreach ($answerConfigs as $answerKey => $answerConfig) {
				$questions[] = [
					'questionNumber' => $this->extractQuestionNumber($answerKey, count($questions) + 1),
					'answer' => $this->stringifyAnswer($row[$answerKey] ?? ''),
					'scorePercent' => $this->calculateScorePercent($answerKey, $answerConfig, $row),
				];
			}

			return $questions;
		}

		$answerColumns = [];
		foreach ($row as $column => $value) {
			if (preg_match('/^Answer(\d+)$/', $column, $matches)) {
				$answerColumns[(int)$matches[1]] = $column;
			}
		}

		ksort($answerColumns);

		foreach ($answerColumns as $questionNumber => $column) {
			$questions[] = [
				'questionNumber' => $questionNumber,
				'answer' => $this->stringifyAnswer($row[$column] ?? ''),
				'scorePercent' => 0,
			];
		}

		return $questions;
	}

	private function calculateScorePercent(string $answerKey, array $answerConfig, array $row): int
	{
		$userAnswer = $row[$answerKey] ?? null;
		if ($userAnswer === null || $userAnswer === '') {
			return 0;
		}

		return $this->isAnswerCorrect($answerConfig, (string)$userAnswer, $row) ? 100 : 0;
	}

	private function resolveLabScore(array $row, int $labId): float
	{
		if (isset($row['score']) && is_numeric($row['score'])) {
			return (float)$row['score'];
		}

		$totalQuestions = (int)($row['total_questions'] ?? 0);
		$correctAnswers = (int)($row['correct_answers'] ?? 0);
		$gradingConfig = $this->labConfigs[$labId]['grading'] ?? [];
		$maxScore = (int)($gradingConfig['maxScore'] ?? 3);

		if ($totalQuestions <= 0 || $correctAnswers <= 0 || $maxScore <= 0) {
			return 0.0;
		}

		$ratio = $correctAnswers / $totalQuestions;
		$thresholds = $gradingConfig['thresholds'] ?? [
			['score' => 3, 'minRatio' => 1.0],
			['score' => 2, 'minRatio' => 0.67],
			['score' => 1, 'minRatio' => 0.34],
		];

		usort($thresholds, static function (array $left, array $right): int {
			return (int)($right['score'] ?? 0) <=> (int)($left['score'] ?? 0);
		});

		foreach ($thresholds as $threshold) {
			$score = (int)($threshold['score'] ?? 0);
			$minRatio = (float)($threshold['minRatio'] ?? 0);
			if ($score > $maxScore) {
				continue;
			}

			if ($ratio >= $minRatio) {
				return (float)$score;
			}
		}

		return 0.0;
	}

	private function isAnswerCorrect(array $answerConfig, string $userAnswer, array $row): bool
	{
		$userValue = $this->normalizeNumber($userAnswer);

		if ($userValue === null) {
			return false;
		}

		switch ($answerConfig['type'] ?? null) {
			case 'numeric':
				$diff = abs($userValue - (float)$answerConfig['correctAnswer']);
				return round($diff, 5) <= (float)$answerConfig['limit'];

			case 'multiple_numeric':
				if (!isset($answerConfig['correctAnswers']) || !is_array($answerConfig['correctAnswers'])) {
					return false;
				}

				foreach ($answerConfig['correctAnswers'] as $correctAnswer) {
					$diff = abs($userValue - (float)($correctAnswer['value'] ?? 0));
					if (round($diff, 5) <= (float)($correctAnswer['limit'] ?? 0)) {
						return true;
					}
				}

				return false;

			case 'exact':
				return $userValue == (float)$answerConfig['correctAnswer'];

			case 'comparison':
				return match ($answerConfig['operator'] ?? null) {
					'>' => $userValue > (float)$answerConfig['value'],
					'<' => $userValue < (float)$answerConfig['value'],
					'>=' => $userValue >= (float)$answerConfig['value'],
					'<=' => $userValue <= (float)$answerConfig['value'],
					default => false,
				};

			case 'range':
				return $userValue >= (float)$answerConfig['min'] && $userValue <= (float)$answerConfig['max'];

			case 'dependent':
				$dependsOnKey = $answerConfig['dependsOn'] ?? null;
				if (!$dependsOnKey || !array_key_exists($dependsOnKey, $row)) {
					return false;
				}

				$dependentValue = $this->normalizeNumber($row[$dependsOnKey]);
				if ($dependentValue === null) {
					return false;
				}

				$expectedValue = match ($answerConfig['operation'] ?? null) {
					'add' => $dependentValue + (float)$answerConfig['value'],
					'subtract' => $dependentValue - (float)$answerConfig['value'],
					'multiply' => $dependentValue * (float)$answerConfig['value'],
					'divide' => (float)$answerConfig['value'] === 0.0 ? null : $dependentValue / (float)$answerConfig['value'],
					default => null,
				};

				if ($expectedValue === null) {
					return false;
				}

				$diff = abs($userValue - $expectedValue);
				return round($diff, 5) <= (float)$answerConfig['limit'];

			default:
				return false;
		}
	}

	private function normalizeNumber(mixed $value): ?float
	{
		if ($value === null) {
			return null;
		}

		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		$value = str_replace(',', '.', $value);
		$value = str_replace(' ', '', $value);
		$value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);

		if (!is_numeric($value)) {
			return null;
		}

		return (float)$value;
	}

	private function extractQuestionNumber(string $answerKey, int $fallback): int
	{
		if (preg_match('/(\d+)/', $answerKey, $matches)) {
			return (int)$matches[1];
		}

		return $fallback;
	}

	private function stringifyAnswer(mixed $answer): string
	{
		if ($answer === null) {
			return '';
		}

		return (string)$answer;
	}

	private function getDurationSeconds(array $row, array $fieldNames): int
	{
		foreach ($fieldNames as $fieldName) {
			if (isset($row[$fieldName]) && is_numeric($row[$fieldName])) {
				return (int)$row[$fieldName];
			}
		}

		return 0;
	}

	private function loadLabConfigs(): array
	{
		$configPath = __DIR__ . '/../config/answers.json';
		if (!file_exists($configPath)) {
			return [];
		}

		$config = json_decode(file_get_contents($configPath), true);
		if (!is_array($config)) {
			return [];
		}

		$labConfigs = [];
		foreach ($config as $labConfig) {
			$labId = $labConfig['id'] ?? null;
			if ($labId === null) {
				continue;
			}

			$labConfigs[(int)$labId] = $labConfig;
		}

		return $labConfigs;
	}
}
