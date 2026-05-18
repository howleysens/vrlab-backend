<?php

namespace App\Controllers;

use App\Facades\Database;
use App\Facades\JsonSerialize;
use App\Services\StudentLabsService;
use PDO;

class ClassController
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
            CREATE TABLE IF NOT EXISTS classes (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT    NOT NULL,
                subject TEXT NOT NULL,
                teacher_id INTEGER REFERENCES users(id),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $columns = $this->db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $hasClassId = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'class_id') {
                $hasClassId = true;
                break;
            }
        }
        if (!$hasClassId) {
            $this->db->exec("ALTER TABLE users ADD COLUMN class_id INTEGER REFERENCES classes(id)");
        }
    }

    public function getClasses(?int $teacherId = null): void
    {
        if ($teacherId !== null) {
            $stmt = $this->db->prepare('
                SELECT c.id, c.name, c.subject,
                       u.id   AS teacher_id,
                       u.name AS teacher_name
                FROM classes c
                LEFT JOIN users u ON c.teacher_id = u.id
                WHERE c.teacher_id = :tid
                ORDER BY c.name
            ');
            $stmt->execute([':tid' => $teacherId]);
        } else {
            $stmt = $this->db->query('
                SELECT c.id, c.name, c.subject,
                       u.id   AS teacher_id,
                       u.name AS teacher_name
                FROM classes c
                LEFT JOIN users u ON c.teacher_id = u.id
                ORDER BY c.name
            ');
        }

        $classes = $stmt->fetchAll();

        $result = array_map(fn($row) => [
            'id'          => (int)$row['id'],
            'name'        => $row['name'],
            'subject'     => $row['subject'],
            'teacherId'   => $row['teacher_id'] !== null ? (int)$row['teacher_id'] : null,
            'teacherName' => $row['teacher_name'] ?? '',
        ], $classes);

        JsonSerialize::jsonResponse([
            'success' => true,
            'classes' => $result,
        ]);
    }

    public function getClassStudents(int $classId): void
    {
        $stmt = $this->db->prepare('SELECT id, name, subject FROM classes WHERE id = :id');
        $stmt->execute([':id' => $classId]);
        $class = $stmt->fetch();

        if (!$class) {
            JsonSerialize::jsonResponse([
                'success' => false,
                'error'   => ['isError' => true, 'errorText' => 'Class not found'],
            ], 404);
        }

        $stmt = $this->db->prepare(
            "SELECT id, name, age, login FROM users
             WHERE class_id = :cid AND role = 'student'
             ORDER BY name"
        );
        $stmt->execute([':cid' => $classId]);
        $students = $stmt->fetchAll();

        $labsService = new StudentLabsService($this->db);

        $result = array_map(function ($student) use ($labsService) {
            [$avgMark] = $labsService->getLabsSummary((int)$student['id']);
            return [
                'id'      => (int)$student['id'],
                'name'    => $student['name'],
                'age'     => (int)$student['age'],
                'login'   => $student['login'],
                'avgMark' => $avgMark,
            ];
        }, $students);

        JsonSerialize::jsonResponse([
            'success'   => true,
            'classId'   => (int)$class['id'],
            'className' => $class['name'],
            'subject'   => $class['subject'],
            'students'  => $result,
        ]);
    }
}
