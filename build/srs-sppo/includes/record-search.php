<?php

declare(strict_types=1);

function unified_record_search_reference(string $query): ?array
{
    $pattern = '/\G\s*(?:[,;\/]\s*)?(book|b|page|p|line|l)\s*[:#]?\s*([A-Za-z0-9]+)\s*/i';
    $references = [];
    $offset = 0;
    $length = strlen($query);

    while ($offset < $length) {
        if (preg_match($pattern, $query, $matches, 0, $offset) !== 1) {
            return null;
        }

        $label = strtolower($matches[1]);
        $value = $matches[2];
        $field = match ($label) {
            'book', 'b' => 'book_no',
            'page', 'p' => 'page_no',
            'line', 'l' => 'line_no',
        };
        $isBook = $field === 'book_no';
        $validValue = $isBook
            ? preg_match('/^(?:\d+|[IVXLCDM]+)$/i', $value) === 1
            : preg_match('/^\d+$/', $value) === 1;

        if (!$validValue || isset($references[$field])) {
            return null;
        }

        $references[$field] = $value;
        $offset += strlen($matches[0]);
    }

    return $references ?: null;
}

function unified_record_search_book_values(string $book): array
{
    $values = [$book];

    if (preg_match('/^\d+$/', $book) === 1) {
        $number = (int) $book;
        $values[] = (string) $number;
        $roman = register_roman_numeral($number);
        if ($roman !== '') {
            $values[] = $roman;
        }
    } else {
        $number = register_roman_to_int($book);
        if ($number !== null && register_roman_numeral($number) === strtoupper($book)) {
            $values[] = (string) $number;
            $values[] = register_roman_numeral($number);
        }
    }

    return array_values(array_unique($values));
}
