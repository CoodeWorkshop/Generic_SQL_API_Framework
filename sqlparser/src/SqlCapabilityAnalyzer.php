<?php

require_once __DIR__ . '/SqlBackendCapabilities.php';
require_once __DIR__ . '/SqlToApiMapper.php';
require_once __DIR__ . '/../../app/Requests/QueryRequestValidator.php';

class SqlCapabilityAnalyzer
{
    public function analyze(array $ast): array
    {
        $state = [
            'supported' => [], 'warnings' => [], 'unsupported' => [],
            'tables' => [], 'columns' => [], 'functions' => [], 'joins' => [],
            'filters' => [], 'grouping' => [], 'sorting' => [],
        ];

        if (($ast['type'] ?? null) === 'routine') {
            return [
                'supported' => ['PROCEDURE CALL'], 'warnings' => [], 'unsupported' => [],
                'detected' => [
                    'action' => 'procedure', 'tables' => [], 'columns' => [],
                    'joins' => [], 'filters' => [], 'grouping' => [],
                    'sorting' => [], 'functions' => [],
                ],
            ];
        }

        $root = $ast;
        if ($ast['type'] === 'with') {
            $state['supported'][] = 'CTE / WITH';
            if (count($ast['ctes']) > 1) {
                $state['unsupported'][] = 'Multiple CTE definitions are unsupported: the public with property accepts exactly one CTE definition.';
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
            $this->inspectWhereCompatibility($query['where'], $state, $query['commaSources'] !== []);
        }
        if ($query['group'] !== []) {
            $state['supported'][] = 'GROUP BY';
            foreach ($query['group'] as $expression) {
                $this->inspectExpression($expression, $state);
                $state['grouping'][] = $this->expressionLabel($expression);
                $this->inspectPublicFieldExpression($expression, $state, 'groupBy');
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
                $this->inspectPublicFieldExpression($order['expression'], $state, 'sort');
            }
        }
        if ($query['distinct']) {
            $state['supported'][] = 'DISTINCT';
        }
        if ($query['top'] !== null) {
            $state['supported'][] = 'TOP';
        }
        if (($query['pagination'] ?? null) !== null) {
            $state['supported'][] = 'OFFSET/FETCH pagination';
            if ($query['pagination']['offset'] % $query['pagination']['pageSize'] !== 0) {
                $state['unsupported'][] = 'OFFSET must be an exact multiple of FETCH to map to page/pageSize pagination.';
            }
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
        if (is_array($node['left'] ?? null)) {
            $this->inspectExpression($node['left'], $state);
        }
        $right = $node['right'] ?? null;
        foreach (is_array($right) && array_is_list($right) ? $right : [$right] as $expression) {
            if (is_array($expression)) {
                $this->inspectExpression($expression, $state);
            }
        }
    }

    private function inspectExpression(array $expression, array &$state): void
    {
        if ($expression['type'] === 'subquery') {
            $state['supported'][] = 'FILTER SUBQUERY';
            $this->inspectQuery($expression['query'], $state);
            return;
        }
        if ($expression['type'] === 'predicate') {
            $this->inspectBoolean($expression, $state);
            return;
        }
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

    private function inspectPublicFieldExpression(array $expression, array &$state, string $context = 'fields'): void
    {
        // Probe the authoritative public validator in the actual clause context.
        // This keeps aggregate/window/depth/signature rules shared, not mirrored.
        try {
            $mapper = new SqlToApiMapper();
            $request = ['action' => 'select', 'source' => ['table' => 'CapabilityProbe'], 'fields' => ['Probe']];
            if ($context === 'sort') {
                $request['sort'] = [$mapper->mapSort(['expression' => $expression, 'direction' => 'ASC'])];
            } else {
                $request[$context] = [$mapper->mapExpression($expression, $context !== 'fields')];
            }
            $this->validateCapabilityRequest($request, $state);
        } catch (SqlMappingException $exception) {
            $state['unsupported'][] = $exception->getMessage();
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
        try {
            $this->validateCapabilityRequest([
                'action' => 'select', 'source' => ['table' => 'CapabilityProbe'], 'fields' => ['Probe'],
                'having' => [(new SqlToApiMapper())->having($node)],
            ], $state);
        } catch (SqlMappingException $exception) {
            $state['unsupported'][] = $exception->getMessage();
        }
    }

    private function validateCapabilityRequest(array $request, array &$state): void
    {
        try {
            (new QueryRequestValidator())->validate($request);
        } catch (ApiRequestException $exception) {
            foreach ($exception->getDetails() as $detail) {
                $state['unsupported'][] = 'Backend public contract: ' . $detail['message'];
            }
        }
    }

    private function inspectWhereCompatibility(array $node, array &$state, bool $commaSources): void
    {
        if ($node['type'] === 'boolean_group') {
            $this->inspectWhereCompatibility($node['expression'], $state, $commaSources);
            return;
        }
        if ($node['type'] === 'boolean') {
            $this->inspectWhereCompatibility($node['left'], $state, $commaSources);
            $this->inspectWhereCompatibility($node['right'], $state, $commaSources);
            return;
        }
        if (in_array($node['operator'], ['EXISTS', 'NOT EXISTS'], true)) {
            return;
        }
        $left = $this->unwrapGroup($node['left']);
        if ($left['type'] !== 'identifier') {
            $state['unsupported'][] = 'Expression-valued WHERE inputs are unsupported; the left side must be a direct field.';
        }
        $right = $node['right'] ?? null;
        foreach (is_array($right) && array_is_list($right) ? $right : [$right] as $endpoint) {
            if ($endpoint === null) {
                continue;
            }
            $endpoint = $this->unwrapGroup($endpoint);
            if ($endpoint['type'] === 'subquery'
                && in_array($node['operator'], ['IN', 'NOT IN'], true)) {
                continue;
            }
            // Leave candidate comma-join edges to the existing safe join mapper.
            if ($commaSources && $node['operator'] === '=' && $left['type'] === 'identifier'
                && $endpoint['type'] === 'identifier') {
                continue;
            }
            if ($endpoint['type'] === 'literal' || ($endpoint['type'] === 'unary'
                && in_array($endpoint['operator'], ['+', '-'], true)
                && $this->unwrapGroup($endpoint['expression'])['type'] === 'literal'
                && is_numeric($this->unwrapGroup($endpoint['expression'])['value']))) {
                continue;
            }
            $state['unsupported'][] = in_array($node['operator'], ['BETWEEN', 'NOT BETWEEN'], true)
                ? 'Expression-valued BETWEEN endpoints are unsupported.'
                : 'Expression-valued WHERE comparison values are unsupported.';
        }
    }

    private function unwrapGroup(array $expression): array
    {
        while (($expression['type'] ?? null) === 'group') {
            $expression = $expression['expression'];
        }
        return $expression;
    }
}
