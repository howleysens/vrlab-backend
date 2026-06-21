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

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS class_teachers (
                class_id INTEGER NOT NULL REFERENCES classes(id),
                teacher_id INTEGER NOT NULL REFERENCES users(id),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (class_id, teacher_id)
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

        $this->db->exec("
            INSERT OR IGNORE INTO class_teachers (class_id, teacher_id)
            SELECT id, teacher_id
            FROM classes
            WHERE teacher_id IS NOT NULL
        ");
    }

    public function getClasses(?int $teacherId = null): void
    {
        if ($teacherId !== null) {
            $stmt = $this->db->prepare('
                SELECT c.id,
                       c.name,
                       c.subject,
                       GROUP_CONCAT(DISTINCT ct.teacher_id) AS teacher_ids,
                       GROUP_CONCAT(DISTINCT u.name) AS teacher_names
                FROM classes c
                LEFT JOIN class_teachers ct ON ct.class_id = c.id
                LEFT JOIN users u ON u.id = ct.teacher_id
                WHERE EXISTS (
                    SELECT 1
                    FROM class_teachers filter_ct
                    WHERE filter_ct.class_id = c.id
                      AND filter_ct.teacher_id = :tid
                )
                GROUP BY c.id, c.name, c.subject
                ORDER BY c.name
            ');
            $stmt->execute([':tid' => $teacherId]);
        } else {
            $stmt = $this->db->query('
                SELECT c.id,
                       c.name,
                       c.subject,
                       GROUP_CONCAT(DISTINCT ct.teacher_id) AS teacher_ids,
                       GROUP_CONCAT(DISTINCT u.name) AS teacher_names
                FROM classes c
                LEFT JOIN class_teachers ct ON ct.class_id = c.id
                LEFT JOIN users u ON u.id = ct.teacher_id
                GROUP BY c.id, c.name, c.subject
                ORDER BY c.name
            ');
        }

        $classes = $stmt->fetchAll();

        $result = array_map(function ($row) {
            $teacherIds = $this->parseTeacherIds($row['teacher_ids'] ?? null);
            $teacherNames = $this->parseTeacherNames($row['teacher_names'] ?? null);

            return [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'subject' => $row['subject'],
                'teacherId' => $teacherIds[0] ?? null,
                'teacherName' => $teacherNames[0] ?? '',
                'teacherIds' => $teacherIds,
                'teacherNames' => $teacherNames,
            ];
        }, $classes);

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
                'students' => [],
                'error'   => ['isError' => true, 'errorText' => 'Class not found'],
            ]);
        }

        $stmt = $this->db->prepare(
            "SELECT id, name, age, login, password, role FROM users
             WHERE class_id = :cid AND role = 'student'
             ORDER BY name"
        );
        $stmt->execute([':cid' => $classId]);
        $students = $stmt->fetchAll();

        $labsService = new StudentLabsService($this->db);

        $result = array_map(function ($student) use ($labsService) {
            [$avgMark] = $labsService->getLabsSummary((int)$student['id']);
            return [
                'id'           => (int)$student['id'],
                'role'         => in_array(($student['role'] ?? null), ['student', 'teacher'], true) ? $student['role'] : 'student',
                'allTime'      => '',
                'timeFirstLab' => '',
                'login'        => $student['login'] ?? '',
                'password'     => $student['password'] ?? '',
                'name'         => $student['name'] ?? '',
                'age'          => (int)($student['age'] ?? 0),
                'avgMark'      => $avgMark,
                'labs'         => [],
            ];
        }, $students);

        JsonSerialize::jsonResponse([
            'students' => $result,
            'error'    => [
                'isError' => false,
                'errorText' => 'Success',
            ],
        ]);
    }

    private function parseTeacherIds(?string $teacherIds): array
    {
        if ($teacherIds === null || $teacherIds === '') {
            return [];
        }

        return array_values(array_map('intval', array_filter(explode(',', $teacherIds), static fn($value) => $value !== '')));
    }

    private function parseTeacherNames(?string $teacherNames): array
    {
        if ($teacherNames === null || $teacherNames === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $teacherNames), static fn($value) => $value !== ''));
    }
}
