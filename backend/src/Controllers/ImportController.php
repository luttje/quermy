<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Drivers\DriverInterface;
use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\Json;
use Quermy\Http\Route;

final class ImportController extends BaseController
{
    public function __construct(
        private readonly ConnectionSessionInterface $session,
    ) {}

    #[Route('POST', '/api/import')]
    public function import(): void
    {
        $uploadError = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if (empty($_FILES['file']) || $uploadError !== UPLOAD_ERR_OK) {
            Json::error('File upload failed: ' . $this->uploadErrorMessage($uploadError), 400);
        }

        $database          = trim((string) ($_POST['database']          ?? ''));
        $table             = trim((string) ($_POST['table']             ?? ''));
        $hasHeader         = ($_POST['hasHeader']         ?? '0') === '1';
        $delimiter         = (string) ($_POST['delimiter']         ?? ',');
        $enclosure         = (string) ($_POST['enclosure']         ?? '"');
        $commentChar       = (string) ($_POST['commentChar']       ?? '');
        $duplicateHandling = (string) ($_POST['duplicateHandling'] ?? 'INSERT');
        $columnMappingJson = (string) ($_POST['columnMapping']     ?? '{}');

        if ($database === '' || $table === '') {
            Json::error('Database and table are required', 400);
        }
        $onConflict = match ($duplicateHandling) {
            'INSERT'        => DriverInterface::ON_CONFLICT_FAIL,
            'INSERT IGNORE' => DriverInterface::ON_CONFLICT_IGNORE,
            'REPLACE'       => DriverInterface::ON_CONFLICT_REPLACE,
            default         => null,
        };
        if ($onConflict === null) {
            Json::error('Invalid duplicate handling mode', 400);
        }
        if (strlen($delimiter) !== 1) {
            Json::error('Delimiter must be a single character', 400);
        }
        if (strlen($enclosure) > 1) {
            Json::error('Enclosure must be empty or a single character', 400);
        }

        $columnMapping = json_decode($columnMappingJson, true);
        if (!is_array($columnMapping)) {
            Json::error('Invalid column mapping', 400);
        }

        $columnMapping = array_filter($columnMapping, fn($v) => $v !== '' && $v !== null);
        if (empty($columnMapping)) {
            Json::error('At least one column must be mapped', 400);
        }

        $tmpPath = $_FILES['file']['tmp_name'];
        $driver  = $this->session->open();
        try {
            $handle = fopen($tmpPath, 'rb');
            if ($handle === false) {
                Json::error('Failed to read uploaded file', 500);
            }

            $headers  = null; // CSV header names once parsed from first row
            $imported = 0;
            $errors   = [];
            $dataRow  = 0; // data rows seen (after header)

            $effectiveEnclosure = $enclosure !== '' ? $enclosure : "\0";

            while (!feof($handle)) {
                $row = fgetcsv($handle, 0, $delimiter, $effectiveEnclosure);
                if ($row === false) {
                    break;
                }

                // Skip empty rows (fgetcsv returns [null] for blank lines)
                if (count($row) === 1 && $row[0] === null) {
                    continue;
                }

                // Skip comment rows
                if ($commentChar !== '' && isset($row[0]) && str_starts_with((string) $row[0], $commentChar)) {
                    continue;
                }

                // First content row is headers when hasHeader is true
                if ($hasHeader && $headers === null) {
                    $headers = array_map('strval', $row);
                    continue;
                }

                $dataRow++;
                $values = [];
                foreach ($columnMapping as $tableCol => $csvKey) {
                    if ($headers !== null) {
                        $csvIndex = array_search($csvKey, $headers, true);
                        $value    = $csvIndex !== false ? ($row[$csvIndex] ?? null) : null;
                    } else {
                        $csvIndex = (int) $csvKey;
                        $value    = $row[$csvIndex] ?? null;
                    }
                    $values[(string) $tableCol] = $value;
                }

                // The file's contents are untrusted. insertRow() sends every
                // value as a bound parameter, so nothing in a CSV cell can
                // change the statement that runs.
                try {
                    $driver->insertRow($database, $table, $values, $onConflict);
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = ['row' => $dataRow, 'error' => $e->getMessage()];
                    if (count($errors) >= 100) {
                        break;
                    }
                }
            }

            fclose($handle);

            Json::send(['imported' => $imported, 'errors' => $errors]);
        } finally {
            $driver->disconnect();
        }
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'File exceeds the maximum upload size',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form maximum upload size',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'Upload stopped by a PHP extension',
            default               => "Unknown error ({$code})",
        };
    }
}
