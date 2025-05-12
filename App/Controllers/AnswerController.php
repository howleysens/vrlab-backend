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

        return (float) $value;
    }

    private function validateAnswer($answerKey, $userAnswer)
    {
        if (!isset($this->answers['answers'][$answerKey])) {
            return false;
        }

        $answerConfig = $this->answers['answers'][$answerKey];
        $userValue = $this->normalizeNumber($userAnswer);

        if ($userValue === null) {
            return false;
        }

        switch ($answerConfig['type']) {
            case 'numeric':
                $diff = abs($userValue - $answerConfig['correctAnswer']);
                $diff = round($diff, 5);
                return $diff <= $answerConfig['limit'];
            
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

        $userAnswers = array_filter($_POST, function($value, $key) {
            return strpos($key, 'Answer') === 0 && $value !== '';
        }, ARRAY_FILTER_USE_BOTH);

        $totalAnswers = count($this->answers['answers']);
        $correctCount = 0;
        
        foreach ($this->answers['answers'] as $answerKey => $answerConfig) {
            if (isset($userAnswers[$answerKey])) {
                if ($this->validateAnswer($answerKey, $userAnswers[$answerKey])) {
                    $correctCount++;
                }
            }
        }

        return [
            'correct' => $correctCount,
            'total' => $totalAnswers,
            'percentage' => $totalAnswers > 0 ? round(($correctCount / $totalAnswers) * 100) : 0
        ];
    }
}
