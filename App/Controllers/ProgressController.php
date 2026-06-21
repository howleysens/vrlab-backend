<?php

namespace App\Controllers;

use App\Facades\JsonSerialize;

class ProgressController
{
    public function __construct(
        private string $studentId
    )
    {
    }

    private function getStudentId(): string
    {
        return $this->studentId;
    }

    public function getStudentProgress()
    {
        try {
            $db = new \SQLite3('App/database/database.sqlite');

            $getLabTables = "SELECT name FROM sqlite_master WHERE type='table' and name LIKE 'lab_%'";
            $result = $db->query($getLabTables);

            $tables = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $tables[] = $row['name'];
            }

            $progress = [];

            foreach ($tables as $table) {
                $query = "SELECT * FROM " . $db->escapeString($table) . " WHERE student_id = :studentId";
                $stmt = $db->prepare($query);

                $stmt->bindValue(':studentId', $this->studentId);

                $result = $stmt->execute();

                $tableData = [];
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $tableData[] = $row;
                }

                $progress[] = [
                    'labId' => $table,
                    'answers' => $tableData
                ];
            }

            $db->close();

            return JsonSerialize::jsonResponse([
                'success' => true,
                'data' => $progress
            ]);
        } catch (\Exception $e) {
            return JsonSerialize::jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}
