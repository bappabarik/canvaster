<?php
declare(strict_types=1);

namespace App\Application\Services;

class PlaceholderExtractor
{
    /**
     * Scan a canvas JSON string for {placeholder} tokens.
     * Returns a deduplicated list e.g. ["name", "photo", "roll_no"]
     */
    public function extract(string $canvasJson): array
    {
        preg_match_all(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            $canvasJson,
            $matches
        );

        // Deduplicate and re-index
        return array_values(array_unique($matches[1]));
    }
}