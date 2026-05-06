<?php
declare(strict_types=1);

namespace App\Application\Services;

use RuntimeException;

class CsvParser
{
    /**
     * Parse a CSV file into rows keyed by header.
     * Handles large files via SplFileObject streaming — never loads full file into memory.
     *
     * Returns:
     * [
     *   'headers' => ['Name', 'Roll No', 'Photo'],
     *   'rows'    => [
     *     ['row_index' => 0, 'data' => ['Name' => 'John', 'Roll No' => '101', 'Photo' => 'john.jpg']],
     *     ...
     *   ],
     *   'total'   => 250,
     * ]
     */
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
        if (!$headers || count($headers) === 0) {
            throw new RuntimeException('CSV file is empty or has no headers');
        }

        // Trim BOM and whitespace from headers
        $headers = array_map(fn($h) => trim(ltrim($h, "\xEF\xBB\xBF")), $headers);

        if (count(array_unique($headers)) !== count($headers)) {
            throw new RuntimeException('CSV has duplicate column headers');
        }

        $file->next();

        $rows      = [];
        $rowIndex  = 0;
        $headerCount = count($headers);

        while (!$file->eof()) {
            $line = $file->current();
            $file->next();

            // Skip completely blank lines
            if ($line === null || $line === [null]) continue;

            // Pad or trim to match header count
            $line = array_slice(array_pad($line, $headerCount, ''), 0, $headerCount);

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