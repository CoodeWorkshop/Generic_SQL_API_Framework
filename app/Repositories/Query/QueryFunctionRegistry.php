<?php

final class QueryFunctionRegistry
{
    private const FUNCTIONS = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'STRING_AGG',
        'UPPER', 'LOWER', 'LTRIM', 'RTRIM', 'TRIM', 'LEN', 'COALESCE',
        'ISNULL', 'CAST', 'CONVERT', 'NULLIF', 'CONCAT', 'LEFT', 'RIGHT',
        'SUBSTRING', 'REPLACE', 'CHARINDEX', 'PATINDEX', 'FORMAT', 'CHOOSE',
        'YEAR', 'MONTH', 'DAY', 'DATEPART', 'DATENAME', 'GETDATE', 'DATEADD',
        'DATEDIFF', 'EOMONTH', 'ISDATE', 'DATEFROMPARTS',
        'DATETIMEFROMPARTS', 'TIMEFROMPARTS', 'SYSDATETIME',
        'CURRENT_TIMESTAMP', 'IIF', 'ABS', 'ROUND', 'CEILING', 'FLOOR',
        'POWER', 'SQRT', 'EXP', 'LOG', 'ROW_NUMBER', 'RANK', 'DENSE_RANK',
        'NTILE', 'LAG', 'LEAD', 'FIRST_VALUE', 'LAST_VALUE',
    ];

    private const AGGREGATES = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'STRING_AGG',
    ];

    private const WINDOWS = [
        'ROW_NUMBER', 'RANK', 'DENSE_RANK', 'NTILE', 'LAG', 'LEAD',
        'FIRST_VALUE', 'LAST_VALUE',
    ];

    /** Functions whose existing `field` property may contain an ExpressionNode. */
    private const EXPRESSION_INPUT = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'STRING_AGG',
        'UPPER', 'LOWER', 'LTRIM', 'RTRIM', 'TRIM', 'LEN', 'ISNULL',
        'CAST', 'CONVERT', 'NULLIF', 'LEFT', 'RIGHT', 'SUBSTRING',
        'REPLACE', 'CHARINDEX', 'PATINDEX', 'FORMAT', 'YEAR', 'MONTH',
        'DAY', 'DATEPART', 'DATENAME', 'DATEADD', 'ISDATE', 'ABS', 'ROUND',
        'CEILING', 'FLOOR', 'POWER', 'SQRT', 'EXP', 'LOG', 'LAG', 'LEAD',
        'FIRST_VALUE', 'LAST_VALUE',
    ];

    public static function all(): array
    {
        return self::FUNCTIONS;
    }

    public static function supports(string $name): bool
    {
        return in_array(strtoupper($name), self::FUNCTIONS, true);
    }

    public static function isAggregate(string $name): bool
    {
        return in_array(strtoupper($name), self::AGGREGATES, true);
    }

    public static function isWindow(string $name): bool
    {
        return in_array(strtoupper($name), self::WINDOWS, true);
    }

    public static function acceptsExpressionInput(string $name): bool
    {
        return in_array(strtoupper($name), self::EXPRESSION_INPUT, true);
    }

    public static function allowedProperties(string $name): array
    {
        $name = strtoupper($name);
        $fieldOnly = [
            'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'UPPER', 'LOWER', 'LTRIM',
            'RTRIM', 'TRIM', 'LEN', 'YEAR', 'MONTH', 'DAY', 'ABS', 'CEILING',
            'FLOOR', 'SQRT', 'EXP', 'LOG',
        ];
        if (in_array($name, $fieldOnly, true)) {
            return ['function', 'field'];
        }
        return match ($name) {
            'STRING_AGG' => ['function', 'field', 'separator', 'sort'],
            'COALESCE' => ['function', 'fields', 'default'],
            'ISNULL' => ['function', 'field', 'default'],
            'CAST' => ['function', 'field', 'datatype'],
            'CONVERT' => ['function', 'field', 'datatype', 'style'],
            'NULLIF' => ['function', 'field', 'value'],
            'CONCAT' => ['function', 'fields'],
            'LEFT', 'RIGHT' => ['function', 'field', 'length'],
            'SUBSTRING' => ['function', 'field', 'start', 'length'],
            'REPLACE' => ['function', 'field', 'search', 'replace'],
            'CHARINDEX' => ['function', 'field', 'search'],
            'PATINDEX' => ['function', 'field', 'pattern'],
            'FORMAT' => ['function', 'field', 'format', 'style'],
            'CHOOSE' => ['function', 'index', 'values'],
            'DATEPART', 'DATENAME' => ['function', 'part', 'field'],
            'GETDATE', 'SYSDATETIME', 'CURRENT_TIMESTAMP' => ['function'],
            'DATEADD' => ['function', 'datepart', 'number', 'field', 'style'],
            'DATEDIFF' => ['function', 'datepart', 'start', 'end'],
            'EOMONTH' => ['function', 'start', 'month'],
            'ISDATE' => ['function', 'field', 'style'],
            'DATEFROMPARTS' => ['function', 'year', 'month', 'day'],
            'DATETIMEFROMPARTS' => [
                'function', 'year', 'month', 'day', 'hour', 'minute', 'second',
                'millisecond',
            ],
            // Retains the current declared surface; its pre-existing incomplete
            // public validation remains outside the recursive-expression work.
            'TIMEFROMPARTS' => ['function', 'hour', 'minute', 'second', 'precision'],
            'IIF' => ['function', 'condition', 'true', 'false'],
            'ROUND' => ['function', 'field', 'precision'],
            'POWER' => ['function', 'field', 'power'],
            'ROW_NUMBER', 'RANK', 'DENSE_RANK' => ['function', 'sort', 'partitionBy'],
            'NTILE' => ['function', 'buckets', 'sort', 'partitionBy'],
            'LAG', 'LEAD' => ['function', 'field', 'offset', 'default', 'sort', 'partitionBy'],
            'FIRST_VALUE', 'LAST_VALUE' => ['function', 'field', 'sort', 'partitionBy'],
            default => ['function'],
        };
    }

    public static function isRecursivelyRenderable(string $name): bool
    {
        return in_array(strtoupper($name), [
            'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'STRING_AGG',
            'UPPER', 'LOWER', 'LTRIM', 'RTRIM', 'TRIM', 'LEN', 'ISNULL',
            'CAST', 'CONVERT', 'NULLIF', 'LEFT', 'RIGHT', 'SUBSTRING',
            'REPLACE', 'CHARINDEX', 'PATINDEX', 'FORMAT', 'YEAR', 'MONTH',
            'DAY', 'DATEPART', 'DATENAME', 'GETDATE', 'DATEADD', 'ISDATE',
            'SYSDATETIME', 'CURRENT_TIMESTAMP', 'ABS', 'ROUND', 'CEILING',
            'FLOOR', 'POWER', 'SQRT', 'EXP', 'LOG', 'ROW_NUMBER', 'RANK',
            'DENSE_RANK', 'NTILE', 'LAG', 'LEAD', 'FIRST_VALUE', 'LAST_VALUE',
        ], true);
    }
}
