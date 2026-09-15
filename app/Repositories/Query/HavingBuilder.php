<?php

class HavingBuilder
{
    private MetadataRepository $metadataRepository;
    private $columnResolver;
    private ?SqlExpressionBuilder $expressionBuilder;

    public function __construct(
        MetadataRepository $metadataRepository,
        callable $columnResolver,
        ?SqlExpressionBuilder $expressionBuilder = null
    )
    {
        $this->metadataRepository = $metadataRepository;
        $this->columnResolver = $columnResolver;
        $this->expressionBuilder = $expressionBuilder;
    }

    public function build(array $request, array $params): array
    {
        if (empty($request['having'])) {
            return ['sql' => '', 'params' => $params];
        }
        $conditions = [];
        foreach ($request['having'] as $having) {
            if (isset($having['expression'])) {
                if ($this->expressionBuilder === null) {
                    throw new Exception('HAVING expression rendering is unavailable.');
                }
                foreach ($this->expressionBuilder->fieldNames($having['expression']) as $field) {
                    $resolved = ($this->columnResolver)($field);
                    $table = $resolved['table'] ?? $request['table'];
                    if (!$this->metadataRepository->columnExists($table, $resolved['column'])) {
                        throw new Exception("Invalid HAVING column: {$field}");
                    }
                }
                $expressionSql = $this->expressionBuilder->renderNode($having['expression'], $params);
                $conditions[] = $expressionSql . ' ' . $having['operator'] . ' ?';
                $params[] = $having['value'];
                continue;
            }
            if (!in_array(strtoupper($having['function']), ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'STRING_AGG'])) {
                throw new Exception("Invalid HAVING function: {$having['function']}");
            }
            if (strtoupper($having['column']) != '*') {
                $resolved = ($this->columnResolver)($having['column']);
                $table = $resolved['table'] ?? $request['table'];
                if (!$this->metadataRepository->columnExists($table, $resolved['column'])) {
                    throw new Exception("Invalid HAVING column: {$having['column']}");
                }
            }
            if (!in_array(strtoupper($having['operator']), ['=', '!=', '<>', '>', '<', '>=', '<='])) {
                throw new Exception("Invalid HAVING operator: {$having['operator']}");
            }
            $conditions[] = strtoupper($having['function']) . '(' . $having['column'] . ') '
                . $having['operator'] . ' ?';
            $params[] = $having['value'];
        }
        return ['sql' => ' HAVING ' . implode(' AND ', $conditions), 'params' => $params];
    }
}
