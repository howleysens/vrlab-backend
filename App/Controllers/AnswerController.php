<?php

namespace App\Controllers;

class AnswerController
{
    private array $answers = [];
    private array $requestData = [];

    public function __construct()
    {
        $this->loadAnswers();
    }

    private function loadAnswers(): void
    {
        $jsonPath = __DIR__ . '/../config/answers.json';
        if (file_exists($jsonPath)) {
            $jsonContent = file_get_contents($jsonPath);
            $decoded = json_decode($jsonContent, true);
            $this->answers = is_array($decoded) ? $decoded : [];
        }
    }

    private function getCurrentLab($labId)
    {
        if (!is_array($this->answers)) {
            return null;
        }

        foreach ($this->answers as $lab) {
            if ($lab['id'] == $labId) {
                return $lab;
            }
        }
        return null;
    }

    private function normalizeNumber($value)
    {
        $value = trim($value);

        if (empty($value)) {
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

    private function validateAnswer($answerKey, $userAnswer, $labId)
    {
        $currentLab = $this->getCurrentLab($labId);
        if (!$currentLab || !isset($currentLab['answers'][$answerKey])) {
            return false;
        }

        $answerConfig = $currentLab['answers'][$answerKey];
        $userValue = $this->normalizeNumber($userAnswer);

        if ($userValue === null) {
            return false;
        }

        switch ($answerConfig['type']) {
            case 'numeric':
                $diff = abs($userValue - $answerConfig['correctAnswer']);
                $diff = round($diff, 5);
                return $diff <= $answerConfig['limit'];
            
            case 'multiple_numeric':
                if (!isset($answerConfig['correctAnswers']) || !is_array($answerConfig['correctAnswers'])) {
                    return false;
                }
                foreach ($answerConfig['correctAnswers'] as $correctAnswer) {
                    $diff = abs($userValue - $correctAnswer['value']);
                    $diff = round($diff, 5);
                    if ($diff <= $correctAnswer['limit']) {
                        return true;
                    }
                }
                return false;
            
            case 'exact':
                return $userValue == $answerConfig['correctAnswer'];
            
            case 'comparison':
                switch ($answerConfig['operator']) {
                    case '>':
                        return $userValue > $answerConfig['value'];
                    case '<':
                        return $userValue < $answerConfig['value'];
                    case '>=':
                        return $userValue >= $answerConfig['value'];
                    case '<=':
                        return $userValue <= $answerConfig['value'];
                    default:
                        return false;
                }
            
            case 'range':
                return $userValue >= $answerConfig['min'] && $userValue <= $answerConfig['max'];
            
            case 'dependent':
                if (!isset($this->requestData[$answerConfig['dependsOn']])) {
                    return false;
                }

                $dependentValue = $this->normalizeNumber($this->requestData[$answerConfig['dependsOn']]);
                if ($dependentValue === null) {
                    return false;
                }
                
                switch ($answerConfig['operation']) {
                    case 'add':
                        $diff = abs($userValue - ($dependentValue + $answerConfig['value']));
                        $diff = round($diff, 5);
                        return $diff <= $answerConfig['limit'];
                    case 'subtract':
                        $diff = abs($userValue - ($dependentValue - $answerConfig['value']));
                        $diff = round($diff, 5);
                        return $diff <= $answerConfig['limit'];
                    case 'multiply':
                        $diff = abs($userValue - ($dependentValue * $answerConfig['value']));
                        $diff = round($diff, 5);
                        return $diff <= $answerConfig['limit'];
                    case 'divide':
                        if ($answerConfig['value'] == 0) {
                            return false;
                        }
                        $diff = abs($userValue - ($dependentValue / $answerConfig['value']));
                        $diff = round($diff, 5);
                        return $diff <= $answerConfig['limit'];
                    default:
                        return false;
                }
            
            default:
                return false;
        }
    }

    public function validateAnswers(array $requestData = []): array
    {
        $this->requestData = $requestData;

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return [
                'error' => 'Method not allowed',
                'correct' => 0,
                'total' => 0,
                'score' => 0,
                'maxScore' => 3,
                'percentage' => 0
            ];
        }

        $labId = $requestData['lab_id'] ?? $requestData['labNumber'] ?? 1;
        $currentLab = $this->getCurrentLab($labId);

        if (!$currentLab) {
            return [
                'error' => 'Laboratory work not found',
                'correct' => 0,
                'total' => 0,
                'score' => 0,
                'maxScore' => 3,
                'percentage' => 0
            ];
        }

        $userAnswers = array_filter($requestData, function ($value, $key) {
            return strpos($key, 'Answer') === 0 && $value !== '';
        }, ARRAY_FILTER_USE_BOTH);

        $totalAnswers = count($currentLab['answers']);
        $correctCount = 0;

        foreach ($currentLab['answers'] as $answerKey => $answerConfig) {
            if (isset($userAnswers[$answerKey])) {
                if ($this->validateAnswer($answerKey, $userAnswers[$answerKey], $labId)) {
                    $correctCount++;
                }
            }
        }

        $tableName = 'lab_' . $labId;

        try {
            $this->createLabTable($tableName, $currentLab);
            $this->saveStudentAnswers($tableName, $userAnswers, $currentLab, $requestData);
        } catch (\Exception $e) {
            error_log("Error creating/saving lab data: " . $e->getMessage());
        }

        $score = $this->calculateLabScore($correctCount, $totalAnswers, $currentLab);
        $percentage = $totalAnswers > 0 ? round(($correctCount / $totalAnswers) * 100) : 0;

        return [
            'correct' => $correctCount,
            'total' => $totalAnswers,
            'score' => $score,
            'maxScore' => (int)(($currentLab['grading']['maxScore'] ?? 3)),
            'percentage' => $percentage,
        ];
    }

    private function createLabTable($tableName, $currentLab): void
    {
        $db = null;

        try {
            $db = new \SQLite3('App/database/database.sqlite');
            $columns = [];
            foreach ($currentLab['answers'] as $answerKey => $answerConfig) {
                $columns[] = "$answerKey TEXT";
            }

            $columnsStr = implode(', ', $columns);
            $query = "CREATE TABLE IF NOT EXISTS $tableName (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id TEXT,
                $columnsStr,
                total_questions INTEGER,
                correct_answers INTEGER,
                score INTEGER DEFAULT 0,
                percentage REAL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            )";
            $db->exec($query);

            $tableInfo = $db->query("PRAGMA table_info($tableName)");
            $hasScoreColumn = false;

            while ($tableInfo !== false && ($column = $tableInfo->fetchArray(SQLITE3_ASSOC))) {
                if (($column['name'] ?? '') === 'score') {
                    $hasScoreColumn = true;
                    break;
                }
            }

            if (!$hasScoreColumn) {
                $db->exec("ALTER TABLE $tableName ADD COLUMN score INTEGER DEFAULT 0");
            }
        } catch (\Exception $e) {
            error_log("Error creating table $tableName: " . $e->getMessage());
            throw $e;
        } finally {
            if ($db instanceof \SQLite3) {
                $db->close();
            }
        }
    }

    private function saveStudentAnswers($tableName, $userAnswers, $currentLab, array $requestData): void
    {
        $db = new \SQLite3('App/database/database.sqlite');
        $studentId = $requestData['id'] ?? 'unknown';
        $columns = [];
        $values = [];
        foreach ($currentLab['answers'] as $answerKey => $answerConfig) {
            $columns[] = $answerKey;
            $value = isset($userAnswers[$answerKey]) ? $userAnswers[$answerKey] : '';
            $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);
            $values[] = "'$value'";
        }
        $columnsStr = implode(', ', $columns);
        $valuesStr = implode(', ', $values);

        $totalQuestions = count($currentLab['answers']);
        $correctAnswers = 0;
        foreach ($currentLab['answers'] as $answerKey => $answerConfig) {
            if (isset($userAnswers[$answerKey]) && $this->validateAnswer($answerKey, $userAnswers[$answerKey], $currentLab['id'])) {
                $correctAnswers++;
            }
        }

        $score = $this->calculateLabScore($correctAnswers, $totalQuestions, $currentLab);
        $percentage = $totalQuestions > 0 ? round(($correctAnswers / $totalQuestions) * 100, 2) : 0;

        $query = "INSERT INTO $tableName (student_id, $columnsStr, total_questions, correct_answers, score, percentage)
                  VALUES ('$studentId', $valuesStr, $totalQuestions, $correctAnswers, $score, $percentage)";
        $db->exec($query);
        $db->close();
    }

    private function calculateLabScore(int $correctAnswers, int $totalQuestions, array $currentLab): int
    {
        $maxScore = (int)($currentLab['grading']['maxScore'] ?? 3);
        if ($totalQuestions <= 0 || $correctAnswers <= 0 || $maxScore <= 0) {
            return 0;
        }

        $ratio = $correctAnswers / $totalQuestions;
        $thresholds = $currentLab['grading']['thresholds'] ?? [
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
                return $score;
            }
        }

        return 0;
    }
}
