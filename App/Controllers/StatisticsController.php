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
        $stmt->execute([':id' => 1]);
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
            return json_encode([
                'students' => [
                    'id' => $this->id,
                    'name' => $userData['name'],
                    'login' => $userData['login'],
                    'password' => $userData['password'],
                    'age' => $userData['age'],
                    'avgMark' => 5,
                ],
                'error' => [
                    'isError' => false,
                    'errorText' => '',
                ]
            ]);
        }
    }
}