# SQL parser/backend capability audit

Status: executable-code audit for parser parity. The backend public request,
validator, normalizer, and query builders are authoritative; this is not a SQL
Server feature list.

Legend: **Yes** means the complete public JSON → validator → normalizer → builder
path exists. **No** means the parser must reject it. **Partial** names the exact
boundary.

| Feature | Backend / public JSON | Validator + builder | Parser before this parity pass | Action |
|---|---|---|---|---|
| SELECT fields, qualified fields, aliases, `*` | Yes: `fields` strings/objects | Yes; metadata checked at execution | Yes | Keep |
| Qualified wildcard (`T.*`) | No public identifier shape (`*` is special only by itself) | Rejected | Parses but final validation rejects | Keep unsupported with explicit diagnostic |
| DISTINCT / TOP | `distinct`, `limit` | Yes | Yes | Keep |
| Recursive arithmetic, unary, functions, CASE | Typed ExpressionNode | Yes, depth 32 and context rules | Yes | Keep |
| INNER/LEFT/RIGHT joins, aliases, multiple joins | `joins[]` | One field-to-field equality per join | Yes | Keep |
| FULL/CROSS/APPLY or compound/literal JOIN ON | No public shape | Rejected | Parsed for diagnostics | Keep unsupported |
| Scalar WHERE operators | Flat `filters[]` | `= != <> > < >= <= LIKE NOT LIKE BETWEEN NOT BETWEEN IS NULL IS NOT NULL` | Yes | Keep |
| IN/NOT IN literal lists | Filter `value` list | Yes, non-empty scalars, prepared | Yes | Keep |
| IN/NOT IN SELECT subquery | Filter `query` | Yes; one explicit output field, nested SELECT restrictions | No | Implement |
| EXISTS/NOT EXISTS SELECT subquery | Operator + filter `query`, no field | Yes | No | Implement |
| Correlated subquery | Right-side field comparison would be required | Public WHERE comparison values are scalar-only | No | Keep unsupported |
| Derived table / scalar projection subquery | No `source` or ExpressionNode shape | No | No | Keep unsupported |
| Flat AND or flat OR | One `filterLogic` value | Yes | Yes | Keep |
| Mixed/nested AND+OR | No Boolean tree; grouping cannot be preserved | No | AST parses it | Keep unsupported |
| GROUP BY identifiers/expressions | `groupBy` strings/ExpressionNodes | Yes; aggregates/windows rejected | Yes | Keep |
| HAVING aggregate shorthand / aggregate expression AND | `having[]` | Yes; generic expression must contain aggregate | Yes | Keep |
| HAVING OR / mixed nested Boolean | No Boolean tree | No | Rejected | Keep unsupported |
| ORDER BY field/alias/expression, multiple entries | `sort[]` | Yes | Yes | Keep |
| Positional ORDER BY | Public validator rejects numeric fields | No | Explicitly rejected | Keep unsupported |
| Backend pagination | `pagination:{page,pageSize}` | Positive integers; builder creates count + OFFSET/FETCH/fallback | SQL OFFSET/FETCH not parsed | Implement only exact page-aligned OFFSET/FETCH |
| ROW_NUMBER/RANK/DENSE_RANK/NTILE/LAG/LEAD/FIRST_VALUE/LAST_VALUE | Window function objects | Partition/order expressions supported; sort required | Yes | Keep |
| Aggregate window such as `SUM(x) OVER` | Registry aggregate is not a backend window function | Window builder does not support it | Parsed then rejected | Keep unsupported |
| Nested window functions | No legal recursive context | Validator rejects | Rejected | Keep unsupported |
| One standard CTE | One `with` object | Yes; nested branch excludes action/sort/pagination/with | Yes | Keep |
| Existing two-branch recursive CTE | `anchor` + `recursive` | Yes, equal projection count | Yes (`UNION ALL` only) | Keep |
| Multiple CTEs / column lists / CTE preceding outer set | No public shape | Rejected | Parsed for diagnostics | Keep unsupported |
| UNION / UNION ALL homogeneous chains | Top-level actions with `queries[]` | Yes; matching projection count | Yes | Keep |
| Mixed set operators, branch/final sort, set pagination, branch CTE | No matching public envelope | Rejected | Rejected | Keep unsupported |
| Procedure public action | Explicit source name + prepared `parameters` | Yes | Parser accepted SELECT statements only | Support EXEC/EXECUTE with positional literal parameters; compiler still never executes it |
| Function/tableFunction public actions | Explicit source name + prepared `parameters` | Yes | No unambiguous statement grammar in the SELECT parser | Keep unsupported; do not confuse registry functions or table sources with routine actions |
| Named SQL placeholders (`:name`) | No public parameter-definition/reference node | No | Lexer rejects | Keep unsupported; clients supply filter values in JSON |
| CAST/CONVERT and datatypes | Named function properties | Existing datatype allowlist | Yes | Keep |
| DATE/TIME/conditional constructor functions | Function-specific named properties | Backend supports a restricted set of structural/value arguments | Mapper incomplete | Add only safely reversible public signatures |
| Arbitrary functions/operators/datatypes/raw SQL | None | Allowlist/restricted tokens | Rejected | Keep rejected |

Backend discrepancies found during the audit are kept fail-closed in the parser:
`TIMEFROMPARTS` exposes no validator-compatible `fractions` property; the
recursive renderer cannot render wildcard input; and COALESCE's optional
`default` is checked with `isset`, so a NULL fallback is lost and a one-argument
COALESCE would produce invalid SQL Server syntax. Those forms remain rejected
rather than expanding or silently repairing backend behavior here.

## Function capability classification

`QueryFunctionRegistry` remains the admission authority.

| Class | Registry functions | Parser parity boundary |
|---|---|---|
| Aggregate | COUNT, SUM, AVG, MIN, MAX, STRING_AGG | Direct or supported recursive input; no nested aggregates; no aggregate windows |
| String | UPPER, LOWER, LTRIM, RTRIM, TRIM, LEN, COALESCE, ISNULL, NULLIF, CONCAT, LEFT, RIGHT, SUBSTRING, REPLACE, CHARINDEX, PATINDEX, FORMAT, STRING_AGG | Named public properties only; COALESCE/CONCAT remain direct-field forms |
| Conversion | CAST, CONVERT | Existing datatype allowlist; style integer only |
| Date/time | YEAR, MONTH, DAY, DATEPART, DATENAME, GETDATE, DATEADD, DATEDIFF, EOMONTH, ISDATE, DATEFROMPARTS, DATETIMEFROMPARTS, TIMEFROMPARTS, SYSDATETIME, CURRENT_TIMESTAMP | Map only signatures representable by current named properties; TIMEFROMPARTS remains unusable because validator/builder properties disagree |
| Conditional | IIF, CHOOSE | Existing restricted condition/value properties only; not generic recursive function arguments |
| Mathematical | ABS, ROUND, CEILING, FLOOR, POWER, SQRT, EXP, LOG | Supported named numeric options and recursive primary input |
| Window | ROW_NUMBER, RANK, DENSE_RANK, NTILE, LAG, LEAD, FIRST_VALUE, LAST_VALUE | Top-level projection only; required ordering; optional partition/expression inputs where declared |

## Implemented parity scope

This parity pass adds only capabilities proven end-to-end:

1. `IN (SELECT ...)`, `NOT IN (SELECT ...)`, `EXISTS (SELECT ...)`, and
   `NOT EXISTS (SELECT ...)` using the existing filter `query` object.
2. SQL Server `OFFSET n ROWS FETCH NEXT m ROWS ONLY` when `n` is exactly page
   aligned (`n % m == 0`), mapped to existing pagination JSON.
3. Missing safe reverse mappings for the backend's named DATEDIFF, EOMONTH,
   DATEFROMPARTS, DATETIMEFROMPARTS, IIF, and CHOOSE forms.
4. `EXEC`/`EXECUTE` procedure compilation with positional scalar literal
   parameters into the existing explicit procedure action.
5. Explicit diagnostics/tests for the audited boundaries above.

No backend contract or builder capability is expanded by these changes.
