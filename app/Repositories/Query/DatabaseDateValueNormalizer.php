<?php

class DatabaseDateValueNormalizer
{
    private const INTEGER_TYPES = ['int', 'bigint', 'smallint', 'tinyint'];

    /** Preserve the existing JSON Query behavior for integer-backed BETWEEN values. */
    public static function normalizeLegacyValue($value, $dataType)
    {
        if (!self::isIntegerType($dataType)
            || !is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $value;
        }

        return (int)str_replace('-', '', $value);
    }

    /** Validate a semantic date and adapt it only when SQL Server stores it as an integer. */
    public static function normalizeSemanticValue($value, $dataType)
    {
        if ($value === null) {
            return null;
        }

        $date = is_int($value) ? (string)$value : $value;
        if (!is_string($date)) {
            throw new InvalidArgumentException('Expected a valid YYYY-MM-DD or YYYYMMDD date.');
        }

        $isoDate = $date;
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $parts) === 1) {
            $isoDate = $parts[1] . '-' . $parts[2] . '-' . $parts[3];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $parts) !== 1
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new InvalidArgumentException('Expected a valid YYYY-MM-DD or YYYYMMDD date.');
        }

        return self::isIntegerType($dataType)
            ? (int)($parts[1] . $parts[2] . $parts[3])
            : $isoDate;
    }

    private static function isIntegerType($dataType): bool
    {
        return in_array(strtolower((string)$dataType), self::INTEGER_TYPES, true);
    }
}
