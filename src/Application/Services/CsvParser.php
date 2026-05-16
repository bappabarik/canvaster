<?php
declare(strict_types=1);

namespace App\Application\Services;

use RuntimeException;

class CsvParser
{
    public function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException('CSV file not found');
        }

        $file = new \SplFileObject($filePath, 'r');
        $file->setFlags(
            \SplFileObject::READ_CSV |
            \SplFileObject::SKIP_EMPTY |
            \SplFileObject::DROP_NEW_LINE
        );

        // First row = headers
        $headers = $file->current();

        // Guard: current() can return false if file is empty
        if (!is_array($headers) || count($headers) === 0) {
            throw new RuntimeException('CSV file is empty or has no headers');
        }

        // Trim BOM and whitespace from headers
        $headers = array_map(fn($h) => trim(ltrim((string) $h, "\xEF\xBB\xBF")), $headers);

        // Remove any empty header columns (trailing commas in header row)
        $headers = array_values(array_filter($headers, fn($h) => $h !== ''));

        if (count($headers) === 0) {
            throw new RuntimeException('CSV header row has no valid column names');
        }

        if (count(array_unique($headers)) !== count($headers)) {
            throw new RuntimeException('CSV has duplicate column headers');
        }

        $file->next();

        $rows        = [];
        $rowIndex    = 0;
        $headerCount = count($headers);

        while (!$file->eof()) {
            $line = $file->current();
            $file->next();

            // SKIP_EMPTY can still yield false, null, or [null] — guard all cases
            if (!is_array($line) || $line === [null]) {
                continue;
            }

            // Skip rows that are entirely empty strings
            $hasContent = array_filter($line, fn($cell) => trim((string) $cell) !== '');
            if (count($hasContent) === 0) {
                continue;
            }

            // Normalize to header column count — array_pad is now safe because
            // we've confirmed $line is a real array above
            $line = array_slice(
                array_pad($line, $headerCount, ''),
                0,
                $headerCount
            );

            $rows[] = [
                'row_index' => $rowIndex,
                'data'      => array_combine($headers, $line),
            ];
            $rowIndex++;
        }

        if ($rowIndex === 0) {
            throw new RuntimeException('CSV has headers but no data rows');
        }

        return [
            'headers' => $headers,
            'rows'    => $rows,
            'total'   => $rowIndex,
        ];
    }
}