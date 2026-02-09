<?php

declare(strict_types=1);

/**
 * Encoding conversion helpers for UTF-8 <-> CP1251.
 *
 * The peoplesru database uses cp1251 (Windows Cyrillic), while the browser/API
 * uses UTF-8. All data must be converted when reading from or writing to the DB.
 */

/**
 * Convert a UTF-8 string to CP1251 for database storage.
 *
 * @param string|null $str UTF-8 encoded string
 * @return string|null CP1251 encoded string, or null if input is null
 */
function toDb(string|null $str): string|null
{
    if ($str === null || $str === '') {
        return $str;
    }

    $result = @iconv('UTF-8', 'CP1251//TRANSLIT', $str);
    if ($result === false) {
        // Fallback: try without TRANSLIT to avoid complete failure
        $result = @iconv('UTF-8', 'CP1251//IGNORE', $str);
        if ($result === false) {
            return $str;
        }
    }

    return $result;
}

/**
 * Convert a CP1251 string from the database to UTF-8 for browser output.
 *
 * @param string|null $str CP1251 encoded string
 * @return string|null UTF-8 encoded string, or null if input is null
 */
function fromDb(string|null $str): string|null
{
    if ($str === null || $str === '') {
        return $str;
    }

    $result = @iconv('CP1251', 'UTF-8//IGNORE', $str);
    if ($result === false) {
        return $str;
    }

    return $result;
}

/**
 * Convert all string values in an associative array from UTF-8 to CP1251.
 *
 * Non-string values are left unchanged.
 *
 * @param array $arr Associative array with UTF-8 string values
 * @return array Array with CP1251 string values
 */
function toDbArray(array $arr): array
{
    $result = [];
    foreach ($arr as $key => $value) {
        if (is_string($value)) {
            $result[$key] = toDb($value);
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}

/**
 * Convert all string values in an associative array from CP1251 to UTF-8.
 *
 * Non-string values are left unchanged.
 *
 * @param array $arr Associative array with CP1251 string values
 * @return array Array with UTF-8 string values
 */
function fromDbArray(array $arr): array
{
    $result = [];
    foreach ($arr as $key => $value) {
        if (is_string($value)) {
            $result[$key] = fromDb($value);
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}

/**
 * Convert an array of database rows from CP1251 to UTF-8.
 *
 * Each row is processed through fromDbArray().
 *
 * @param array $rows Array of associative arrays (database rows)
 * @return array Array of rows with UTF-8 string values
 */
function fromDbRows(array $rows): array
{
    return array_map('fromDbArray', $rows);
}
