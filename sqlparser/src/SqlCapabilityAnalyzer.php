<?php

require_once __DIR__ . '/SqlBackendCapabilities.php';

class SqlCapabilityAnalyzer
{
    public function analyze(array $ast): array
    {
        $state = [
            'supported' => [], 'warnings' => [], 'unsupported' => [],
            'tables' => [], 'columns' => [], 'functions' => [], 'joins' => [],
            'filters' => [], 'grouping' => [], 'sorting' => [],
        ];

        $root = $ast;
        if ($ast['type'] === 'with') {
            $state['supported'][] = 'CTE / WITH';
            if (count($ast['ctes']) > 1) {
                $state['unsupported'][] = 'The public with property accepts exactly one CTE definition.';
            }
            foreach ($ast['ctes'] as $cte) {
                if ($cte['columns'] !== []) {
                    $state['unsupported'][] = 'CTE column-name lists are not exposed by the public with contract.';
                }
                $this->inspectQuery($cte['query'], $state);
            }
            $root = $ast['query'];
        }

        $this->inspectQuery($root, $state);
        $action = $root['type'] === 'set' ? 'set operation' : 'select';
        return [
            'supported' => array_values(array_unique($state['supported'])),
            'warnings' => array_values(array_unique($state['warnings'])),
            'unsupported' => array_values(array_unique($state['unsupported'])),
            'detected' => [
                'action' => $action,
                'tables' => array_values(array_unique($state['tables'])),
                'columns' => array_values(array_unique($state['columns'])),
                'joins' => $state['joins'],
                'filters' => $state['filters'],
                'grouping' => array_values(array_unique($state['grouping'])),
                'sorting' => array_values(array_unique($state['sorting'])),
                'functions' => array_values(array_unique($state['functions'])),
            ],
        ];
    }

    private function inspectQuery(array $query, array &$state): void
    {
        if ($query['type'] === 'set') {
            $state['supported'][] = 'SET OPERATION';
            if (count(array_unique($query['operators'])) > 1) {
                $state['unsupported'][] = 'A mixed UNION/UNION ALL chain cannot be represented by one public action.';
            }
            foreach ($query['queries'] as $branch) {
                $this->inspectSelect($branch, $state);
            }
            return;
        }
        $this->inspectSelect($query, $state);
    }

    private function inspectSelect(array $query, array &$state): void
    {
        $state['supported'][] = 'SELECT';
        $state['tables'][] = $query['source']['table'];
        foreach ($query['commaSources'] as $source) {
            $state['tables'][] = $source['table'];
        }
        if ($query['commaSources'] !== []) {
            $state['warnings'][] = 'Legacy comma-separated FROM sources require an unambiguous AND equality predicate to map to public INNER joins.';
        }

        foreach ($query['joins'] as $join) {
            $state['tables'][] = $join['source']['table'];
            $state['joins'][] = $join['type'];
            if (!in_array($join['type'], ['INNER', 'LEFT', 'RIGHT'], true)) {
                $state['unsupported'][] = "{$join['type']} is not accepted by the public join contract.";
            }
            if ($join['on'] !== null) {
                $this->inspectBoolean($join['on'], $state);
            }
        }

        foreach ($query['fields'] as $field) {
            $this->inspectExpression($field['expression'], $state);
            $this->inspectPublicFieldExpression($field['expression'], $state);
        }
        if ($query['where'] !== null) {
            $state['supported'][] = 'WHERE';
            $this->inspectBoolean($query['where'], $state, true);
        }
        if ($query['group'] !== []) {
            $state['supported'][] = 'GROUP BY';
            foreach ($query['group'] as $expression) {
                $this->inspectExpression($expression, $state);
                $state['grouping'][] = $this->expressionLabel($expression);
                if ($this->unwrapGroup($expression)['type'] !== 'identifier') {
                    $state['unsupported'][] = 'The public groupBy property accepts identifiers, not SQL expressions.';
                }
            }
        }
        if ($query['having'] !== null) {
            $state['supported'][] = 'HAVING';
            $this->inspectBoolean($query['having'], $state, true);
            $this->inspectHavingCompatibility($query['having'], $state);
        }
        if ($query['order'] !== []) {
            $state['supported'][] = 'ORDER BY';
            foreach ($query['order'] as $order) {
                $this->inspectExpression($order['expression'], $state);
                $state['sorting'][] = $this->expressionLabel($order['expression']);
                if ($this->unwrapGroup($order['expression'])['type'] !== 'identifier') {
                    $state['unsupported'][] = 'The public sort property accepts logical field identifiers, not SQL expressions.';
                }
            }
        }
        if ($query['distinct']) {
            $state['supported'][] = 'DISTINCT';
        }
        if ($query['top'] !== null) {
            $state['supported'][] = 'TOP';
        }
    }

    private function inspectBoolean(array $node, array &$state, bool $recordFilter = false): void
    {
        if ($node['type'] === 'boolean') {
            $this->inspectBoolean($node['left'], $state, $recordFilter);
            $this->inspectBoolean($node['right'], $state, $recordFilter);
            return;
        }
        if ($node['type'] === 'boolean_group') {
            $this->inspectBoolean($node['expression'], $state, $recordFilter);
            return;
        }
        if ($node['type'] !== 'predicate') {
            return;
        }
        if ($recordFilter) {
            $state['filters'][] = $node['operator'];
        }
        $this->inspectExpression($node['left'], $state);
        $right = $node['right'] ?? null;
        foreach (is_array($right) && array_is_list($right) ? $right : [$right] as $expression) {
            if (is_array($expression)) {
                $this->inspectExpression($expression, $state);
            }
        }
    }

    private function inspectExpression(array $expression, array &$state): void
    {
        if ($expression['type'] === 'identifier') {
            $state['columns'][] = $expression['name'];
            return;
        }
        if ($expression['type'] === 'function') {
            $state['functions'][] = $expression['name'];
            if (!SqlBackendCapabilities::supportsFunction($expression['name'])) {
                $state['unsupported'][] = "Function {$expression['name']} is not in the public function allowlist.";
            }
            foreach ($expression['arguments'] as $argument) {
                if (($argument['type'] ?? null) !== 'datatype') {
                    $this->inspectExpression($argument, $state);
                }
            }
            return;
        }
        if ($expression['type'] === 'window') {
            $this->inspectExpression($expression['function'], $state);
            foreach ($expression['partitionBy'] as $partition) {
                $this->inspectExpression($partition, $state);
            }
            foreach ($expression['order'] as $order) {
                $this->inspectExpression($order['expression'], $state);
            }
            return;
        }
        if ($expression['type'] === 'binary') {
            $this->inspectExpression($expression['left'], $state);
            $this->inspectExpression($expression['right'], $state);
            return;
        }
        if (in_array($expression['type'], ['group', 'unary'], true)) {
            $this->inspectExpression($expression['expression'], $state);
            return;
        }
        if ($expression['type'] === 'case') {
            foreach ($expression['when'] as $when) {
                $this->inspectBoolean($when['condition'], $state);
                $this->inspectExpression($when['then'], $state);
            }
            if ($expression['else'] !== null) {
                $this->inspectExpression($expression['else'], $state);
            }
        }
    }

    private function expressionLabel(array $expression): string
    {
        return $expression['name'] ?? $expression['type'];
    }

    private function inspectPublicFieldExpression(array $expression, array &$state): void
    {
        $expression = $this->unwrapGroup($expression);
        if (in_array($expression['type'], ['identifier', 'case'], true)) {
            return;
        }
        if ($expression['type'] === 'window') {
            if ($expression['partitionBy'] !== []) {
                $state['unsupported'][] = 'The public window-function schema has sort but no partitionBy property.';
            }
            return;
        }
        if ($expression['type'] === 'binary') {
            foreach (['left', 'right'] as $side) {
                if (!$this->isPublicArithmeticOperand($expression[$side])) {
                    $state['unsupported'][] = 'A public fields[].expression has one binary level and number-or-identifier operands.';
                    break;
                }
            }
            return;
        }
        if ($expression['type'] !== 'function') {
            $state['unsupported'][] = 'This selected expression has no representation in the public fields schema.';
            return;
        }

        $fieldArgumentIndexes = [
            'COUNT' => 0, 'SUM' => 0, 'AVG' => 0, 'MIN' => 0, 'MAX' => 0,
            'STRING_AGG' => 0, 'UPPER' => 0, 'LOWER' => 0, 'LTRIM' => 0,
            'RTRIM' => 0, 'TRIM' => 0, 'LEN' => 0, 'ISNULL' => 0,
            'CAST' => 0, 'CONVERT' => 1, 'NULLIF' => 0, 'LEFT' => 0,
            'RIGHT' => 0, 'SUBSTRING' => 0, 'REPLACE' => 0,
            'CHARINDEX' => 1, 'PATINDEX' => 1, 'FORMAT' => 0, 'YEAR' => 0,
            'MONTH' => 0, 'DAY' => 0, 'DATEPART' => 1, 'DATENAME' => 1,
            'DATEADD' => 2, 'ISDATE' => 0, 'ABS' => 0, 'ROUND' => 0,
            'CEILING' => 0, 'FLOOR' => 0, 'POWER' => 0, 'SQRT' => 0,
            'EXP' => 0, 'LOG' => 0,
        ];
        $name = $expression['name'];
        if (isset($fieldArgumentIndexes[$name])) {
            $argument = $expression['arguments'][$fieldArgumentIndexes[$name]] ?? null;
            if (!is_array($argument) || $this->unwrapGroup($argument)['type'] !== 'identifier') {
                $state['unsupported'][] = "The public {$name} schema requires a direct field identifier; its parsed SQL argument is an expression.";
            }
        }
    }

    private function inspectHavingCompatibility(array $node, array &$state): void
    {
        if ($node['type'] === 'boolean_group') {
            $this->inspectHavingCompatibility($node['expression'], $state);
            return;
        }
        if ($node['type'] === 'boolean') {
            if ($node['operator'] === 'OR') {
                $state['unsupported'][] = 'The public having array is combined with AND and has no HAVING OR representation.';
            }
            $this->inspectHavingCompatibility($node['left'], $state);
            $this->inspectHavingCompatibility($node['right'], $state);
            return;
        }
        $left = $this->unwrapGroup($node['left']);
        $validFunctions = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'STRING_AGG'];
        $argument = $left['arguments'][0] ?? null;
        if ($left['type'] !== 'function'
            || !in_array($left['name'], $validFunctions, true)
            || !is_array($argument)
            || $this->unwrapGroup($argument)['type'] !== 'identifier') {
            $state['unsupported'][] = 'Each public having item must be a supported aggregate over one direct field.';
        }
    }

    private function isPublicArithmeticOperand(array $expression): bool
    {
        $expression = $this->unwrapGroup($expression);
        return $expression['type'] === 'identifier'
            || ($expression['type'] === 'literal'
                && (is_int($expression['value']) || is_float($expression['value'])));
    }

    private function unwrapGroup(array $expression): array
    {
        while (($expression['type'] ?? null) === 'group') {
            $expression = $expression['expression'];
        }
        return $expression;
    }
}
