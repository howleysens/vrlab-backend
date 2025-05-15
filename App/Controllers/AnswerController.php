<?php

namespace App\Controllers;

class AnswerController
{
    private $answers;

    public function __construct()
    {
        $this->loadAnswers();
    }

    private function loadAnswers()
    {
        $jsonPath = __DIR__ . '/../config/answers.json';
        if (file_exists($jsonPath)) {
            $jsonContent = file_get_contents($jsonPath);
            $this->answers = json_decode($jsonContent, true);
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
                if (!isset($_POST[$answerConfig['dependsOn']])) {
                    return false;
                }
                
                $dependentValue = $this->normalizeNumber($_POST[$answerConfig['dependsOn']]);
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

    public function validateAnswers()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return [
                'error' => 'Method not allowed',
                'correct' => 0,
                'total' => 0,
                'percentage' => 0
            ];
        }

        // Поддержка как lab_id, так и labNumber
        $labId = $_POST['lab_id'] ?? $_POST['labNumber'] ?? 1;
        $currentLab = $this->getCurrentLab($labId);

        if (!$currentLab) {
            return [
                'error' => 'Laboratory work not found',
                'correct' => 0,
                'total' => 0,
                'percentage' => 0
            ];
        }

        $userAnswers = array_filter($_POST, function ($value, $key) {
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
            // Создаем таблицу, если она не существует
            $this->createLabTable($tableName, $currentLab);

            // Записываем ответы ученика в таблицу
            $this->saveStudentAnswers($tableName, $userAnswers, $currentLab);
        } catch (\Exception $e) {
            // Логируем ошибку, но продолжаем выполнение
            error_log("Error creating/saving lab data: " . $e->getMessage());
        }

        return [
            'correct' => $correctCount,
            'total' => $totalAnswers,
            'percentage' => $totalAnswers > 0 ? round(($correctCount / $totalAnswers) * 100) : 0];
    }

    private function createLabTable($tableName, $currentLab)
    {
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
                percentage REAL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            )";
            $db->exec($query);
            $db->close();
        } catch (\Exception $e) {
            error_log("Error creating table $tableName: " . $e->getMessage());
            throw $e;
        }
    }

    private function saveStudentAnswers($tableName, $userAnswers, $currentLab)
    {
        $db = new \SQLite3('App/database/database.sqlite');
        $studentId = $_POST['id'] ?? 'unknown';
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

        // Подсчет общего количества вопросов и правильных ответов
        $totalQuestions = count($currentLab['answers']);
        $correctAnswers = 0;
        foreach ($currentLab['answers'] as $answerKey => $answerConfig) {
            if (isset($userAnswers[$answerKey]) && $this->validateAnswer($answerKey, $userAnswers[$answerKey], $currentLab['id'])) {
                $correctAnswers++;
            }
        }
        $percentage = $totalQuestions > 0 ? round(($correctAnswers / $totalQuestions) * 100, 2) : 0;

        $query = "INSERT INTO $tableName (student_id, $columnsStr, total_questions, correct_answers, percentage) 
                  VALUES ('$studentId', $valuesStr, $totalQuestions, $correctAnswers, $percentage)";
        $db->exec($query);
        $db->close();
    }
}
