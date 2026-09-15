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
    !$nestedFunctionResult['success']
    && $nestedFunctionResult['error']['stage'] === 'capability'
    && str_contains($nestedFunctionResult['error']['details'][0]['message'], 'direct field identifier'),
    'Nested function mapping limitation was not separated from parsing.'
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
    !$groupedResult['success'] && $groupedResult['error']['stage'] === 'capability',
    'Nested arithmetic should parse and then report the public-contract capability limit.'
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

// Both mandatory sales-query forms must parse completely. Mapping then fails at
// the exact public limitation: ROUND.field cannot contain SUM/arithmetic.
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
        !$sales['success']
        && $sales['error']['stage'] === 'capability'
        && in_array('ROUND', $sales['analysis']['detected']['functions'], true)
        && in_array('SUM', $sales['analysis']['detected']['functions'], true)
        && in_array('BETWEEN', $sales['analysis']['detected']['filters'], true)
        && $sales['analysis']['detected']['sorting'] === ['Sales']
        && str_contains($sales['error']['details'][0]['message'], 'direct field identifier'),
        'Sales SQL was not parsed/analyzed before its exact mapping limitation was reported.'
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
    parserAssert($analysis['unsupported'] !== [], "Reported example {$number} unexpectedly had no contract limitation.");
    $result = generated($sql);
    parserAssert(
        !$result['success']
        && $result['error']['stage'] === 'capability'
        && !isset($result['request'])
        && !isset($result['candidate']),
        "Reported example {$number} emitted partial or invalid Universal JSON."
    );
    $messages = array_column($result['error']['details'], 'message');
    parserAssert(
        count($messages) === count(array_unique($messages)),
        "Reported example {$number} returned duplicate capability errors."
    );
}

// Pin the production validator rules responsible for the reported capability
// results. These assertions prevent the generator from inventing recursive JSON
// shapes that the real API rejects.
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
        'fields.0.field',
    ],
    'nested top-level arithmetic' => [
        [
            'action' => 'select', 'source' => ['table' => 'Items'],
            'fields' => [['expression' => [
                'left' => ['expression' => ['left' => 'Amount', 'operator' => '+', 'right' => 1]],
                'operator' => '*', 'right' => 2,
            ]]],
        ],
        'fields.0.expression.left',
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
    'window partitionBy' => [
        'action' => 'select', 'source' => ['table' => 'Items'],
        'fields' => [[
            'function' => 'DENSE_RANK', 'sort' => [['field' => 'Amount']],
            'partitionBy' => ['Category'],
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
