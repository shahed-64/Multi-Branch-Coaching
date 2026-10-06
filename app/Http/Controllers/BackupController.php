<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class BackupController extends Controller
{
    public function takeBackup()
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        try {
            $databaseName = config('database.connections.mysql.database');

            $tables = DB::select('SHOW TABLES');

            $tableKey = 'Tables_in_' . $databaseName;

            $sqlScript = "";

            foreach ($tables as $table) {

                $tableName = $table->$tableKey;

                // =========================
                // CREATE TABLE
                // =========================
                $createTableQuery = DB::select(
                    "SHOW CREATE TABLE `$tableName`"
                );

                $sqlScript .= "\n\n";
                $sqlScript .= $createTableQuery[0]->{'Create Table'};
                $sqlScript .= ";\n\n";

                // =========================
                // TABLE DATA
                // =========================
                $rows = DB::table($tableName)->get();

                foreach ($rows as $row) {

                    $rowArray = (array) $row;

                    $columns = array_keys($rowArray);

                    $values = array_values($rowArray);

                    $escapedValues = array_map(function ($value) {

                        if (is_null($value)) {
                            return 'NULL';
                        }

                        return "'" . addslashes($value) . "'";

                    }, $values);

                    $sqlScript .= "INSERT INTO `$tableName` (`"
                        . implode('`, `', $columns)
                        . "`) VALUES ("
                        . implode(', ', $escapedValues)
                        . ");\n";
                }
            }

            // =========================
            // BACKUP FILE NAME
            // =========================
            $fileName = 'backup-' . date('Y-m-d-H-i-s') . '.sql';

            // =========================
            // BACKUP DIRECTORY
            // =========================
            $directory = storage_path('app/backups');

            if (!file_exists($directory)) {
                mkdir($directory, 0777, true);
            }

            // =========================
            // FILE PATH
            // =========================
            $filePath = $directory . DIRECTORY_SEPARATOR . $fileName;

            file_put_contents($filePath, $sqlScript);

            // =========================
            // DOWNLOAD BACKUP
            // =========================
            return response()->download(
                $filePath,
                $fileName,
                [
                    'Content-Type' => 'application/sql',
                ]
            )->deleteFileAfterSend(false);

        } catch (Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}
