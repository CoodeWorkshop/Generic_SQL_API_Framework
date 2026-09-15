<?php

require_once __DIR__ . '/../sqlparser/src/SqlParserRequestHandler.php';

function parserAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function generated(string $sql): array
{
    return (new SqlGenerator())->generate($sql);
}

function parsed(string $sql): array
{
    return (new SqlParser())->parse($sql);
}

function astTypes(array $node): array
{
    $types = isset($node['type']) && is_string($node['type']) ? [$node['type']] : [];
    foreach ($node as $value) {
        if (is_array($value)) {
            $types = array_merge($types, astTypes($value));
        }
    }
    return array_values(array_unique($types));
}

// Basic SELECT and statement termination.
$star = generated('SELECT * FROM ItemMasterTable;');
parserAssert($star['success'] && $star['request']['fields'] === ['*'], 'SELECT * with semicolon failed.');
$simple = generated("SELECT Item_Code, Item_Desc\nFROM ItemMasterTable\n ;   ");
parserAssert(
    $simple['success'] && $simple['request']['fields'] === ['Item_Code', 'Item_Desc'],
    'Simple SELECT with a whitespace-separated terminal semicolon failed.'
);
$semicolonString = generated("SELECT * FROM ItemMasterTable WHERE Name = 'ABC;DEF';");
parserAssert(
    $semicolonString['success'] && $semicolonString['request']['filters'][0]['value'] === 'ABC;DEF',
    'A semicolon inside a string was treated as a statement terminator.'
);
$unicodeString = generated("SELECT * FROM ItemMasterTable WHERE Name = N'Pen;Blue';");
parserAssert($unicodeString['success'], 'SQL Server Unicode string parsing failed.');

try {
    parsed('SELECT * FROM ItemMasterTable; SELECT * FROM CustomerTable;');
    throw new RuntimeException('Multiple statements were accepted.');
} catch (SqlParserException $exception) {
    parserAssert(
        str_contains($exception->getMessage(), 'Multiple SQL statements'),
        'Multiple statements did not receive a clear parser error.'
    );
}

// Function calls are recursive AST expressions, independent of mapping limits.
$sum = generated('SELECT SUM(Item_Rate) FROM BillDetTable;');
parserAssert($sum['success'] && $sum['request']['fields'][0]['function'] === 'SUM', 'SUM mapping failed.');
$round = generated('SELECT ROUND(Item_Rate, 2) FROM BillDetTable;');
parserAssert(
    $round['success'] && $round['request']['fields'][0]['precision'] === 2,
    'ROUND over a direct field failed.'
);
$nestedFunctionAst = parsed('SELECT ROUND(SUM(Item_Rate), 2) FROM BillDetTable;');
$nestedFunction = $nestedFunctionAst['fields'][0]['expression'];
parserAssert(
    $nestedFunction['type'] === 'function'
    && $nestedFunction['name'] === 'ROUND'
    && $nestedFunction['arguments'][0]['type'] === 'function'
    && $nestedFunction['arguments'][0]['name'] === 'SUM',
    'Nested function calls were not preserved in the AST.'
);
$nestedFunctionResult = generated('SELECT ROUND(SUM(Item_Rate), 2) FROM BillDetTable;');
parserAssert(
    $nestedFunctionResult['success']
    && $nestedFunctionResult['request']['fields'][0] === [
        'function' => 'ROUND', 'field' => ['function' => 'SUM', 'field' => ['field' => 'Item_Rate']], 'precision' => 2,
    ],
    'Nested function mapping did not preserve the recursive public contract.'
);

$functionCases = [
    'SELECT COUNT(*) FROM BillDetTable;',
    'SELECT ABS(Item_Rate) FROM BillDetTable;',
    'SELECT YEAR(Bill_Date) FROM BillDetTable;',
    'SELECT UPPER(Cust_Name) FROM CustomerTable;',
];
foreach ($functionCases as $sql) {
    parserAssert(generated($sql)['success'], "Supported function failed: {$sql}");
}

// Window syntax used to leave OVER's opening parenthesis after the statement.
$window = generated(
    'SELECT ROW_NUMBER() OVER (ORDER BY Bill_Date DESC) AS RowNo FROM BillDetTable;'
);
parserAssert(
    $window['success']
    && $window['request']['fields'][0]['function'] === 'ROW_NUMBER'
    && $window['request']['fields'][0]['sort'][0]['field'] === 'Bill_Date',
    'Window function OVER grammar failed.'
);

// Arithmetic AST precedence and grouping are recursive even where mapping is narrower.
$precedence = parsed('SELECT A + B * C AS Value FROM T;')['fields'][0]['expression'];
parserAssert(
    $precedence['type'] === 'binary'
    && $precedence['operator'] === '+'
    && $precedence['right']['type'] === 'binary'
    && $precedence['right']['operator'] === '*',
    'Multiplication did not bind more tightly than addition.'
);
$grouped = parsed('SELECT (Item_Rate + 10) * 2 FROM BillDetTable;')['fields'][0]['expression'];
parserAssert(
    $grouped['operator'] === '*' && $grouped['left']['type'] === 'group',
    'Parenthesized arithmetic was not preserved in the AST.'
);
$oneLevelArithmetic = generated('SELECT Item_Rate / 100 AS Rate FROM BillDetTable;');
parserAssert($oneLevelArithmetic['success'], 'Representable arithmetic failed.');
$groupedResult = generated('SELECT (Item_Rate + 10) * 2 FROM BillDetTable;');
parserAssert(
    $groupedResult['success'] && $groupedResult['request']['fields'][0] === ['expression' => [
        'left' => ['expression' => ['left' => ['field' => 'Item_Rate'], 'operator' => '+', 'right' => ['literal' => 10]]],
        'operator' => '*', 'right' => ['literal' => 2],
    ]],
    'Nested arithmetic did not preserve parentheses and typed operands.'
);

$nestedArithmeticAst = parsed(
    'SELECT ROUND(SUM(BIL.Item_Rate) / 100000, 0) AS Sales FROM BillDetTable BIL;'
)['fields'][0]['expression'];
parserAssert(
    $nestedArithmeticAst['type'] === 'function'
    && $nestedArithmeticAst['arguments'][0]['type'] === 'binary'
    && $nestedArithmeticAst['arguments'][0]['left']['type'] === 'function',
    'Function/arithmetic nesting was not represented recursively.'
);

// Filters and Boolean precedence.
$where = generated('SELECT Item_Code FROM ItemMasterTable WHERE Item_Code = 10;');
parserAssert($where['success'] && $where['request']['filters'][0]['value'] === 10, 'WHERE failed.');
$like = generated("SELECT Item_Code FROM ItemMasterTable WHERE Item_Desc LIKE '%pen%';");
parserAssert($like['success'] && $like['request']['filters'][0]['operator'] === 'LIKE', 'LIKE failed.');
$between = generated(
    'SELECT * FROM BillDetTable WHERE Bill_Date BETWEEN 20210401 AND 20220331;'
);
parserAssert(
    $between['success'] && $between['request']['filters'][0]['value'] === [20210401, 20220331],
    'BETWEEN failed.'
);
$booleanAst = parsed('SELECT * FROM T WHERE A = 1 OR B = 2 AND C = 3;')['where'];
parserAssert(
    $booleanAst['type'] === 'boolean'
    && $booleanAst['operator'] === 'OR'
    && $booleanAst['right']['operator'] === 'AND',
    'AND did not bind more tightly than OR.'
);

// Explicit joins, legacy comma joins, grouping, HAVING, and ordering aliases.
$joinSql = 'SELECT CAT.Cat_Desc FROM BillDetTable BIL '
    . 'JOIN CategoryTable CAT ON BIL.Cat_Code = CAT.Cat_Code';
$join = generated($joinSql . ';');
parserAssert(
    $join['success']
    && $join['request']['joins'][0]['type'] === 'INNER'
    && $join['request']['source']['alias'] === 'BIL',
    'Explicit JOIN failed.'
);
$commaJoin = generated(
    'SELECT CAT.Cat_Desc FROM BillDetTable BIL, CategoryTable CAT '
    . 'WHERE BIL.Cat_Code = CAT.Cat_Code;'
);
parserAssert(
    $commaJoin['success']
    && $commaJoin['request']['joins'][0]['source']['alias'] === 'CAT'
    && !isset($commaJoin['request']['filters']),
    'Unambiguous comma join was not normalized to an INNER join.'
);
$group = generated($joinSql . ' GROUP BY CAT.Cat_Desc;');
parserAssert($group['success'] && $group['request']['groupBy'] === ['CAT.Cat_Desc'], 'GROUP BY failed.');
$having = generated(
    'SELECT Category, SUM(Amount) AS Sales FROM Sales GROUP BY Category '
    . 'HAVING SUM(Amount) > 100 ORDER BY Sales DESC;'
);
parserAssert(
    $having['success']
    && $having['request']['having'][0]['function'] === 'SUM'
    && $having['request']['sort'][0]['field'] === 'Sales',
    'HAVING or ORDER BY alias failed.'
);

// DISTINCT, TOP, set operations, CASE, and CAST.
$distinct = generated('SELECT DISTINCT Item_Code FROM ItemMasterTable;');
parserAssert($distinct['success'] && $distinct['request']['distinct'] === true, 'DISTINCT failed.');
$top = generated('SELECT TOP (10) Item_Code FROM ItemMasterTable;');
parserAssert($top['success'] && $top['request']['limit'] === 10, 'TOP did not map to limit.');
$union = generated('SELECT Id FROM CurrentRows UNION SELECT Id FROM ArchiveRows;');
$unionAll = generated('SELECT Id FROM CurrentRows UNION ALL SELECT Id FROM ArchiveRows;');
parserAssert($union['success'] && $union['request']['action'] === 'union', 'UNION failed.');
parserAssert($unionAll['success'] && $unionAll['request']['action'] === 'unionAll', 'UNION ALL failed.');
$case = generated(
    "SELECT CASE WHEN Status = 'A' THEN 'Active' ELSE 'Inactive' END AS StatusText FROM Items;"
);
parserAssert($case['success'] && $case['request']['fields'][0]['case']['else'] === 'Inactive', 'CASE failed.');
$cast = generated('SELECT CAST(Amount AS decimal(10,2)) AS AmountValue FROM Sales;');
parserAssert($cast['success'] && $cast['request']['fields'][0]['datatype'] === 'decimal(10,2)', 'CAST failed.');

// Standard and recursive CTEs use the existing public with shape.
$cte = generated('WITH Active AS (SELECT Id FROM Items) SELECT Id FROM Active;');
parserAssert(
    $cte['success']
    && $cte['request']['with']['name'] === 'Active'
    && $cte['request']['with']['query']['source']['table'] === 'Items',
    'Standard CTE mapping failed.'
);
$recursiveCte = generated(
    'WITH Tree AS ('
    . 'SELECT CategoryId, ParentId FROM Categories WHERE ParentId IS NULL '
    . 'UNION ALL '
    . 'SELECT C.CategoryId, C.ParentId FROM Categories C '
    . 'JOIN Tree T ON C.ParentId = T.CategoryId'
    . ') SELECT CategoryId, ParentId FROM Tree;'
);
parserAssert(
    $recursiveCte['success']
    && isset($recursiveCte['request']['with']['anchor'], $recursiveCte['request']['with']['recursive']),
    'Recursive CTE mapping failed.'
);
$multipleCtes = generated(
    'WITH A AS (SELECT Id FROM Items), B AS (SELECT Id FROM A) SELECT Id FROM B;'
);
parserAssert(
    !$multipleCtes['success']
    && $multipleCtes['error']['stage'] === 'capability'
    && str_contains($multipleCtes['error']['details'][0]['message'], 'exactly one CTE'),
    'Multiple CTEs were not parsed and reported as a public capability limit.'
);

// Both sales-query join forms must retain the same supported expression mapping.
$salesSelect = 'SELECT TOP 10 CAT.Cat_Desc AS Category, '
    . 'ROUND(SUM(BIL.Item_Rate) / 100000, 0) AS Sales ';
$salesTail = 'WHERE BIL.Bill_NETT > 0 '
    . 'AND BIL.Bill_Date BETWEEN 20210401 AND 20220331 '
    . 'GROUP BY CAT.Cat_Desc ORDER BY Sales DESC;';
$salesQueries = [
    $salesSelect . 'FROM BillDetTable BIL, CategoryTable CAT '
        . 'WHERE BIL.Cat_Code = CAT.Cat_Code AND BIL.Bill_NETT > 0 '
        . 'AND BIL.Bill_Date BETWEEN 20210401 AND 20220331 '
        . 'GROUP BY CAT.Cat_Desc ORDER BY Sales DESC;',
    $salesSelect . 'FROM BillDetTable BIL JOIN CategoryTable CAT '
        . 'ON BIL.Cat_Code = CAT.Cat_Code ' . $salesTail,
];
foreach ($salesQueries as $salesSql) {
    $salesAst = parsed($salesSql);
    parserAssert($salesAst['type'] === 'select' && $salesAst['top'] === 10, 'Sales SQL did not parse completely.');
    $sales = generated($salesSql);
    parserAssert(
        $sales['success']
        && in_array('ROUND', $sales['analysis']['detected']['functions'], true)
        && in_array('SUM', $sales['analysis']['detected']['functions'], true)
        && in_array('BETWEEN', $sales['analysis']['detected']['filters'], true)
        && $sales['analysis']['detected']['sorting'] === ['Sales'],
        'Sales SQL failed recursive mapping or changed join analysis.'
    );
}

// Full developer-reported example matrix. All seven examples must tokenize and
// parse; genuinely unrepresentable shapes must stop at capability analysis with
// no partial Universal JSON candidate.
$reportedExamples = [
    1 => <<<'SQL'
SELECT
    Item_Code,
    Item_Desc,
    Sale_Rate,
    Item_MRP,
    Std_Vat,
    cl_stock,
    ROUND(Cl_Stock * Sale_Rate, 2) AS stock_value
FROM ItemMasterTable
SQL,
    2 => <<<'SQL'
SELECT
    COUNT(Item_Code) AS TotalItems,
    MIN(Sale_Rate) AS MinimumSP,
    MAX(Sale_Rate) AS MaximumSP,
    SUM(Sale_Rate * Cl_Stock) AS StockValue
FROM ItemMasterTable
SQL,
    3 => $salesQueries[0],
    4 => <<<'SQL'
SELECT
    SUBSTRING(CONVERT(VARCHAR, BILL_DATE), 5, 2) AS Month,
    ROUND(SUM(BILL_AMT), 0) AS Sales
FROM billmasttable
WHERE Bill_Date BETWEEN 20210401 AND 20220331
GROUP BY SUBSTRING(CONVERT(VARCHAR, BILL_DATE), 5, 2)
ORDER BY SUBSTRING(CONVERT(VARCHAR, BILL_DATE), 5, 2) DESC
SQL,
    5 => <<<'SQL'
SELECT
    SUBSTRING(CONVERT(VARCHAR, GIN_DATE), 5, 2) AS Month,
    ROUND(SUM(Inv_Value), 0) AS Purchases
FROM PurMastTable
WHERE GIN_DATE BETWEEN 20210401 AND 20220331
GROUP BY
    SUBSTRING(CONVERT(VARCHAR, GIN_DATE), 1, 4),
    SUBSTRING(CONVERT(VARCHAR, GIN_DATE), 5, 2)
ORDER BY
    SUBSTRING(CONVERT(VARCHAR, GIN_DATE), 1, 4),
    SUBSTRING(CONVERT(VARCHAR, GIN_DATE), 5, 2) DESC
SQL,
    6 => <<<'SQL'
SELECT
    SUBSTRING(CONVERT(VARCHAR, BIL.Bill_Date), 5, 2) AS Month,
    CAT.Cat_Desc AS Category,
    ROUND(SUM(BIL.Item_Rate) / 1000, 0) AS Sales
FROM BillDetTable BIL, CategoryTable CAT
WHERE
    BIL.Cat_Code = CAT.Cat_Code
    AND BIL.Bill_Date BETWEEN 20210401 AND 20220331
GROUP BY
    SUBSTRING(CONVERT(VARCHAR, BIL.Bill_Date), 5, 2),
    CAT.Cat_Desc
HAVING ROUND(SUM(BIL.Item_Rate) / 1000, 0) > 15
ORDER BY Month, Category
SQL,
    7 => <<<'SQL'
WITH SalesData AS
(
    SELECT
        SUBSTRING(CONVERT(VARCHAR, BILL_DATE), 5, 2) + '/' +
        SUBSTRING(CONVERT(VARCHAR, BILL_DATE), 1, 4) AS Month,
        CAT.Cat_Desc AS Category,
        ROUND(
            SUM(
                CASE
                    WHEN BIL.Status = 'S' THEN BIL.Item_Value
                    ELSE -1 * BIL.Item_Value
                END
            ) / 100000, 2
        ) AS Sales
    FROM BillDetTable BIL, CategoryTable CAT
    WHERE
        BIL.Cat_Code = CAT.Cat_Code
        AND Bill_Date BETWEEN
            CONVERT(NUMERIC,
                CONVERT(VARCHAR, YEAR(GETDATE()) - 5) + '04' + '01'
            )
            AND
            CONVERT(NUMERIC,
                CONVERT(VARCHAR, YEAR(GETDATE()) - 5 + 1) + '03' + '31'
            )
    GROUP BY
        SUBSTRING(CONVERT(VARCHAR, BILL_DATE), 5, 2) + '/' +
        SUBSTRING(CONVERT(VARCHAR, BILL_DATE), 1, 4),
        CAT.Cat_Desc
),
RankedData AS
(
    SELECT
        Month,
        Category,
        Sales,
        DENSE_RANK() OVER
        (
            PARTITION BY Month
            ORDER BY Sales DESC
        ) AS RowNo
    FROM SalesData
)
SELECT
    Month,
    Category,
    Sales
FROM RankedData
WHERE RowNo <= 10
ORDER BY Month, Sales DESC;
SQL,
];

$expectedAstTypes = [
    1 => ['select', 'function', 'binary'],
    2 => ['select', 'function', 'binary'],
    3 => ['select', 'function', 'binary', 'predicate', 'boolean'],
    4 => ['select', 'function', 'predicate'],
    5 => ['select', 'function'],
    6 => ['select', 'function', 'binary', 'predicate'],
    7 => ['with', 'cte', 'select', 'function', 'window', 'case', 'unary', 'binary'],
];

foreach ($reportedExamples as $number => $sql) {
    $tokens = (new SqlLexer())->tokenize($sql);
    parserAssert(end($tokens)['type'] === 'eof', "Reported example {$number} did not tokenize to EOF.");
    $ast = parsed($sql);
    $types = astTypes($ast);
    foreach ($expectedAstTypes[$number] as $type) {
        parserAssert(in_array($type, $types, true), "Reported example {$number} is missing AST node {$type}.");
    }
    $analysis = (new SqlCapabilityAnalyzer())->analyze($ast);
    $result = generated($sql);
    if ($number <= 6) {
        parserAssert($analysis['unsupported'] === [] && $result['success'], "Reported example {$number} failed: " . json_encode($result));
        (new QueryRequestValidator())->validate($result['request']);
        $normalized = (new QueryRequestNormalizer())->normalize($result['request']);
        parserAssert($normalized['controller'] === 'Query' && $normalized['columns'] !== [], "Example {$number} failed normalization.");
        continue;
    }
    parserAssert(
        !$result['success']
        && $result['error']['stage'] === 'capability'
        && !isset($result['request'])
        && !isset($result['candidate']),
        "Reported example {$number} emitted partial or invalid Universal JSON."
    );
    $messages = array_column($result['error']['details'], 'message');
    parserAssert(
        $messages === [
            'Multiple CTE definitions are unsupported: the public with property accepts exactly one CTE definition.',
            'Expression-valued BETWEEN endpoints are unsupported.',
        ],
        'Query 7 must report exactly the two remaining limitations: ' . json_encode($messages)
    );
}

// Golden public JSON: expected trees are constructed independently of the mapper.
$fieldNode = fn (string $name): array => ['field' => $name];
$literalNode = fn ($value): array => ['literal' => $value];
$binaryNode = fn (array $left, string $operator, array $right): array => [
    'expression' => ['left' => $left, 'operator' => $operator, 'right' => $right],
];
$sumNode = fn (string $field): array => ['function' => 'SUM', 'field' => $fieldNode($field)];
$roundNode = fn (array $input, string $alias): array => [
    'function' => 'ROUND', 'field' => $input, 'precision' => 0, 'alias' => $alias,
];
$monthNode = fn (string $field, int $start = 5, int $length = 2): array => [
    'function' => 'SUBSTRING',
    'field' => ['function' => 'CONVERT', 'datatype' => 'VARCHAR', 'field' => $fieldNode($field)],
    'start' => $start, 'length' => $length,
];
$joinNode = [
    'type' => 'INNER', 'source' => ['table' => 'CategoryTable', 'alias' => 'CAT'],
    'on' => ['left' => 'BIL.Cat_Code', 'operator' => '=', 'right' => 'CAT.Cat_Code'],
];
$rangeNode = fn (string $field): array => [
    'field' => $field, 'operator' => 'BETWEEN', 'value' => [20210401, 20220331],
];
$categoryNode = ['field' => 'CAT.Cat_Desc', 'alias' => 'Category'];
$sales6Node = $roundNode($binaryNode($sumNode('BIL.Item_Rate'), '/', $literalNode(1000)), 'Sales');
$having6Node = $sales6Node;
unset($having6Node['alias']);
$goldenRequests = [
    1 => [
        'action' => 'select', 'source' => ['table' => 'ItemMasterTable'],
        'fields' => ['Item_Code', 'Item_Desc', 'Sale_Rate', 'Item_MRP', 'Std_Vat', 'cl_stock', [
            'function' => 'ROUND', 'field' => $binaryNode($fieldNode('Cl_Stock'), '*', $fieldNode('Sale_Rate')),
            'precision' => 2, 'alias' => 'stock_value',
        ]],
    ],
    2 => [
        'action' => 'select', 'source' => ['table' => 'ItemMasterTable'], 'fields' => [
            ['function' => 'COUNT', 'field' => 'Item_Code', 'alias' => 'TotalItems'],
            ['function' => 'MIN', 'field' => 'Sale_Rate', 'alias' => 'MinimumSP'],
            ['function' => 'MAX', 'field' => 'Sale_Rate', 'alias' => 'MaximumSP'],
            ['function' => 'SUM', 'field' => $binaryNode($fieldNode('Sale_Rate'), '*', $fieldNode('Cl_Stock')), 'alias' => 'StockValue'],
        ],
    ],
    3 => [
        'action' => 'select', 'source' => ['table' => 'BillDetTable', 'alias' => 'BIL'],
        'fields' => [$categoryNode, $roundNode($binaryNode($sumNode('BIL.Item_Rate'), '/', $literalNode(100000)), 'Sales')],
        'limit' => 10, 'joins' => [$joinNode],
        'filters' => [['field' => 'BIL.Bill_NETT', 'operator' => '>', 'value' => 0], $rangeNode('BIL.Bill_Date')],
        'filterLogic' => 'AND', 'groupBy' => ['CAT.Cat_Desc'], 'sort' => [['field' => 'Sales', 'direction' => 'DESC']],
    ],
    4 => [
        'action' => 'select', 'source' => ['table' => 'billmasttable'],
        'fields' => [$monthNode('BILL_DATE') + ['alias' => 'Month'], $roundNode($sumNode('BILL_AMT'), 'Sales')],
        'filters' => [$rangeNode('Bill_Date')], 'groupBy' => [$monthNode('BILL_DATE')],
        'sort' => [['expression' => $monthNode('BILL_DATE'), 'direction' => 'DESC']],
    ],
    5 => [
        'action' => 'select', 'source' => ['table' => 'PurMastTable'],
        'fields' => [$monthNode('GIN_DATE') + ['alias' => 'Month'], $roundNode($sumNode('Inv_Value'), 'Purchases')],
        'filters' => [$rangeNode('GIN_DATE')], 'groupBy' => [$monthNode('GIN_DATE', 1, 4), $monthNode('GIN_DATE')],
        'sort' => [
            ['expression' => $monthNode('GIN_DATE', 1, 4), 'direction' => 'ASC'],
            ['expression' => $monthNode('GIN_DATE'), 'direction' => 'DESC'],
        ],
    ],
    6 => [
        'action' => 'select', 'source' => ['table' => 'BillDetTable', 'alias' => 'BIL'],
        'fields' => [$monthNode('BIL.Bill_Date') + ['alias' => 'Month'], $categoryNode, $sales6Node],
        'joins' => [$joinNode], 'filters' => [$rangeNode('BIL.Bill_Date')],
        'groupBy' => [$monthNode('BIL.Bill_Date'), 'CAT.Cat_Desc'],
        'having' => [['expression' => $having6Node, 'operator' => '>', 'value' => 15]],
        'sort' => [['field' => 'Month', 'direction' => 'ASC'], ['field' => 'Category', 'direction' => 'ASC']],
    ],
];
// Object key order is irrelevant in JSON; list order and scalar types are not.
$canonicalJson = function ($value) use (&$canonicalJson) {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value);
    return array_map($canonicalJson, $value);
};
foreach ($goldenRequests as $number => $expected) {
    parserAssert(
        $canonicalJson(generated($reportedExamples[$number])['request']) === $canonicalJson($expected),
        "Query {$number} golden Universal JSON changed."
    );
}

// Verify Query 7's now-supported subtrees separately without adding CTE/predicate support.
$recursiveCaseNode = ['case' => [
    'when' => [[
        'condition' => ['left' => $fieldNode('Status'), 'operator' => '=', 'right' => $literalNode('S')],
        'then' => $fieldNode('Item_Value'),
    ]],
    'else' => $binaryNode(['unary' => ['operator' => '-', 'operand' => $literalNode(1)]], '*', $fieldNode('Item_Value')),
]];
$caseAggregate = generated("SELECT SUM(CASE WHEN Status = 'S' THEN Item_Value ELSE -1 * Item_Value END) AS Sales FROM Items;");
parserAssert($caseAggregate['success'] && $caseAggregate['request']['fields'] === [[
    'function' => 'SUM', 'field' => $recursiveCaseNode, 'alias' => 'Sales',
]], 'CASE inside aggregate lost recursive branches or unary minus.');
$partitionWindow = generated('SELECT DENSE_RANK() OVER (PARTITION BY Month ORDER BY Sales DESC) AS RowNo FROM SalesData;');
parserAssert($partitionWindow['success'] && $partitionWindow['request']['fields'] === [[
    'function' => 'DENSE_RANK', 'sort' => [['field' => 'Sales', 'direction' => 'DESC']],
    'partitionBy' => ['Month'], 'alias' => 'RowNo',
]], 'Window PARTITION BY golden mapping failed.');
$expressionWindow = generated('SELECT LAG(Amount + 1, 2, 0) OVER (PARTITION BY Category + 2 ORDER BY Amount * 3 DESC) AS Previous FROM Items;');
parserAssert($expressionWindow['success'] && $expressionWindow['request']['fields'] === [[
    'function' => 'LAG', 'sort' => [['expression' => $binaryNode($fieldNode('Amount'), '*', $literalNode(3)), 'direction' => 'DESC']],
    'partitionBy' => [$binaryNode($fieldNode('Category'), '+', $literalNode(2))],
    'field' => $binaryNode($fieldNode('Amount'), '+', $literalNode(1)), 'offset' => 2, 'default' => 0, 'alias' => 'Previous',
]], 'Window expression input/partition/order mapping failed.');
$dateTree = generated("SELECT CONVERT(NUMERIC, CONVERT(VARCHAR, YEAR(GETDATE()) - 5) + '04' + '01') AS Boundary FROM Items;");
parserAssert($dateTree['success'] && $dateTree['request']['fields'] === [[
    'function' => 'CONVERT', 'datatype' => 'NUMERIC', 'field' => $binaryNode(
        $binaryNode(['function' => 'CONVERT', 'datatype' => 'VARCHAR', 'field' => $binaryNode(
            ['function' => 'YEAR', 'field' => ['function' => 'GETDATE']], '-', $literalNode(5)
        )], '+', $literalNode('04')), '+', $literalNode('01')
    ), 'alias' => 'Boundary',
]], 'Query 7 date expression subtree failed mapping.');
$expressionCte = generated('WITH SalesData AS (SELECT ROUND(SUM(Item_Rate) / 1000, 0) AS Sales FROM BillDetTable) SELECT Sales FROM SalesData;');
parserAssert($expressionCte['success'] && $expressionCte['request']['with']['query']['fields'] === [
    $roundNode($binaryNode($sumNode('Item_Rate'), '/', $literalNode(1000)), 'Sales'),
], 'Expression inside a single supported CTE failed.');
$expressionUnion = generated('SELECT Amount + 1 AS Value FROM CurrentRows UNION ALL SELECT Amount * 2 AS Value FROM ArchiveRows;');
parserAssert($expressionUnion['success'] && $expressionUnion['request']['action'] === 'unionAll', 'Expressions inside UNION ALL failed.');

// Pin legacy generator output, including one-level arithmetic and simple CASE.
parserAssert($oneLevelArithmetic['request']['fields'] === [[
    'expression' => ['left' => 'Item_Rate', 'operator' => '/', 'right' => 100], 'alias' => 'Rate',
]], 'Legacy arithmetic output changed unnecessarily.');
parserAssert($round['request']['fields'] === [['function' => 'ROUND', 'field' => 'Item_Rate', 'precision' => 2]], 'Legacy ROUND changed.');
parserAssert($sum['request']['fields'] === [['function' => 'SUM', 'field' => 'Item_Rate']], 'Legacy SUM changed.');
parserAssert($case['request']['fields'] === [['case' => ['when' => [[
    'condition' => ['field' => 'Status', 'operator' => '=', 'value' => 'A'], 'then' => 'Active',
]], 'else' => 'Inactive'], 'alias' => 'StatusText']], 'Legacy CASE changed.');
parserAssert($having['request']['having'] === [[
    'function' => 'SUM', 'field' => 'Amount', 'operator' => '>', 'value' => 100,
]], 'Legacy HAVING changed.');
parserAssert($window['request']['fields'] === [[
    'function' => 'ROW_NUMBER', 'sort' => [['field' => 'Bill_Date', 'direction' => 'DESC']], 'alias' => 'RowNo',
]], 'Legacy window output changed.');

// Old ambiguous recursive shapes must still fail production validation.
$invalidPublicShapes = [
    'nested function field' => [
        [
            'action' => 'select', 'source' => ['table' => 'Items'],
            'fields' => [[
                'function' => 'ROUND',
                'field' => ['expression' => ['left' => 'Amount', 'operator' => '*', 'right' => 2]],
                'precision' => 2,
            ]],
        ],
        'fields.0.field.expression.left',
    ],
    'nested top-level arithmetic' => [
        [
            'action' => 'select', 'source' => ['table' => 'Items'],
            'fields' => [['expression' => [
                'left' => ['expression' => ['left' => 'Amount', 'operator' => '+', 'right' => 1]],
                'operator' => '*', 'right' => 2,
            ]]],
        ],
        'fields.0.expression.left.expression.left',
    ],
    'expression groupBy' => [
        'action' => 'select', 'source' => ['table' => 'Items'], 'fields' => ['Amount'],
        'groupBy' => [['expression' => ['left' => 'Amount', 'operator' => '+', 'right' => 1]]],
    ],
    'expression sort' => [
        'action' => 'select', 'source' => ['table' => 'Items'], 'fields' => ['Amount'],
        'sort' => [['field' => ['expression' => ['left' => 'Amount', 'operator' => '+', 'right' => 1]]]],
    ],
    'nested HAVING' => [
        'action' => 'select', 'source' => ['table' => 'Items'], 'fields' => ['Amount'],
        'having' => [[
            'function' => 'ROUND', 'field' => 'Amount', 'operator' => '>', 'value' => 1,
        ]],
    ],
];

foreach ($invalidPublicShapes as $name => $definition) {
    $request = isset($definition[0]) && is_array($definition[0]) ? $definition[0] : $definition;
    try {
        (new QueryRequestValidator())->validate($request);
        throw new RuntimeException("Production validator accepted invalid {$name} shape.");
    } catch (ApiRequestException $exception) {
        if (isset($definition[1])) {
            parserAssert(
                in_array($definition[1], array_column($exception->getDetails(), 'path'), true),
                "Production validator did not reject {$name} at the expected path."
            );
        }
    }
}

$normalizedRound = (new QueryRequestNormalizer())->normalize($round['request']);
parserAssert(
    $normalizedRound['columns'][0]['function'] === 'ROUND'
    && $normalizedRound['columns'][0]['column'] === 'Item_Rate',
    'A representable function did not survive production normalization.'
);

// Security and intentional capability restrictions remain fail-closed.
$rejectedSql = [
    'SELECT [Amount; DROP TABLE Items] FROM Items;',
    'SELECT Amount AS [Alias];DROP] FROM Items;',
    'SELECT Amount AS [Bad Alias] FROM Items;',
    'SELECT [Bad;Function](Amount) FROM Items;',
    'SELECT UnknownFunction(Amount) FROM Items;',
    'SELECT Amount | 1 FROM Items;',
    'SELECT CAST(Amount AS [INT);DROP TABLE Items;--]) FROM Items;',
    'SELECT CONVERT([VARCHAR);DROP], Amount + 1) FROM Items;',
    'SELECT Amount FROM [Items;DROP TABLE Other];',
    'SELECT Amount FROM Items; DROP TABLE Items;',
    'SELECT ROUND((Amount +), 2) FROM Items;',
    'SELECT SUM(SUM(Amount)) FROM Items;',
    'SELECT SUM(ROW_NUMBER() OVER (ORDER BY Amount)) FROM Items;',
    'SELECT Amount FROM Items GROUP BY SUM(Amount);',
    'SELECT ROW_NUMBER() OVER (PARTITION BY SUM(Amount) ORDER BY Amount) FROM Items;',
    'SELECT Amount FROM Items HAVING Amount + 1 > 2;',
    'SELECT Amount FROM Items HAVING SUM(Amount) > 1 OR SUM(Amount) < 0;',
    'SELECT Amount FROM Items WHERE Amount > ROUND(OtherAmount, 0);',
    'SELECT Amount FROM Items WHERE Amount BETWEEN ABS(OtherAmount) AND 10;',
    'SELECT Amount FROM Items WHERE Amount NOT BETWEEN 1 AND ABS(OtherAmount);',
    'SELECT Amount FROM Items ORDER BY 1;',
    'SELECT ROW_NUMBER() OVER (ORDER BY 1) FROM Items;',
    'SELECT Amount FROM Items ORDER BY (1);',
    'SELECT ROUND(COUNT(*), 2) FROM Items;',
    'SELECT ABS(COALESCE(Amount, OtherAmount, 0)) FROM Items;',
];
foreach ($rejectedSql as $sql) {
    try {
        $result = generated($sql);
        parserAssert(!$result['success'] && !isset($result['request']) && !isset($result['candidate']),
            'Unsafe/unsupported SQL emitted a request or partial candidate: ' . $sql);
    } catch (SqlParserException $exception) {
        // Invalid SQL syntax must never reach mapping.
    }
}
$unsafeAlias = generated('SELECT Amount + 1 AS [Bad Alias] FROM Items;');
parserAssert(!$unsafeAlias['success'] && $unsafeAlias['error']['stage'] === 'validation'
    && $unsafeAlias['candidate'] === null, 'Final production validation was bypassed or leaked rejected JSON.');
$literalText = generated("SELECT 'x''; DROP TABLE Items;--' AS TextValue, NULL AS EmptyValue, -1.25 AS NegativeValue FROM Items;");
parserAssert($literalText['success'] && $literalText['request']['fields'] === [
    ['literal' => "x'; DROP TABLE Items;--", 'alias' => 'TextValue'],
    ['literal' => null, 'alias' => 'EmptyValue'],
    ['unary' => ['operator' => '-', 'operand' => ['literal' => 1.25]], 'alias' => 'NegativeValue'],
], 'Literal text, NULL, and negative decimal were confused with SQL syntax.');
$malformedAstNodes = [
    ['type' => 'raw', 'sql' => 'DROP TABLE Items'],
    ['type' => 'predicate', 'left' => ['type' => 'identifier', 'name' => 'Amount'], 'operator' => '=', 'right' => ['type' => 'literal', 'value' => 1]],
    ['type' => 'binary', 'left' => ['type' => 'literal', 'value' => 1], 'operator' => '+'],
    ['type' => 'binary', 'left' => ['type' => 'literal', 'value' => 1], 'operator' => ';DROP', 'right' => ['type' => 'literal', 'value' => 2]],
    ['type' => 'unary', 'operator' => 'EXEC', 'expression' => ['type' => 'literal', 'value' => 1]],
    ['type' => 'group'],
    ['type' => 'literal', 'value' => ['sql' => 'DROP TABLE Items']],
    ['type' => 'function', 'name' => 'ABS', 'arguments' => 'Amount'],
];
foreach ($malformedAstNodes as $node) {
    try {
        (new SqlToApiMapper())->mapExpression($node);
        throw new RuntimeException('Unexpected/malformed recursive AST node was accepted.');
    } catch (SqlMappingException $exception) {}
}
$deepSql = 'SELECT ' . str_repeat('ABS(', 35) . 'Amount' . str_repeat(')', 35) . ' FROM Items;';
parserAssert(!generated($deepSql)['success'], 'Backend expression depth restriction was bypassed by SQL generation.');
parserAssert(SqlBackendCapabilities::functions() === QueryFunctionRegistry::all(), 'Parser duplicated or changed registry admission.');

// Error categories and endpoint hardening.
[$invalidStatus, $invalid] = (new SqlParserRequestHandler())->handle('POST', '{broken', 7);
parserAssert($invalidStatus === 400 && $invalid['error']['code'] === 'INVALID_JSON', 'Invalid JSON handling failed.');
[$methodStatus] = (new SqlParserRequestHandler())->handle('PUT', '', 0);
parserAssert($methodStatus === 405, 'Method restriction failed.');
[$largeStatus] = (new SqlParserRequestHandler())->handle('POST', '', 200001);
parserAssert($largeStatus === 413, 'Body limit failed.');
[$parseStatus, $parseError] = (new SqlParserRequestHandler())->handle('POST', '{"sql":"SELECT FROM"}', 21);
parserAssert(
    $parseStatus === 400
    && $parseError['error']['stage'] === 'parser'
    && isset($parseError['analysis']['pipeline']['parser']),
    'Parser errors were not categorized.'
);
$unsupported = generated('SELECT A.Id FROM A FULL JOIN B ON A.Id = B.Id;');
parserAssert(
    !$unsupported['success']
    && $unsupported['error']['stage'] === 'capability'
    && str_contains($unsupported['error']['details'][0]['message'], 'FULL'),
    'Unsupported JOIN capability was not categorized.'
);

parserAssert(
    !class_exists('Database') && !class_exists('QueryEngine'),
    'Parser loaded database execution infrastructure.'
);

echo "SQL parser generator tests passed.\n";
