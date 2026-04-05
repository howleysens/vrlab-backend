<?php

namespace App\Controllers;

use App\Facades\Database;
use App\Services\StudentLabsService;

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
		$db = Database::getInstance();
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
			[$overallAvg, $labs] = (new StudentLabsService($db))->getLabsSummary($this->id);

			return json_encode([
				'students' => [
					'id' => $this->id,
					'name' => $userData['name'],
					'login' => $userData['login'],
					'password' => $userData['password'],
					'age' => $userData['age'],
					'role' => in_array(($userData['role'] ?? null), ['student', 'teacher'], true) ? $userData['role'] : 'student',
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
