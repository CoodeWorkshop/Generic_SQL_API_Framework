<?php

class GroupByBuilder
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

    public function build(array $request, array &$params = []): string
    {
        if (empty($request['groupBy'])) {
            return '';
        }
        $items = [];
        foreach ($request['groupBy'] as $column) {
            if (is_array($column)) {
                if ($this->expressionBuilder === null) {
                    throw new Exception('GROUP BY expression rendering is unavailable.');
                }
                foreach ($this->expressionBuilder->fieldNames($column) as $field) {
                    $resolved = ($this->columnResolver)($field);
                    $table = $resolved['table'] ?? $request['table'];
                    if (!$this->metadataRepository->columnExists($table, $resolved['column'])) {
                        throw new Exception("Invalid GROUP BY column: {$field}");
                    }
                }
                $items[] = $this->expressionBuilder->renderNode($column, $params);
                continue;
            }
            $resolved = ($this->columnResolver)($column);
            $table = $resolved['table'] ?? $request['table'];
            if (!$this->metadataRepository->columnExists($table, $resolved['column'])) {
                throw new Exception("Invalid GROUP BY column: {$column}");
            }
            $items[] = $column;
        }
        return ' GROUP BY ' . implode(', ', $items);
    }
}
