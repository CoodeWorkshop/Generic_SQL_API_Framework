# SQL → API JSON Generator

The SQL Parser Generator is an isolated developer tool that translates supported
SQL into the framework's existing Universal API JSON. It never executes pasted
SQL, opens a database connection, or routes through `api/index.php`.

## Run it

```bash
php -S 127.0.0.1:8001 -t sqlparser
```

Open `http://127.0.0.1:8001/`. Windows users with the bundled runtime can run
`sqlparser\start-windows.bat`. Keep this administrative tool behind appropriate
network/access controls; it does not add an authentication system.

The browser posts `{ "sql": "..." }` only to `sqlparser/index.php`. Requests are
limited to 200,000 bytes, responses are not cached, pasted SQL is not logged, and
the parser accepts no execution option.

## Architecture

```text
SQL input
  → SqlLexer tokens
  → SqlParser recursive abstract syntax tree
  → SqlCapabilityAnalyzer
  → SqlToApiMapper
  → existing QueryRequestValidator
  → existing QueryRequestNormalizer compatibility check
  → public Universal API JSON
```

This is a reverse adapter, not another query engine. Generated property names are
the same public names accepted by the API: `action`, `source`, `fields`,
`filters`, `joins`, `groupBy`, `having`, `sort`, `distinct`, `limit`, and the
existing set-operation `queries` shape. Internal normalized builder keys are not
emitted.

## Parser choice

No Composer dependency was added. The repository had no Composer setup, and the
evaluated open-source PHP parsers focus on the MySQL dialect rather than claiming
the SQL Server grammar needed here. The tool instead uses an isolated
character-level lexer and recursive-descent/precedence parser that produces a
real AST. Clause recognition is not implemented with SELECT/FROM regular
expressions. Unsupported grammar fails explicitly rather than being guessed.

## Supported conversion

- one SELECT with table and optional aliases;
- selected identifiers and aliases;
- DISTINCT and SQL Server TOP, mapped to public `distinct` and `limit`;
- INNER, LEFT, and RIGHT equality joins;
- legacy comma-separated FROM sources when each additional source has exactly
  one unambiguous AND equality predicate connecting it to an earlier source;
- flat WHERE conditions using one AND or OR logic;
- comparisons, LIKE/NOT LIKE, BETWEEN/NOT BETWEEN, IN/NOT IN, and NULL tests;
- GROUP BY identifiers;
- supported aggregate HAVING comparisons;
- ORDER BY fields or selected aliases with ASC/DESC;
- COUNT, SUM, AVG, MIN, MAX and selected simple scalar functions when their
  arguments match the exact public function schema;
- recursive function, unary, arithmetic, grouping, comparison, AND, and OR AST
  parsing with SQL precedence; mapping remains limited to shapes the public
  contract can preserve;
- simple searched CASE expressions;
- supported window `OVER (ORDER BY ...)` functions without `PARTITION BY`;
- one standard CTE and the existing two-branch recursive CTE form;
- homogeneous UNION or UNION ALL chains using the existing public actions;
- quoted strings, numeric literals, comments, bracketed identifiers, and a
  trailing semicolon.

Every successful result is passed through the production
`QueryRequestValidator`. A developer can copy it directly to the normal API,
subject to the API's usual live table/column metadata validation at execution.

## Deliberate limitations

The generator rejects or explains constructs that the public JSON contract cannot
represent, including FULL/CROSS/APPLY joins, non-equality or multi-term JOIN ON,
mixed AND/OR filters, HAVING OR, ORDER BY expressions, mixed UNION/UNION ALL
chains, branch ordering, derived tables, subqueries not covered by the mapper,
multiple CTE definitions, CTE column-name lists, and window `PARTITION BY`.

Nested functions and arithmetic are always parsed recursively. A generic AST
expression dispatcher preserves identifiers, literals, grouping, unary/binary
operators, functions, CASE, and windows. Positional function arguments are
translated through declarative public-property schemas; function admission is
read from the production validator's allowlist.

The capability analyzer stops translation where the public field schema cannot
preserve an AST. A top-level public `expression` permits one binary level, and
most public function shapes require a direct `field`; deeper valid SQL is
therefore reported as a capability limitation, not a parser failure or partial
JSON result. `CAST(expression AS datatype)` has dedicated SQL Server grammar and
maps when its expression is a direct field.

For example, the parser fully detects the joins, filters, grouping, sorting,
`ROUND`, and `SUM` in:

```sql
ROUND(SUM(BIL.Item_Rate) / 100000, 0)
```

However, JSON Query Mode requires ROUND to receive a direct field and supports
only one arithmetic level. The generator therefore recommends SQL Resource Mode
instead of emitting JSON that the API would reject. Save such reviewed business
SQL beneath `queries/` and call its discovered resource ID; pasted SQL is never
automatically written or executed.

## Relationship to the API

`sqlparser/index.php` serves only this UI and parsing endpoint.
`api/index.php` remains the production API entry point and has no dependency on
the generator. The tool works without SQL Server, ODBC, credentials, or
`database/config/database.json`.
