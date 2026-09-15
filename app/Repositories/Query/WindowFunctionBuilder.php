<?php

class WindowFunctionBuilder
{
    private OrderByBuilder $orderByBuilder;
    private $valueBuilder;
    private ?MetadataRepository $metadataRepository;
    private $columnResolver;
    private ?SqlExpressionBuilder $expressionBuilder;

    public function __construct(
        OrderByBuilder $orderByBuilder,
        callable $valueBuilder,
        ?MetadataRepository $metadataRepository = null,
        ?callable $columnResolver = null,
        ?SqlExpressionBuilder $expressionBuilder = null
    )
    {
        $this->orderByBuilder = $orderByBuilder;
        $this->valueBuilder = $valueBuilder;
        $this->metadataRepository = $metadataRepository;
        $this->columnResolver = $columnResolver;
        $this->expressionBuilder = $expressionBuilder;
    }

    public function build(
        string $function,
        array $column,
        array $request,
        string $alias,
        ?string $resolvedColumn,
        ?array &$params = null
    ): ?string {
        if ($params === null) {
            $params = [];
        }
        $supported = [
            'ROW_NUMBER', 'RANK', 'DENSE_RANK', 'NTILE',
            'LAG', 'LEAD', 'FIRST_VALUE', 'LAST_VALUE'
        ];
        if (!in_array($function, $supported, true)) {
            return null;
        }

        $parts = [];
        foreach ($column['partitionBy'] ?? [] as $partition) {
            if (is_string($partition)) {
                $resolved = ($this->columnResolver)($partition);
                $table = $resolved['table'] ?? $request['table'];
                if ($this->metadataRepository !== null
                    && !$this->metadataRepository->columnExists($table, $resolved['column'])) {
                    throw new Exception("Invalid PARTITION BY column: {$partition}");
                }
                $parts[] = $partition;
                continue;
            }
            if (!is_array($partition) || $this->expressionBuilder === null) {
                throw new Exception('Invalid PARTITION BY expression.');
            }
            foreach ($this->expressionBuilder->fieldNames($partition) as $field) {
                $resolved = ($this->columnResolver)($field);
                $table = $resolved['table'] ?? $request['table'];
                if ($this->metadataRepository !== null
                    && !$this->metadataRepository->columnExists($table, $resolved['column'])) {
                    throw new Exception("Invalid PARTITION BY column: {$field}");
                }
            }
            $parts[] = $this->expressionBuilder->renderNode($partition, $params);
        }
        $orders = $this->orderByBuilder->buildItems(
            $column['orderBy'],
            $request,
            false,
            false,
            true,
            $params
        );
        $orderSql = implode(', ', $orders);
        $over = ($parts === [] ? '' : 'PARTITION BY ' . implode(', ', $parts) . ' ')
            . 'ORDER BY ' . $orderSql;

        if (in_array($function, ['ROW_NUMBER', 'RANK', 'DENSE_RANK'], true)) {
            return "{$function}() OVER ({$over}) AS [{$alias}]";
        }
        if ($function === 'NTILE') {
            return 'NTILE(' . (int)$column['buckets']
                . ") OVER ({$over}) AS [{$alias}]";
        }
        if ($function === 'FIRST_VALUE') {
            return "FIRST_VALUE({$resolvedColumn}) OVER ({$over}) AS [{$alias}]";
        }
        if ($function === 'LAST_VALUE') {
            return "LAST_VALUE({$resolvedColumn}) OVER ({$over} ROWS BETWEEN "
                . "UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING) AS [{$alias}]";
        }

        $sql = $function . '(' . $resolvedColumn . ', ' . (int)($column['offset'] ?? 1);
        if (array_key_exists('default', $column)) {
            $sql .= ', ' . ($this->valueBuilder)($column['default']);
        }
        return $sql . ") OVER ({$over}) AS [{$alias}]";
    }
}
