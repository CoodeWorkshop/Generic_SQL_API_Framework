<?php

require_once __DIR__ . '/SqlMappingException.php';
require_once __DIR__ . '/SqlBackendCapabilities.php';

class SqlToApiMapper
{
    private int $expressionDepth = 0;

    /**
     * Positional SQL signatures mapped to the heterogeneous public field-object
     * properties. Function admission comes from the backend function registry.
     */
    private const POSITIONAL_FUNCTION_SCHEMAS = [
        'COUNT' => [['field', 'field']],
        'SUM' => [['field', 'field']],
        'AVG' => [['field', 'field']],
        'MIN' => [['field', 'field']],
        'MAX' => [['field', 'field']],
        'UPPER' => [['field', 'field']],
        'LOWER' => [['field', 'field']],
        'LTRIM' => [['field', 'field']],
        'RTRIM' => [['field', 'field']],
        'TRIM' => [['field', 'field']],
        'LEN' => [['field', 'field']],
        'YEAR' => [['field', 'field']],
        'MONTH' => [['field', 'field']],
        'DAY' => [['field', 'field']],
        'ISDATE' => [['field', 'field']],
        'ABS' => [['field', 'field']],
        'CEILING' => [['field', 'field']],
        'FLOOR' => [['field', 'field']],
        'SQRT' => [['field', 'field']],
        'EXP' => [['field', 'field']],
        'LOG' => [['field', 'field']],
        'ROUND' => [['field', 'field'], ['precision', 'integer', true, 0]],
        'POWER' => [['field', 'field'], ['power', 'numeric']],
        'LEFT' => [['field', 'field'], ['length', 'integer']],
        'RIGHT' => [['field', 'field'], ['length', 'integer']],
        'SUBSTRING' => [['field', 'field'], ['start', 'integer'], ['length', 'integer']],
        'REPLACE' => [['field', 'field'], ['search', 'string'], ['replace', 'string']],
        'ISNULL' => [['field', 'field'], ['default', 'literal']],
        'NULLIF' => [['field', 'field'], ['value', 'literal']],
        'STRING_AGG' => [['field', 'field'], ['separator', 'string']],
    ];

    public function map(array $ast): array
    {
        if (($ast['type'] ?? null) === 'routine') {
            if (($ast['action'] ?? null) !== 'procedure') {
                throw new SqlMappingException('Unsupported routine AST action.');
            }
            return [
                'action' => 'procedure',
                'source' => ['procedure' => $ast['name']],
                'parameters' => array_map(fn (array $argument) => $this->literal($argument), $ast['arguments']),
            ];
        }
        if ($ast['type'] === 'with') {
            return $this->mapWith($ast);
        }
        if ($ast['type'] === 'set') {
            return $this->mapSet($ast);
        }
        return ['action' => 'select'] + $this->mapSelect($ast, true);
    }

    private function mapWith(array $ast): array
    {
        if (count($ast['ctes']) !== 1) {
            throw new SqlMappingException('The public with property accepts exactly one CTE definition.');
        }
        if ($ast['query']['type'] !== 'select') {
            throw new SqlMappingException('The public contract cannot attach with to a set-operation action.');
        }

        $cte = $ast['ctes'][0];
        if ($cte['columns'] !== []) {
            throw new SqlMappingException('CTE column-name lists cannot be represented by the public with property.');
        }

        $request = ['action' => 'select'] + $this->mapSelect($ast['query'], true);
        if ($cte['query']['type'] === 'select') {
            $request['with'] = [
                'name' => $cte['name'],
                'query' => $this->mapSelect($cte['query'], false),
            ];
            return $request;
        }

        $set = $cte['query'];
        if (count($set['queries']) !== 2
            || array_unique($set['operators']) !== ['UNION ALL']
            || !$this->referencesSource($set['queries'][1], $cte['name'])) {
            throw new SqlMappingException(
                'A CTE set body is representable only as two recursive branches joined by UNION ALL.'
            );
        }
        $request['with'] = [
            'name' => $cte['name'],
            'anchor' => $this->mapSelect($set['queries'][0], false),
            'recursive' => $this->mapSelect($set['queries'][1], false),
        ];
        return $request;
    }

    private function mapSet(array $ast): array
    {
        $operators = array_values(array_unique($ast['operators']));
        if (count($operators) !== 1) {
            throw new SqlMappingException('Mixed UNION and UNION ALL cannot be represented by one public action.');
        }
        return [
            'action' => $operators[0] === 'UNION ALL' ? 'unionAll' : 'union',
            'queries' => array_map(fn (array $query): array => $this->mapSelect($query, false), $ast['queries']),
        ];
    }

    private function mapSelect(array $ast, bool $topLevel): array
    {
        [$commaJoins, $where] = $this->normalizeCommaSources($ast);
        $request = [
            'source' => $this->mapSource($ast['source']),
            'fields' => array_map(fn (array $field) => $this->field($field), $ast['fields']),
        ];

        if ($ast['distinct']) {
            $request['distinct'] = true;
        }
        if ($ast['top'] !== null) {
            $request['limit'] = $ast['top'];
        }
        if (($ast['pagination'] ?? null) !== null) {
            if (!$topLevel) {
                throw new SqlMappingException('Nested SELECT and set-operation branches cannot contain OFFSET/FETCH.');
            }
            $offset = $ast['pagination']['offset'];
            $pageSize = $ast['pagination']['pageSize'];
            if ($offset % $pageSize !== 0) {
                throw new SqlMappingException(
                    'OFFSET must be an exact multiple of FETCH to map to page/pageSize pagination.'
                );
            }
            $request['pagination'] = ['page' => intdiv($offset, $pageSize) + 1, 'pageSize' => $pageSize];
        }

        $joins = array_merge(
            array_map(fn (array $join): array => $this->join($join), $ast['joins']),
            $commaJoins
        );
        if ($joins !== []) {
            $request['joins'] = $joins;
        }

        if ($where !== null) {
            [$predicates, $logic] = $this->flattenBoolean($where, 'WHERE');
            $request['filters'] = array_map(fn (array $predicate): array => $this->filter($predicate), $predicates);
            if ($logic !== null && count($predicates) > 1) {
                $request['filterLogic'] = $logic;
            }
        }

        if ($ast['group'] !== []) {
            $request['groupBy'] = array_map(function (array $expression) {
                $expression = $this->unwrapGroup($expression);
                return $expression['type'] === 'identifier'
                    ? $expression['name'] : $this->mapExpression($expression);
            }, $ast['group']);
        }

        if ($ast['having'] !== null) {
            [$predicates, $logic] = $this->flattenBoolean($ast['having'], 'HAVING');
            if ($logic === 'OR') {
                throw new SqlMappingException('HAVING OR cannot be represented by the public contract.');
            }
            $request['having'] = array_map(fn (array $predicate): array => $this->having($predicate), $predicates);
        }

        if ($ast['order'] !== []) {
            if (!$topLevel) {
                throw new SqlMappingException('Nested SELECT and set-operation branches cannot contain ORDER BY.');
            }
            $request['sort'] = array_map(fn (array $order): array => $this->mapSort($order), $ast['order']);
        }

        return $request;
    }

    private function mapSource(array $source): array
    {
        return array_filter(
            ['table' => $source['table'], 'alias' => $source['alias']],
            fn ($value): bool => $value !== null
        );
    }

    private function join(array $join): array
    {
        if (!in_array($join['type'], ['INNER', 'LEFT', 'RIGHT'], true)) {
            throw new SqlMappingException("{$join['type']} is not supported by the public join contract.");
        }
        [$predicates, $logic] = $this->flattenBoolean($join['on'], 'JOIN ON');
        if (count($predicates) !== 1 || $logic !== null) {
            throw new SqlMappingException('The public JOIN contract supports one equality predicate.');
        }
        $predicate = $predicates[0];
        $left = $this->unwrapGroup($predicate['left']);
        $right = $this->unwrapGroup($predicate['right'] ?? []);
        if ($predicate['operator'] !== '='
            || ($left['type'] ?? null) !== 'identifier'
            || ($right['type'] ?? null) !== 'identifier') {
            throw new SqlMappingException('The public JOIN contract requires direct fields joined with equality.');
        }
        return [
            'type' => $join['type'],
            'source' => $this->mapSource($join['source']),
            'on' => ['left' => $left['name'], 'operator' => '=', 'right' => $right['name']],
        ];
    }

    private function normalizeCommaSources(array $ast): array
    {
        if ($ast['commaSources'] === []) {
            return [[], $ast['where']];
        }
        if ($ast['where'] === null) {
            throw new SqlMappingException(
                'Comma-separated FROM sources need an equality predicate before they can map to public joins.'
            );
        }

        [$predicates, $logic] = $this->flattenBoolean($ast['where'], 'WHERE');
        if ($logic === 'OR') {
            throw new SqlMappingException('Comma-join predicates cannot be normalized safely through OR logic.');
        }

        $connected = $this->sourceNames($ast['source']);
        $joins = [];
        $used = [];
        foreach ($ast['commaSources'] as $source) {
            $sourceNames = $this->sourceNames($source);
            $candidates = [];
            foreach ($predicates as $index => $predicate) {
                if (isset($used[$index]) || $predicate['operator'] !== '=') {
                    continue;
                }
                $left = $this->unwrapGroup($predicate['left']);
                $right = $this->unwrapGroup($predicate['right'] ?? []);
                if (($left['type'] ?? null) !== 'identifier' || ($right['type'] ?? null) !== 'identifier') {
                    continue;
                }
                $leftQualifier = $this->qualifier($left['name']);
                $rightQualifier = $this->qualifier($right['name']);
                $connects = (
                    in_array($leftQualifier, $sourceNames, true)
                    && in_array($rightQualifier, $connected, true)
                ) || (
                    in_array($rightQualifier, $sourceNames, true)
                    && in_array($leftQualifier, $connected, true)
                );
                if ($connects) {
                    $candidates[$index] = [$left['name'], $right['name']];
                }
            }
            if (count($candidates) !== 1) {
                throw new SqlMappingException(
                    'A comma-separated source must have exactly one unambiguous AND equality predicate connecting it to an earlier source.'
                );
            }
            $index = array_key_first($candidates);
            [$left, $right] = $candidates[$index];
            $used[$index] = true;
            $joins[] = [
                'type' => 'INNER', 'source' => $this->mapSource($source),
                'on' => ['left' => $left, 'operator' => '=', 'right' => $right],
            ];
            $connected = array_values(array_unique(array_merge($connected, $sourceNames)));
        }

        $remaining = array_values(array_filter(
            $predicates,
            fn (array $_, int $index): bool => !isset($used[$index]),
            ARRAY_FILTER_USE_BOTH
        ));
        return [$joins, $this->combinePredicates($remaining, 'AND')];
    }

    private function sourceNames(array $source): array
    {
        return array_values(array_unique(array_filter([$source['alias'], $source['table']])));
    }

    private function qualifier(string $identifier): ?string
    {
        $parts = explode('.', $identifier);
        if (count($parts) < 2) {
            return null;
        }
        array_pop($parts);
        return implode('.', $parts);
    }

    private function combinePredicates(array $predicates, string $operator): ?array
    {
        if ($predicates === []) {
            return null;
        }
        $expression = array_shift($predicates);
        foreach ($predicates as $predicate) {
            $expression = [
                'type' => 'boolean', 'operator' => $operator,
                'left' => $expression, 'right' => $predicate,
            ];
        }
        return $expression;
    }

    private function flattenBoolean(array $node, string $context): array
    {
        $predicates = [];
        $operators = [];
        $walk = function (array $current) use (&$walk, &$predicates, &$operators): void {
            if ($current['type'] === 'boolean_group') {
                $walk($current['expression']);
                return;
            }
            if ($current['type'] === 'boolean') {
                $operators[] = $current['operator'];
                $walk($current['left']);
                $walk($current['right']);
                return;
            }
            $predicates[] = $current;
        };
        $walk($node);
        $logic = array_values(array_unique($operators));
        if (count($logic) > 1) {
            throw new SqlMappingException(
                "{$context} contains mixed AND/OR logic that the single public filterLogic property cannot preserve."
            );
        }
        return [$predicates, $logic[0] ?? null];
    }

    private function field(array $field)
    {
        $expression = $this->unwrapGroup($field['expression']);
        $alias = $field['alias'];
        if ($expression['type'] === 'identifier') {
            return $alias === null
                ? $expression['name']
                : ['field' => $expression['name'], 'alias' => $alias];
        }
        $mapped = $this->mapExpression($expression, false);
        if ($alias !== null) {
            $mapped['alias'] = $alias;
        }
        return $mapped;
    }

    /** Public expression nodes only; never the normalizer's private AST. */
    public function mapExpression(array $expression, bool $recursive = true): array
    {
        if (++$this->expressionDepth > 64) {
            --$this->expressionDepth;
            throw new SqlMappingException('SQL expression mapping exceeds the safety depth limit.');
        }
        try {
            return $this->mapExpressionTree($expression, $recursive);
        } finally {
            --$this->expressionDepth;
        }
    }

    private function mapExpressionTree(array $expression, bool $recursive): array
    {
        $expression = $this->unwrapGroup($expression);
        $children = match ($expression['type'] ?? null) {
            'binary' => ['left', 'right'],
            'unary' => ['expression'],
            'function' => ['arguments'],
            'case' => ['when'],
            'window' => ['function', 'partitionBy', 'order'],
            default => [],
        };
        foreach ($children as $child) {
            if (!is_array($expression[$child] ?? null)) {
                throw new SqlMappingException('Malformed recursive SQL AST expression.');
            }
        }
        switch ($expression['type'] ?? null) {
            case 'identifier':
                return ['field' => $expression['name']];
            case 'literal':
                return ['literal' => $this->literal($expression)];
            case 'unary':
                if (!in_array($expression['operator'] ?? null, ['+', '-'], true)) {
                    throw new SqlMappingException('Unsupported unary operator.');
                }
                return ['unary' => [
                    'operator' => $expression['operator'],
                    'operand' => $this->mapExpression($expression['expression']),
                ]];
            case 'binary':
                if (!in_array($expression['operator'] ?? null, ['+', '-', '*', '/', '%'], true)) {
                    throw new SqlMappingException('Unsupported arithmetic operator.');
                }
                $legacy = !$recursive && $this->isLegacyOperand($expression['left'])
                    && $this->isLegacyOperand($expression['right']);
                return ['expression' => [
                    'left' => $legacy ? $this->operand($expression['left']) : $this->mapExpression($expression['left']),
                    'operator' => $expression['operator'],
                    'right' => $legacy ? $this->operand($expression['right']) : $this->mapExpression($expression['right']),
                ]];
            case 'function':
                if ($recursive && !QueryFunctionRegistry::isRecursivelyRenderable($expression['name'])) {
                    throw new SqlMappingException("Function {$expression['name']} is not supported in a recursive expression.");
                }
                return $this->functionField($expression, $recursive);
            case 'case':
                return $this->caseField($expression, null, $recursive);
            case 'window':
                if ($recursive) {
                    throw new SqlMappingException('Window functions cannot be nested inside expressions.');
                }
                return $this->windowField($expression);
            default:
                throw new SqlMappingException('Unexpected AST node in a public expression.');
        }
    }

    private function isLegacyOperand(array $expression): bool
    {
        $expression = $this->unwrapGroup($expression);
        return $expression['type'] === 'identifier'
            || ($expression['type'] === 'literal'
                && (is_int($expression['value']) || is_float($expression['value'])));
    }

    public function mapSort(array $order): array
    {
        $expression = $this->unwrapGroup($order['expression']);
        if ($expression['type'] === 'literal' || ($expression['type'] === 'unary'
            && $this->unwrapGroup($expression['expression'])['type'] === 'literal')) {
            throw new SqlMappingException('Numeric positional / literal ORDER BY is unsupported; use a logical field or expression.');
        }
        return ($expression['type'] === 'identifier'
            ? ['field' => $expression['name']]
            : ['expression' => $this->mapExpression($expression)])
            + ['direction' => $order['direction']];
    }

    private function operand(array $expression)
    {
        $expression = $this->unwrapGroup($expression);
        if ($expression['type'] === 'identifier') {
            return $expression['name'];
        }
        return $this->literal($expression);
    }

    private function functionField(array $expression, bool $recursive = false): array
    {
        $name = $expression['name'];
        $arguments = $expression['arguments'];
        if (!SqlBackendCapabilities::supportsFunction($name)) {
            throw new SqlMappingException("Function {$name} is not supported by the public API.");
        }

        $mapped = ['function' => $name];
        if (isset(self::POSITIONAL_FUNCTION_SCHEMAS[$name])) {
            return $this->mapPositionalFunction(
                $name,
                $arguments,
                self::POSITIONAL_FUNCTION_SCHEMAS[$name],
                $recursive
            );
        }
        if (in_array($name, ['GETDATE', 'SYSDATETIME', 'CURRENT_TIMESTAMP'], true)) {
            if ($arguments !== []) {
                throw new SqlMappingException("{$name} does not accept arguments.");
            }
            return $mapped;
        }
        if ($name === 'CAST') {
            if (count($arguments) !== 2 || ($arguments[1]['type'] ?? null) !== 'datatype') {
                throw new SqlMappingException('CAST requires an expression and datatype.');
            }
            return $mapped + [
                'field' => $this->functionInput($arguments[0], 'CAST', $recursive),
                'datatype' => $arguments[1]['name'],
            ];
        }
        if ($name === 'CONCAT') {
            if (count($arguments) < 2) {
                throw new SqlMappingException('CONCAT requires at least two direct fields.');
            }
            return $mapped + [
                'fields' => array_map(fn (array $argument): string => $this->directField($argument, 'CONCAT'), $arguments),
            ];
        }
        if ($name === 'COALESCE') {
            if (count($arguments) < 2) {
                throw new SqlMappingException('COALESCE requires at least two arguments for valid SQL Server output.');
            }
            $default = null;
            if (($arguments[count($arguments) - 1]['type'] ?? null) === 'literal') {
                $default = array_pop($arguments)['value'];
                if ($default === null) {
                    throw new SqlMappingException(
                        'COALESCE with a NULL fallback cannot be preserved by the current backend default property.'
                    );
                }
            }
            if ($arguments === []) {
                throw new SqlMappingException('COALESCE requires at least one direct field.');
            }
            $mapped['fields'] = array_map(
                fn (array $argument): string => $this->directField($argument, 'COALESCE'),
                $arguments
            );
            if ($default !== null) {
                $mapped['default'] = $default;
            }
            return $mapped;
        }
        if (in_array($name, ['DATEPART', 'DATENAME'], true)) {
            if (count($arguments) !== 2) {
                throw new SqlMappingException("{$name} requires a date part and field.");
            }
            return $mapped + [
                'part' => strtoupper($this->directField($arguments[0], $name)),
                'field' => $this->functionInput($arguments[1], $name, $recursive),
            ];
        }
        if ($name === 'DATEADD') {
            if (count($arguments) !== 3) {
                throw new SqlMappingException('DATEADD requires date part, integer number, and field.');
            }
            return $mapped + [
                'datepart' => strtoupper($this->directField($arguments[0], 'DATEADD')),
                'number' => $this->integerLiteral($arguments[1], 'DATEADD number'),
                'field' => $this->functionInput($arguments[2], 'DATEADD', $recursive),
            ];
        }
        if (in_array($name, ['CHARINDEX', 'PATINDEX'], true)) {
            if (count($arguments) !== 2) {
                throw new SqlMappingException("{$name} requires a string and field.");
            }
            $key = $name === 'CHARINDEX' ? 'search' : 'pattern';
            return $mapped + [
                'field' => $this->functionInput($arguments[1], $name, $recursive),
                $key => $this->stringLiteral($arguments[0], $name),
            ];
        }
        if ($name === 'FORMAT') {
            if (count($arguments) < 2 || count($arguments) > 3) {
                throw new SqlMappingException('FORMAT requires field, format, and optional style.');
            }
            $mapped['field'] = $this->functionInput($arguments[0], 'FORMAT', $recursive);
            $mapped['format'] = $this->stringLiteral($arguments[1], 'FORMAT');
            if (isset($arguments[2])) {
                $mapped['style'] = $this->integerLiteral($arguments[2], 'FORMAT style');
            }
            return $mapped;
        }
        if ($name === 'CONVERT') {
            if (count($arguments) < 2 || count($arguments) > 3) {
                throw new SqlMappingException('CONVERT requires datatype, field, and optional style.');
            }
            if (($arguments[0]['type'] ?? null) !== 'datatype') {
                throw new SqlMappingException('CONVERT requires a validated datatype token.');
            }
            $mapped['datatype'] = $arguments[0]['name'];
            $mapped['field'] = $this->functionInput($arguments[1], 'CONVERT', $recursive);
            if (isset($arguments[2])) {
                $mapped['style'] = $this->integerLiteral($arguments[2], 'CONVERT style');
            }
            return $mapped;
        }
        if ($name === 'DATEDIFF') {
            if (count($arguments) !== 3) {
                throw new SqlMappingException('DATEDIFF requires datepart, start, and end.');
            }
            return $mapped + [
                'datepart' => strtoupper($this->directField($arguments[0], 'DATEDIFF')),
                'start' => $this->dateEndpoint($arguments[1], 'DATEDIFF start'),
                'end' => $this->dateEndpoint($arguments[2], 'DATEDIFF end'),
            ];
        }
        if ($name === 'EOMONTH') {
            if (count($arguments) < 1 || count($arguments) > 2) {
                throw new SqlMappingException('EOMONTH requires a start field and optional integer month offset.');
            }
            $mapped['start'] = $this->dateEndpoint($arguments[0], 'EOMONTH start');
            if (isset($arguments[1])) {
                $mapped['month'] = $this->integerLiteral($arguments[1], 'EOMONTH month');
            }
            return $mapped;
        }
        if ($name === 'DATEFROMPARTS') {
            return $this->mapPartsFunction($mapped, $arguments, ['year', 'month', 'day']);
        }
        if ($name === 'DATETIMEFROMPARTS') {
            return $this->mapPartsFunction(
                $mapped,
                $arguments,
                ['year', 'month', 'day', 'hour', 'minute', 'second', 'millisecond']
            );
        }
        if ($name === 'IIF') {
            if (count($arguments) !== 3 || ($arguments[0]['type'] ?? null) !== 'predicate') {
                throw new SqlMappingException('IIF requires one comparison and true/false expressions.');
            }
            return $mapped + [
                'condition' => $this->safeCondition($arguments[0]),
                'true' => $this->safeFunctionValue($arguments[1]),
                'false' => $this->safeFunctionValue($arguments[2]),
            ];
        }
        if ($name === 'CHOOSE') {
            if (count($arguments) < 3) {
                throw new SqlMappingException('CHOOSE requires a positive literal index and at least two values.');
            }
            return $mapped + [
                'index' => $this->integerLiteral(array_shift($arguments), 'CHOOSE index'),
                'values' => array_map(fn (array $argument) => $this->safeFunctionValue($argument), $arguments),
            ];
        }

        throw new SqlMappingException(
            "Function {$name} is public, but this SQL argument form does not yet have a safe reverse mapping."
        );
    }

    private function functionInput(array $expression, string $name, bool $recursive)
    {
        $expression = $this->unwrapGroup($expression);
        if ($recursive && $expression['type'] === 'identifier' && $expression['name'] === '*') {
            throw new SqlMappingException('Wildcard function inputs are not renderable inside recursive backend expressions; direct COUNT(*) remains supported.');
        }
        if ($expression['type'] === 'identifier' && !$recursive) {
            return $expression['name'];
        }
        if (!QueryFunctionRegistry::acceptsExpressionInput($name)) {
            throw new SqlMappingException("{$name} does not accept an expression input.");
        }
        return $this->mapExpression($expression);
    }

    private function mapPositionalFunction(string $name, array $arguments, array $schema, bool $recursive): array
    {
        $minimum = count(array_filter($schema, fn (array $item): bool => !($item[2] ?? false)));
        if (count($arguments) < $minimum || count($arguments) > count($schema)) {
            throw new SqlMappingException("{$name} has an argument count that does not match its public schema.");
        }

        $mapped = ['function' => $name];
        foreach ($schema as $index => $definition) {
            [$property, $kind] = $definition;
            if (!isset($arguments[$index])) {
                if (array_key_exists(3, $definition)) {
                    $mapped[$property] = $definition[3];
                }
                continue;
            }
            $context = "{$name} {$property}";
            $mapped[$property] = match ($kind) {
                'field' => $this->functionInput($arguments[$index], $name, $recursive),
                'integer' => $this->integerLiteral($arguments[$index], $context),
                'numeric' => $this->numericLiteral($arguments[$index], $context),
                'string' => $this->stringLiteral($arguments[$index], $context),
                'literal' => $this->literal($arguments[$index]),
                default => throw new LogicException("Unknown SQL function mapping kind: {$kind}"),
            };
        }
        return $mapped;
    }

    private function windowField(array $expression): array
    {
        $function = $expression['function'];
        $name = $function['name'];
        if (!QueryFunctionRegistry::isWindow($name)) {
            throw new SqlMappingException("{$name} OVER is not a supported public window function.");
        }
        if ($expression['order'] === []) {
            throw new SqlMappingException("{$name} OVER requires ORDER BY.");
        }
        $mapped = ['function' => $name, 'sort' => array_map(fn (array $order): array => $this->mapSort($order), $expression['order'])];
        if ($expression['partitionBy'] !== []) {
            $mapped['partitionBy'] = array_map(function (array $partition) {
                $partition = $this->unwrapGroup($partition);
                return $partition['type'] === 'identifier'
                    ? $partition['name'] : $this->mapExpression($partition);
            }, $expression['partitionBy']);
        }
        $arguments = $function['arguments'];
        if ($name === 'NTILE') {
            if (count($arguments) !== 1) {
                throw new SqlMappingException('NTILE requires one bucket count.');
            }
            $mapped['buckets'] = $this->integerLiteral($arguments[0], 'NTILE buckets');
        } elseif (in_array($name, ['LAG', 'LEAD'], true)) {
            if ($arguments === [] || count($arguments) > 3) {
                throw new SqlMappingException("{$name} requires field and optional offset/default.");
            }
            $mapped['field'] = $this->functionInput($arguments[0], $name, false);
            if (isset($arguments[1])) {
                $mapped['offset'] = $this->integerLiteral($arguments[1], "{$name} offset");
            }
            if (isset($arguments[2])) {
                $mapped['default'] = $this->literal($arguments[2]);
            }
        } elseif (in_array($name, ['FIRST_VALUE', 'LAST_VALUE'], true)) {
            if (count($arguments) !== 1) {
                throw new SqlMappingException("{$name} requires one field.");
            }
            $mapped['field'] = $this->functionInput($arguments[0], $name, false);
        } elseif ($arguments !== []) {
            throw new SqlMappingException("{$name} does not accept arguments.");
        }
        return $mapped;
    }

    private function caseField(array $expression, ?string $alias, bool $recursive = false): array
    {
        $when = [];
        foreach ($expression['when'] as $branch) {
            [$predicates, $logic] = $this->flattenBoolean($branch['condition'], 'CASE WHEN');
            if (count($predicates) !== 1 || $logic !== null) {
                throw new SqlMappingException('Public CASE branches require one direct comparison.');
            }
            $condition = $predicates[0];
            $left = $this->unwrapGroup($condition['left']);
            if (!isset($condition['right']) || array_is_list($condition['right'])) {
                throw new SqlMappingException('CASE conditions require one comparison with two expression operands.');
            }
            $legacy = !$recursive && $left['type'] === 'identifier'
                && $this->isLiteralExpression($condition['right']) && $this->isLiteralExpression($branch['then']);
            $when[] = [
                'condition' => $legacy ? [
                    'field' => $left['name'], 'operator' => $condition['operator'],
                    'value' => $this->literal($condition['right']),
                ] : [
                    'left' => $this->mapExpression($left), 'operator' => $condition['operator'],
                    'right' => $this->mapExpression($condition['right']),
                ],
                'then' => $legacy ? $this->literal($branch['then']) : $this->mapExpression($branch['then']),
            ];
        }
        $mapped = ['case' => ['when' => $when]];
        if ($expression['else'] !== null) {
            $mapped['case']['else'] = !$recursive && $this->isLiteralExpression($expression['else'])
                ? $this->literal($expression['else']) : $this->mapExpression($expression['else']);
        }
        if ($alias !== null) {
            $mapped['alias'] = $alias;
        }
        return $mapped;
    }

    private function filter(array $predicate): array
    {
        if (in_array($predicate['operator'], ['EXISTS', 'NOT EXISTS'], true)) {
            return [
                'operator' => $predicate['operator'],
                'query' => $this->mapSubquery($predicate['right'] ?? null),
            ];
        }
        $left = $this->unwrapGroup($predicate['left']);
        if ($left['type'] !== 'identifier') {
            throw new SqlMappingException('WHERE requires a direct field on the left side in the public contract.');
        }
        $result = ['field' => $left['name'], 'operator' => $predicate['operator']];
        if (!in_array($predicate['operator'], ['IS NULL', 'IS NOT NULL'], true)) {
            $right = $predicate['right'];
            if (($right['type'] ?? null) === 'subquery') {
                $result['query'] = $this->mapSubquery($right);
            } else {
                $result['value'] = is_array($right) && array_is_list($right)
                    ? array_map(fn (array $value) => $this->literal($value), $right)
                    : $this->literal($right);
            }
        }
        return $result;
    }

    private function mapSubquery($expression): array
    {
        if (!is_array($expression) || ($expression['type'] ?? null) !== 'subquery'
            || !is_array($expression['query'] ?? null)
            || ($expression['query']['type'] ?? null) !== 'select') {
            throw new SqlMappingException('Filter subquery must contain one SELECT body.');
        }
        return $this->mapSelect($expression['query'], false);
    }

    private function dateEndpoint(array $expression, string $context): array
    {
        $expression = $this->unwrapGroup($expression);
        if ($expression['type'] === 'identifier') {
            return ['field' => $expression['name']];
        }
        if ($expression['type'] === 'function'
            && $expression['name'] === 'GETDATE'
            && $expression['arguments'] === []) {
            return ['function' => 'GETDATE'];
        }
        throw new SqlMappingException("{$context} must be a field or GETDATE().");
    }

    private function mapPartsFunction(array $mapped, array $arguments, array $properties): array
    {
        if (count($arguments) !== count($properties)) {
            throw new SqlMappingException(
                $mapped['function'] . ' requires exactly ' . count($properties) . ' arguments.'
            );
        }
        foreach ($properties as $index => $property) {
            $mapped[$property] = $this->safeFunctionValue($arguments[$index]);
        }
        return $mapped;
    }

    private function safeCondition(array $predicate): array
    {
        while (($predicate['type'] ?? null) === 'boolean_group') {
            $predicate = $predicate['expression'];
        }
        if (($predicate['type'] ?? null) !== 'predicate'
            || !in_array($predicate['operator'] ?? null, ['=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'], true)) {
            throw new SqlMappingException('Conditional function requires one supported comparison.');
        }
        $right = $predicate['right'] ?? null;
        return [
            'left' => $this->safeFunctionValue($predicate['left']),
            'operator' => $predicate['operator'],
            'right' => is_array($right) && array_is_list($right)
                ? array_map(fn (array $item) => $this->safeFunctionValue($item), $right)
                : $this->safeFunctionValue($right),
        ];
    }

    private function safeFunctionValue(array $expression)
    {
        $expression = $this->unwrapGroup($expression);
        if ($expression['type'] === 'identifier') {
            return ['field' => $expression['name']];
        }
        if ($expression['type'] === 'literal' || $expression['type'] === 'unary') {
            return $this->literal($expression);
        }
        if ($expression['type'] === 'binary') {
            if (!in_array($expression['operator'], ['+', '-', '*', '/', '%'], true)) {
                throw new SqlMappingException('Unsupported conditional/constructor arithmetic operator.');
            }
            return ['expression' => [
                'left' => $this->safeFunctionValue($expression['left']),
                'operator' => $expression['operator'],
                'right' => $this->safeFunctionValue($expression['right']),
            ]];
        }
        throw new SqlMappingException('This function argument cannot be represented by its public named property.');
    }

    public function having(array $predicate): array
    {
        $left = $this->unwrapGroup($predicate['left']);
        if ($left['type'] !== 'function'
            || !QueryFunctionRegistry::isAggregate($left['name'])
            || count($left['arguments']) !== 1
            || $this->unwrapGroup($left['arguments'][0])['type'] !== 'identifier') {
            return [
                'expression' => $this->mapExpression($left),
                'operator' => $predicate['operator'],
                'value' => $this->literal($predicate['right'] ?? []),
            ];
        }
        return [
            'function' => $left['name'],
            'field' => $this->directField($left['arguments'][0], 'HAVING aggregate'),
            'operator' => $predicate['operator'],
            'value' => $this->literal($predicate['right']),
        ];
    }

    private function directField(array $expression, string $context): string
    {
        $expression = $this->unwrapGroup($expression);
        if ($expression['type'] !== 'identifier') {
            throw new SqlMappingException(
                "{$context} requires a direct identifier at this structural argument position."
            );
        }
        return $expression['name'];
    }

    private function literal(array $expression)
    {
        $expression = $this->unwrapGroup($expression);
        if ($expression['type'] === 'literal') {
            $value = $expression['value'];
            if (is_scalar($value) || $value === null) {
                return $value;
            }
            throw new SqlMappingException('Literal AST values must be scalars or null.');
        }
        if ($expression['type'] === 'unary') {
            $inner = $this->unwrapGroup($expression['expression']);
            if (in_array($expression['operator'], ['+', '-'], true)
                && $inner['type'] === 'literal'
                && (is_int($inner['value']) || is_float($inner['value']))) {
                return $expression['operator'] === '-' ? -$inner['value'] : $inner['value'];
            }
        }
        throw new SqlMappingException('The public request requires a literal value at this position.');
    }

    private function isLiteralExpression(array $expression): bool
    {
        try {
            $this->literal($expression);
            return true;
        } catch (SqlMappingException $exception) {
            return false;
        }
    }

    private function integerLiteral(array $expression, string $context): int
    {
        $value = $this->literal($expression);
        if (!is_int($value)) {
            throw new SqlMappingException("{$context} must be an integer literal.");
        }
        return $value;
    }

    private function numericLiteral(array $expression, string $context): int|float
    {
        $value = $this->literal($expression);
        if (!is_int($value) && !is_float($value)) {
            throw new SqlMappingException("{$context} must be numeric.");
        }
        return $value;
    }

    private function stringLiteral(array $expression, string $context): string
    {
        $value = $this->literal($expression);
        if (!is_string($value)) {
            throw new SqlMappingException("{$context} must be a string literal.");
        }
        return $value;
    }

    private function unwrapGroup(array $expression): array
    {
        $depth = 0;
        while (($expression['type'] ?? null) === 'group') {
            if (++$depth > 64 || !is_array($expression['expression'] ?? null)) {
                throw new SqlMappingException('Malformed or excessively deep grouped SQL expression.');
            }
            $expression = $expression['expression'];
        }
        return $expression;
    }

    private function referencesSource(array $query, string $name): bool
    {
        if (strcasecmp($query['source']['table'], $name) === 0) {
            return true;
        }
        foreach (array_merge($query['commaSources'], array_column($query['joins'], 'source')) as $source) {
            if (strcasecmp($source['table'], $name) === 0) {
                return true;
            }
        }
        return false;
    }
}
