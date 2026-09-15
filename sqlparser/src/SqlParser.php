<?php

require_once __DIR__ . '/SqlLexer.php';

class SqlParser
{
    private const RESERVED_ALIASES = [
        'FROM', 'WHERE', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS',
        'OUTER', 'APPLY', 'ON', 'GROUP', 'HAVING', 'ORDER', 'UNION', 'AND',
        'OR', 'ASC', 'DESC', 'WHEN', 'THEN', 'ELSE', 'END', 'OVER',
    ];

    private array $tokens = [];
    private int $index = 0;

    public function parse(string $sql): array
    {
        if (trim($sql) === '') {
            throw new SqlParserException('SQL input is required.');
        }
        if (strlen($sql) > 200000) {
            throw new SqlParserException('SQL input exceeds 200,000 bytes.');
        }

        $this->tokens = (new SqlLexer())->tokenize($sql);
        $this->index = 0;
        $statement = $this->withOrQuery();

        $terminated = $this->symbol(';');
        if (!$this->is('eof')) {
            if ($terminated && ($this->peekWord('SELECT') || $this->peekWord('WITH'))) {
                $this->fail('Multiple SQL statements are not supported.');
            }
            $this->fail('Unexpected token after statement.');
        }

        return $statement;
    }

    private function withOrQuery(): array
    {
        if (!$this->word('WITH')) {
            return $this->queryExpression();
        }

        $ctes = [];
        do {
            $name = $this->identifierPart();
            $columns = [];
            if ($this->symbol('(')) {
                do {
                    $columns[] = $this->identifierPart();
                } while ($this->symbol(','));
                $this->expectSymbol(')');
            }
            $this->expectWord('AS');
            $this->expectSymbol('(');
            $query = $this->queryExpression();
            $this->expectSymbol(')');
            $ctes[] = ['type' => 'cte', 'name' => $name, 'columns' => $columns, 'query' => $query];
        } while ($this->symbol(','));

        return ['type' => 'with', 'ctes' => $ctes, 'query' => $this->queryExpression()];
    }

    private function queryExpression(): array
    {
        $queries = [$this->select()];
        $operators = [];
        while ($this->word('UNION')) {
            $operators[] = $this->word('ALL') ? 'UNION ALL' : 'UNION';
            $queries[] = $this->select();
        }
        return count($queries) === 1
            ? $queries[0]
            : ['type' => 'set', 'queries' => $queries, 'operators' => $operators];
    }

    private function select(): array
    {
        $this->expectWord('SELECT');
        $distinct = $this->word('DISTINCT');
        $top = null;
        if ($this->word('TOP')) {
            $parenthesized = $this->symbol('(');
            $top = $this->expect('number')['value'];
            if (!is_int($top) || $top < 1) {
                $this->fail('TOP requires a positive integer.');
            }
            if ($parenthesized) {
                $this->expectSymbol(')');
            }
        }

        $fields = [];
        do {
            $expression = $this->expression();
            $alias = null;
            if ($this->word('AS')) {
                $alias = $this->identifierPart();
            } elseif ($this->aliasAhead()) {
                $alias = $this->identifierPart();
            }
            $fields[] = ['expression' => $expression, 'alias' => $alias];
        } while ($this->symbol(','));

        $this->expectWord('FROM');
        $source = $this->source();
        $commaSources = [];
        while ($this->symbol(',')) {
            $commaSources[] = $this->source();
        }

        $joins = [];
        while ($this->joinAhead()) {
            $type = 'INNER';
            if ($this->word('LEFT')) {
                $type = 'LEFT';
                $this->word('OUTER');
            } elseif ($this->word('RIGHT')) {
                $type = 'RIGHT';
                $this->word('OUTER');
            } elseif ($this->word('FULL')) {
                $type = 'FULL';
                $this->word('OUTER');
            } elseif ($this->word('CROSS')) {
                $type = $this->word('APPLY') ? 'CROSS APPLY' : 'CROSS';
            } elseif ($this->word('OUTER')) {
                $this->expectWord('APPLY');
                $type = 'OUTER APPLY';
            } else {
                $this->word('INNER');
            }

            if (!str_ends_with($type, 'APPLY')) {
                $this->expectWord('JOIN');
            }
            $joinSource = $this->source();
            $on = null;
            if (!in_array($type, ['CROSS', 'CROSS APPLY', 'OUTER APPLY'], true)) {
                $this->expectWord('ON');
                $on = $this->booleanExpression();
            }
            $joins[] = ['type' => $type, 'source' => $joinSource, 'on' => $on];
        }

        $where = $this->word('WHERE') ? $this->booleanExpression() : null;
        $group = [];
        if ($this->word('GROUP')) {
            $this->expectWord('BY');
            do {
                $group[] = $this->expression();
            } while ($this->symbol(','));
        }
        $having = $this->word('HAVING') ? $this->booleanExpression() : null;
        $order = [];
        if ($this->word('ORDER')) {
            $this->expectWord('BY');
            do {
                $expression = $this->expression();
                $direction = $this->word('DESC') ? 'DESC' : ($this->word('ASC') ? 'ASC' : 'ASC');
                $order[] = ['expression' => $expression, 'direction' => $direction];
            } while ($this->symbol(','));
        }

        return compact(
            'distinct', 'top', 'fields', 'source', 'commaSources', 'joins',
            'where', 'group', 'having', 'order'
        ) + ['type' => 'select'];
    }

    private function source(): array
    {
        $table = $this->identifier();
        $alias = null;
        if ($this->word('AS')) {
            $alias = $this->identifierPart();
        } elseif ($this->aliasAhead()) {
            $alias = $this->identifierPart();
        }
        return compact('table', 'alias');
    }

    private function booleanExpression(): array
    {
        return $this->booleanOr();
    }

    private function booleanOr(): array
    {
        $left = $this->booleanAnd();
        while ($this->word('OR')) {
            $left = ['type' => 'boolean', 'operator' => 'OR', 'left' => $left, 'right' => $this->booleanAnd()];
        }
        return $left;
    }

    private function booleanAnd(): array
    {
        $left = $this->booleanPrimary();
        while ($this->word('AND')) {
            $left = ['type' => 'boolean', 'operator' => 'AND', 'left' => $left, 'right' => $this->booleanPrimary()];
        }
        return $left;
    }

    private function booleanPrimary(): array
    {
        if ($this->peek()['value'] === '(') {
            $savedIndex = $this->index;
            try {
                $this->index++;
                $expression = $this->booleanOr();
                $this->expectSymbol(')');
                return ['type' => 'boolean_group', 'expression' => $expression];
            } catch (SqlParserException) {
                $this->index = $savedIndex;
            }
        }
        return $this->predicate();
    }

    private function predicate(): array
    {
        $left = $this->expression();
        if ($this->word('IS')) {
            $not = $this->word('NOT');
            $this->expectWord('NULL');
            return ['type' => 'predicate', 'left' => $left, 'operator' => $not ? 'IS NOT NULL' : 'IS NULL'];
        }

        $not = $this->word('NOT');
        if ($this->word('BETWEEN')) {
            $lower = $this->expression();
            $this->expectWord('AND');
            $upper = $this->expression();
            return [
                'type' => 'predicate', 'left' => $left,
                'operator' => $not ? 'NOT BETWEEN' : 'BETWEEN',
                'right' => [$lower, $upper],
            ];
        }
        if ($this->word('IN')) {
            $this->expectSymbol('(');
            $values = [];
            do {
                $values[] = $this->expression();
            } while ($this->symbol(','));
            $this->expectSymbol(')');
            return [
                'type' => 'predicate', 'left' => $left,
                'operator' => $not ? 'NOT IN' : 'IN', 'right' => $values,
            ];
        }
        if ($this->word('LIKE')) {
            return [
                'type' => 'predicate', 'left' => $left,
                'operator' => $not ? 'NOT LIKE' : 'LIKE', 'right' => $this->expression(),
            ];
        }
        if ($not) {
            $this->fail('NOT must precede BETWEEN, IN, or LIKE.');
        }

        return [
            'type' => 'predicate', 'left' => $left,
            'operator' => $this->comparisonOperator(), 'right' => $this->expression(),
        ];
    }

    private function expression(int $minimumPrecedence = 0): array
    {
        $left = $this->unaryExpression();
        $precedence = ['+' => 10, '-' => 10, '*' => 20, '/' => 20, '%' => 20];
        while (isset($precedence[$this->peek()['value']])
            && $precedence[$this->peek()['value']] >= $minimumPrecedence) {
            $operator = $this->take()['value'];
            $right = $this->expression($precedence[$operator] + 1);
            $left = ['type' => 'binary', 'operator' => $operator, 'left' => $left, 'right' => $right];
        }
        return $left;
    }

    private function unaryExpression(): array
    {
        if ($this->symbol('+')) {
            return ['type' => 'unary', 'operator' => '+', 'expression' => $this->unaryExpression()];
        }
        if ($this->symbol('-')) {
            return ['type' => 'unary', 'operator' => '-', 'expression' => $this->unaryExpression()];
        }
        return $this->primaryExpression();
    }

    private function primaryExpression(): array
    {
        if ($this->symbol('(')) {
            $expression = $this->expression();
            $this->expectSymbol(')');
            return ['type' => 'group', 'expression' => $expression];
        }
        if ($this->word('CASE')) {
            return $this->caseExpression();
        }
        if ($this->word('CURRENT_TIMESTAMP')) {
            return ['type' => 'function', 'name' => 'CURRENT_TIMESTAMP', 'arguments' => []];
        }

        $token = $this->peek();
        if ($token['type'] === 'number' || $token['type'] === 'string') {
            $this->index++;
            return ['type' => 'literal', 'value' => $token['value']];
        }
        if ($this->word('NULL')) {
            return ['type' => 'literal', 'value' => null];
        }
        if ($this->symbol('*')) {
            return ['type' => 'identifier', 'name' => '*'];
        }

        $name = $this->identifier();
        if (!$this->symbol('(')) {
            return ['type' => 'identifier', 'name' => $name];
        }

        $function = strtoupper($name);
        if ($function === 'CAST') {
            $arguments = [$this->expression()];
            $this->expectWord('AS');
            $arguments[] = ['type' => 'datatype', 'name' => $this->datatype()];
            $this->expectSymbol(')');
        } else {
            $arguments = [];
            if (!$this->symbol(')')) {
                do {
                    $arguments[] = $this->expression();
                } while ($this->symbol(','));
                $this->expectSymbol(')');
            }
        }

        $expression = ['type' => 'function', 'name' => $function, 'arguments' => $arguments];
        if ($this->word('OVER')) {
            $expression = $this->windowExpression($expression);
        }
        return $expression;
    }

    private function caseExpression(): array
    {
        $when = [];
        while ($this->word('WHEN')) {
            $condition = $this->booleanExpression();
            $this->expectWord('THEN');
            $when[] = ['condition' => $condition, 'then' => $this->expression()];
        }
        $else = $this->word('ELSE') ? $this->expression() : null;
        $this->expectWord('END');
        return ['type' => 'case', 'when' => $when, 'else' => $else];
    }

    private function windowExpression(array $function): array
    {
        $this->expectSymbol('(');
        $partitionBy = [];
        if ($this->word('PARTITION')) {
            $this->expectWord('BY');
            do {
                $partitionBy[] = $this->expression();
            } while ($this->symbol(','));
        }
        $order = [];
        if ($this->word('ORDER')) {
            $this->expectWord('BY');
            do {
                $expression = $this->expression();
                $direction = $this->word('DESC') ? 'DESC' : ($this->word('ASC') ? 'ASC' : 'ASC');
                $order[] = ['expression' => $expression, 'direction' => $direction];
            } while ($this->symbol(','));
        }
        $this->expectSymbol(')');
        return [
            'type' => 'window', 'function' => $function,
            'partitionBy' => $partitionBy, 'order' => $order,
        ];
    }

    private function datatype(): string
    {
        $name = $this->identifierPart();
        if (!$this->symbol('(')) {
            return $name;
        }
        $sizes = [$this->expect('number')['value']];
        if ($this->symbol(',')) {
            $sizes[] = $this->expect('number')['value'];
        }
        $this->expectSymbol(')');
        return $name . '(' . implode(',', $sizes) . ')';
    }

    private function identifier(): string
    {
        $parts = [$this->identifierPart()];
        while ($this->symbol('.')) {
            $parts[] = $this->symbol('*') ? '*' : $this->identifierPart();
        }
        return implode('.', $parts);
    }

    private function identifierPart(): string
    {
        $token = $this->peek();
        if (!in_array($token['type'], ['word', 'identifier'], true)) {
            $this->fail('Expected identifier.');
        }
        $this->index++;
        return $token['value'];
    }

    private function comparisonOperator(): string
    {
        $operator = strtoupper((string) $this->peek()['value']);
        if (!in_array($operator, ['=', '!=', '<>', '>', '<', '>=', '<='], true)) {
            $this->fail('Expected comparison operator.');
        }
        $this->index++;
        return $operator;
    }

    private function joinAhead(): bool
    {
        return $this->peekWord('JOIN')
            || in_array(strtoupper((string) $this->peek()['value']), [
                'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'OUTER',
            ], true);
    }

    private function aliasAhead(): bool
    {
        return in_array($this->peek()['type'], ['word', 'identifier'], true)
            && !in_array(strtoupper((string) $this->peek()['value']), self::RESERVED_ALIASES, true);
    }

    private function peekWord(string $word): bool
    {
        return in_array($this->peek()['type'], ['word', 'identifier'], true)
            && strcasecmp((string) $this->peek()['value'], $word) === 0;
    }

    private function word(string $word): bool
    {
        if (!$this->peekWord($word)) {
            return false;
        }
        $this->index++;
        return true;
    }

    private function symbol(string $symbol): bool
    {
        if ($this->peek()['value'] !== $symbol) {
            return false;
        }
        $this->index++;
        return true;
    }

    private function expectWord(string $word): void
    {
        if (!$this->word($word)) {
            $this->fail("Expected {$word}.");
        }
    }

    private function expectSymbol(string $symbol): void
    {
        if (!$this->symbol($symbol)) {
            $this->fail("Expected '{$symbol}'.");
        }
    }

    private function expect(string $type): array
    {
        $token = $this->peek();
        if ($token['type'] !== $type) {
            $this->fail("Expected {$type}.");
        }
        $this->index++;
        return $token;
    }

    private function is(string $type): bool
    {
        return $this->peek()['type'] === $type;
    }

    private function peek(): array
    {
        return $this->tokens[$this->index];
    }

    private function take(): array
    {
        return $this->tokens[$this->index++];
    }

    private function fail(string $message): never
    {
        $token = $this->peek();
        throw new SqlParserException($message . " Found '{$token['value']}'.", $token['position']);
    }
}
