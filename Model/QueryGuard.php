<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model;

use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magento\Framework\Exception\LocalizedException;

/**
 * Validates that an operator-supplied statement is a single, read-only, bounded query and
 * returns the exact SQL the caller must execute — so what was validated is what runs.
 *
 * The layers below run in this order, each with its own rejection message:
 *
 * 1. **Lexing + comment stripping.** The statement is scanned byte by byte into tokens
 *    (words, string literals, numbers, punctuation) while comments are replaced by a single
 *    space and whitespace runs collapsed. Analysis happens on the token stream, never on the
 *    raw text, so a keyword inside a string literal cannot trip a scanner and a `--` inside a
 *    literal cannot truncate one. Literal boundaries must agree with the server's exactly,
 *    which is why `\` inside a literal is refused: whether it escapes depends on sql_mode, and
 *    a disagreement lets an attacker hide a whole clause inside what we think is one string.
 *    MySQL executable comments (`/*!`, `/*+`) are rejected
 *    outright: they hide syntax from naive scanners and have no legitimate place in an
 *    operator's read query.
 * 2. **Single statement.** A `;` anywhere but at the very end is refused, which is what stops
 *    `SELECT 1; DROP TABLE x`. Empty (or comment-only) input is refused.
 * 3. **Leading keyword allowlist.** The first token must be one of SELECT, SHOW, DESCRIBE,
 *    DESC, EXPLAIN, WITH — a whole token, so a keyword split by an interior comment is
 *    refused here instead of being reassembled into something that looks allowed.
 * 4. **Keyword deny-list.** Word-boundary, case-insensitive, anywhere in the statement:
 *    every mutating statement keyword plus the exfiltration/DoS forms (`INTO OUTFILE`,
 *    `LOAD_FILE`, `SLEEP`, `BENCHMARK`, `GET_LOCK`, …). Session and system variables (`@`,
 *    `@@`) are refused too, which covers `SELECT … INTO @var` and `SELECT @x := …`.
 * 4b. **Function allowlist.** Every `name(` must be a built-in read-only function. A deny-list
 *    cannot stop a stored function or UDF that mutates data, because the operator chooses the
 *    name; only an allowlist can.
 * 5. **Protected identifiers** — see {@see TableAccessPolicy}. Scanned over *every* identifier
 *    in the statement, not only the ones in table positions: this is the layer the crown jewels
 *    depend on, so it must not rest on the positional parser used by layer 5b. It also runs
 *    *first*, so its message wins over "not allowed" for a name that is both.
 * 5b. **Table allowlist** — `magebit_mcp/db_tools/allowed_tables`, empty by default; an empty
 *    list refuses every statement, `SELECT 1` included. Only identifiers in **table positions**
 *    have to be listed (see {@see assertTablesAreAllowlisted()}), so CTE names and aliases need
 *    no entry, and matching is exact-or-prefixed only — never the wide word-run matcher, which
 *    fails open in the allow direction. Running after layer 5 is what stops an allowlist entry
 *    from re-opening a protected name. The CTE exemption follows the server's own scoping rules
 *    exactly; anything looser is a read of an unlisted table.
 * 5c. **SHOW form allowlist** — `SHOW` sub-forms that expose tables the allowlist never named
 *    (`TRIGGERS`, `EVENTS`, `TABLE STATUS`, `ENGINE INNODB STATUS`, …) are refused outright;
 *    the forms that do take a table target go through layer 5b like any other table position.
 * 6. **Column deny-list** — secret-bearing column names, scanned over every identifier and kept
 *    independent of table resolution: a table allowlist cannot protect
 *    `customer_entity.password_hash` or `sales_order_payment.cc_*`, which sit on tables any
 *    useful allowlist contains.
 * 7. **No wildcards.** `SELECT *` / `t.*` would return secret columns that layer 6 cannot
 *    see, so an explicit column list is required. `COUNT(*)` is the one accepted `*` shape.
 * 8. **Row limit.** SELECT/WITH get `LIMIT <default>` appended when they carry none; a
 *    caller LIMIT above the configured ceiling is rejected rather than silently clamped,
 *    because clamping would let a client believe it had seen every matching row.
 *
 * Layers 7 and 8 are skipped for SHOW/DESCRIBE/DESC/EXPLAIN, which return no table rows.
 *
 * @phpstan-type SqlToken array{type: string, value: string, quoted: bool}
 *   `quoted` marks a backtick-quoted identifier — always an identifier or function name to
 *   MySQL, never a bare keyword, so it never earns the structural exemption in layer 4b.
 */
class QueryGuard
{
    /**
     * Row cap used if `default_rows` is missing or non-positive.
     */
    public const FALLBACK_ROWS = 100;

    private const TYPE_WORD = 'word';
    private const TYPE_STRING = 'string';
    private const TYPE_NUMBER = 'number';
    private const TYPE_PUNCT = 'punct';

    private const ALLOWED_LEADING_KEYWORDS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH'];

    /**
     * Statements that return metadata rather than table rows: exempt from layers 7 and 8.
     */
    private const METADATA_KEYWORDS = ['SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];

    /**
     * Mutating statement keywords, plus file/lock/timing primitives used for exfiltration
     * and denial of service. `REPLACE` and `TRUNCATE` also name read-only string/number
     * functions; they stay denied because the statement forms are the greater risk.
     */
    private const DENIED_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'RENAME',
        'GRANT', 'GRANTS', 'REVOKE', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'LOCK', 'UNLOCK', 'SHARE', 'CALL',
        'DO', 'HANDLER', 'LOAD', 'PREPARE', 'EXECUTE', 'DEALLOCATE', 'SET', 'FLUSH', 'RESET', 'SHUTDOWN',
        'KILL', 'INSTALL', 'UNINSTALL', 'BINLOG', 'PURGE', 'OPTIMIZE', 'REPAIR', 'ANALYZE', 'ANALYSE',
        'CHECKSUM', 'PROCEDURE', 'INTO', 'OUTFILE', 'DUMPFILE', 'LOAD_FILE', 'SLEEP', 'BENCHMARK',
        // Table-value constructors (MySQL 8.0.19+). `TABLE t` is `SELECT * FROM t` written with no
        // `*` and no column names, so as a `UNION`/`INTERSECT`/`EXCEPT` branch it slipped past the
        // wildcard rule and the column deny-list — nothing to reject — while the table name never
        // entered a table position and so was never allowlisted. `VALUES ROW(…)` is the same family.
        // Denied flat: neither has a place in an explicit-column read query, and a flat deny closes
        // every spelling at once rather than teaching the positional scanner a new grammar.
        // (`VALUES` also remains in STRUCTURAL_KEYWORDS; that entry is now unreachable, and
        // harmless, since this layer runs first.)
        'TABLE', 'VALUES',
        'GET_LOCK', 'RELEASE_LOCK', 'IS_USED_LOCK', 'IS_FREE_LOCK', 'SYS_EXEC', 'SYS_EVAL',
        // Row-collapsing aggregates: they defeat the row limit by returning a whole column in
        // one value, bounded only by max_allowed_packet.
        'GROUP_CONCAT', 'JSON_ARRAYAGG', 'JSON_OBJECTAGG',
        // Replication waits — arbitrary session blocking. MASTER_POS_WAIT was renamed, so the
        // current names have to be listed alongside the legacy one.
        'MASTER_POS_WAIT', 'SOURCE_POS_WAIT', 'WAIT_FOR_EXECUTED_GTID_SET',
        'WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS',
    ];

    /**
     * Read-only built-in functions a diagnostic query realistically needs. Deliberately
     * conservative: anything not listed is refused, which is what keeps stored functions and
     * UDFs (any name, including `MODIFIES SQL DATA` ones) out. Extend this list — never the
     * structural set below — when a legitimate built-in turns out to be missing.
     */
    private const ALLOWED_FUNCTIONS = [
        // Aggregates (row-collapsing ones are denied above) and window functions.
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'STD', 'STDDEV', 'STDDEV_POP', 'STDDEV_SAMP', 'VARIANCE',
        'VAR_POP', 'VAR_SAMP', 'BIT_AND', 'BIT_OR', 'BIT_XOR', 'ANY_VALUE', 'ROW_NUMBER', 'RANK',
        'DENSE_RANK', 'PERCENT_RANK', 'CUME_DIST', 'NTILE', 'LAG', 'LEAD', 'FIRST_VALUE', 'LAST_VALUE',
        'NTH_VALUE',
        // Strings and hashing.
        'CONCAT', 'CONCAT_WS', 'SUBSTRING', 'SUBSTR', 'MID', 'LEFT', 'RIGHT', 'LENGTH', 'CHAR_LENGTH',
        'CHARACTER_LENGTH', 'OCTET_LENGTH', 'LOWER', 'UPPER', 'LCASE', 'UCASE', 'TRIM', 'LTRIM', 'RTRIM',
        'LPAD', 'RPAD', 'REVERSE', 'REPEAT', 'INSTR', 'LOCATE', 'POSITION', 'FIELD', 'FIND_IN_SET', 'ELT',
        'HEX', 'UNHEX', 'ASCII', 'ORD', 'BIN', 'OCT', 'SPACE', 'STRCMP', 'SUBSTRING_INDEX', 'QUOTE',
        'FORMAT', 'REGEXP_LIKE', 'REGEXP_INSTR', 'REGEXP_SUBSTR', 'REGEXP_REPLACE', 'TO_BASE64',
        'FROM_BASE64', 'MD5', 'SHA1', 'SHA2', 'UUID', 'CRC32', 'BIT_COUNT', 'INET_ATON', 'INET_NTOA',
        // Numbers.
        'ABS', 'CEIL', 'CEILING', 'FLOOR', 'ROUND', 'MOD', 'POW', 'POWER', 'SQRT', 'EXP', 'LOG', 'LOG2',
        'LOG10', 'LN', 'SIGN', 'RAND', 'PI', 'GREATEST', 'LEAST', 'DEGREES', 'RADIANS', 'SIN', 'COS',
        'TAN', 'COT', 'ASIN', 'ACOS', 'ATAN', 'ATAN2',
        // Dates and times.
        'NOW', 'CURDATE', 'CURTIME', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'LOCALTIME',
        'LOCALTIMESTAMP', 'UNIX_TIMESTAMP', 'FROM_UNIXTIME', 'DATE', 'TIME', 'TIMESTAMP', 'YEAR', 'MONTH',
        'DAY', 'DAYOFMONTH', 'DAYOFWEEK', 'DAYOFYEAR', 'WEEK', 'WEEKDAY', 'WEEKOFYEAR', 'HOUR', 'MINUTE',
        'SECOND', 'MICROSECOND', 'QUARTER', 'MONTHNAME', 'DAYNAME', 'LAST_DAY', 'DATE_ADD', 'DATE_SUB',
        'DATE_FORMAT', 'STR_TO_DATE', 'DATEDIFF', 'TIMEDIFF', 'TIMESTAMPDIFF', 'TIMESTAMPADD', 'ADDDATE',
        'SUBDATE', 'ADDTIME', 'SUBTIME', 'MAKEDATE', 'MAKETIME', 'PERIOD_ADD', 'PERIOD_DIFF',
        'SEC_TO_TIME', 'TIME_TO_SEC', 'TIME_FORMAT', 'UTC_DATE', 'UTC_TIME', 'UTC_TIMESTAMP', 'YEARWEEK',
        'EXTRACT', 'CONVERT_TZ',
        // Casts, control flow, and the type names that take a parenthesised precision.
        'CAST', 'CONVERT', 'IF', 'IFNULL', 'NULLIF', 'COALESCE', 'ISNULL', 'CHAR', 'NCHAR', 'BINARY',
        'VARBINARY', 'VARCHAR', 'DECIMAL', 'SIGNED', 'UNSIGNED', 'INTEGER', 'DATETIME', 'FLOAT', 'DOUBLE',
        'JSON',
        // JSON accessors and constructors (the aggregating ones are denied above).
        'JSON_EXTRACT', 'JSON_UNQUOTE', 'JSON_VALUE', 'JSON_CONTAINS', 'JSON_CONTAINS_PATH', 'JSON_KEYS',
        'JSON_LENGTH', 'JSON_DEPTH', 'JSON_TYPE', 'JSON_VALID', 'JSON_QUOTE', 'JSON_OVERLAPS',
        'JSON_SEARCH', 'JSON_ARRAY', 'JSON_OBJECT',
        // Server metadata that leaks nothing beyond what SHOW already exposes.
        'DATABASE', 'VERSION',
    ];

    /**
     * Keywords that may legitimately be followed by `(` without naming a function —
     * subqueries, `IN (…)`, `WITH x AS (…)`, `OVER (…)`, and so on. Only consulted for
     * unquoted words: a backtick-quoted word before `(` is always a function call.
     *
     * The invariant every entry must satisfy: it can never be the unquoted name of a stored
     * function **in any supported server, MariaDB included**. "Reserved in MySQL 8" is the wrong
     * test for a Magento module and is exactly how `ANY`, `AGAINST` and `LATERAL` got in here.
     * Verified on MariaDB 10.6 with `SELECT <word>(1)`: every entry either fails to parse
     * (reserved) or parses as an operator / select modifier (`NOT`, `ALL`, `DISTINCT`,
     * `STRAIGHT_JOIN`), so none of them can reach a stored function.
     *
     * `LATERAL` was removed for failing that test: MariaDB 10.6 answers `SELECT lateral(1)` with
     * *FUNCTION … does not exist*, i.e. it is non-reserved there (as in MySQL 8.0.0–8.0.13) and
     * can name a stored function. MariaDB 10.6 has no lateral derived tables anyway. `ANY` and
     * `AGAINST` are absent for the same reason, at the cost of `= ANY (subquery)` and fulltext
     * `MATCH … AGAINST (…)`; `IN (subquery)` and `LIKE` cover the realistic cases.
     */
    private const STRUCTURAL_KEYWORDS = [
        'SELECT', 'FROM', 'WHERE', 'HAVING', 'GROUP', 'ORDER', 'BY', 'AND', 'OR', 'XOR', 'NOT', 'IN',
        'EXISTS', 'AS', 'ON', 'USING', 'JOIN', 'INNER', 'CROSS', 'NATURAL', 'STRAIGHT_JOIN',
        'UNION', 'EXCEPT', 'INTERSECT', 'ALL', 'SOME', 'DISTINCT', 'CASE', 'WHEN', 'THEN', 'ELSE',
        'IS', 'LIKE', 'RLIKE', 'REGEXP', 'BETWEEN', 'OVER', 'PARTITION', 'MATCH', 'INDEX',
        'KEY', 'INTERVAL', 'ROW', 'VALUES', 'WITH', 'RECURSIVE',
    ];

    /**
     * `SHOW` sub-forms that are accepted: either they name a table (which then goes through the
     * table allowlist) or they expose nothing table-specific. Everything else is refused,
     * including `TRIGGERS` / `EVENTS` (they embed statement bodies), `TABLE STATUS` and
     * `OPEN TABLES` (per-table row counts and names) and `ENGINE INNODB STATUS` (other sessions'
     * SQL). `SHOW CREATE …` and `SHOW GRANTS` never get this far — layer 4 denies those keywords.
     */
    private const ALLOWED_SHOW_FORMS = [
        'TABLES', 'COLUMNS', 'FIELDS', 'INDEX', 'INDEXES', 'KEYS', 'DATABASES', 'SCHEMAS', 'ENGINES',
        'CHARACTER', 'CHARSET', 'COLLATION', 'VARIABLES', 'STATUS', 'WARNINGS', 'ERRORS',
    ];

    /**
     * Words that may sit between `SHOW` and the sub-form itself.
     */
    private const SHOW_FORM_MODIFIERS = ['FULL', 'EXTENDED', 'GLOBAL', 'SESSION'];

    /**
     * Keywords that end a `FROM`/`JOIN` table list, after which a top-level comma separates
     * expressions (`GROUP BY a, b`) rather than another table reference.
     */
    private const TABLE_LIST_TERMINATORS = [
        'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET', 'WINDOW', 'UNION', 'EXCEPT', 'INTERSECT',
        'INTO', 'PROCEDURE', 'FOR', 'SET', 'LIKE',
    ];

    /**
     * The only words that may stand where a table name is expected without being one. Kept this
     * short on purpose: everything else in a table position is treated as a table name and has to
     * be allowlisted, so an unforeseen construct fails closed. `SELECT`/`WITH` open a derived
     * table or CTE and `DUAL` is the dummy one-row table; all three are reserved in MySQL and
     * MariaDB, so a real table with one of those names cannot be referenced unquoted — and a
     * quoted identifier is always checked.
     */
    private const NON_TABLE_WORDS = ['SELECT', 'WITH', 'DUAL'];

    /**
     * @param DbToolsConfig $config
     * @param TableAccessPolicy $tablePolicy
     * @param ColumnDenyList $columnDenyList
     */
    public function __construct(
        private readonly DbToolsConfig $config,
        private readonly TableAccessPolicy $tablePolicy,
        private readonly ColumnDenyList $columnDenyList
    ) {
    }

    /**
     * Validate a statement and return the normalised, limit-enforced SQL to execute verbatim.
     *
     * @param string $sql Raw statement, after any `{{table}}` substitution by the caller.
     * @param int|null $limit Row cap to append when the statement carries no LIMIT of its own.
     * @return string
     * @throws LocalizedException When any layer refuses the statement.
     */
    public function assertReadOnly(string $sql, ?int $limit = null): string
    {
        $this->assertNoControlCharacters($sql);

        $scan = $this->scan($sql);
        $normalised = $this->assertSingleStatement($scan['sql'], $scan['tokens']);
        $tokens = $normalised['tokens'];

        $leadingKeyword = $this->assertAllowedLeadingKeyword($tokens);
        $this->assertNoDeniedKeywords($tokens);
        $this->assertOnlyAllowedFunctions($tokens);
        // Layer 5 before 5b/5c, and never instead of them: the protected set vetoes the allowlist.
        $this->assertNoProtectedIdentifiers($tokens);
        $this->tablePolicy->assertUsableAllowlist();
        if ($leadingKeyword === 'SHOW') {
            $this->assertAllowedShowForm($tokens);
        }
        $this->assertTablesAreAllowlisted($tokens, $leadingKeyword);
        $this->assertNoDeniedColumns($tokens);

        if (in_array($leadingKeyword, self::METADATA_KEYWORDS, true)) {
            return $normalised['sql'];
        }

        $this->assertNoWildcard($tokens);

        return $this->applyRowLimit($normalised['sql'], $tokens, $limit);
    }

    /**
     * Row cap in effect for an already-validated statement, for the caller's response payload.
     *
     * @param string $sql
     * @return int|null Null when the statement has no trailing LIMIT (metadata statements).
     */
    public function findRowLimit(string $sql): ?int
    {
        try {
            $tokens = $this->scan($sql)['tokens'];
            $index = $this->findLimitIndex($tokens);

            return $index === null ? null : $this->readTrailingLimit($tokens, $index);
        } catch (LocalizedException) {
            return null;
        }
    }

    // ------------------------------------------------------------------ layer 1: lexing

    /**
     * @param string $sql
     * @return void
     * @throws LocalizedException
     */
    private function assertNoControlCharacters(string $sql): void
    {
        // `!== 0` rather than `=== 1`: a PCRE failure returns false and must reject, not pass.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $sql) !== 0) {
            throw new LocalizedException(
                __('The query contains a control character. Remove it and try again.')
            );
        }
    }

    /**
     * Tokenises the statement while stripping comments and collapsing whitespace.
     *
     * @param string $sql
     * @return array{sql: string, tokens: list<SqlToken>}
     * @throws LocalizedException
     */
    private function scan(string $sql): array
    {
        $length = strlen($sql);
        $output = '';
        $tokens = [];
        $pendingSpace = false;
        $position = 0;

        while ($position < $length) {
            $char = $sql[$position];

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                $pendingSpace = true;
                $position++;
                continue;
            }

            if ($char === '#') {
                $position = $this->skipToLineEnd($sql, $position);
                $pendingSpace = true;
                continue;
            }

            if ($char === '-' && ($sql[$position + 1] ?? '') === '-') {
                $following = $sql[$position + 2] ?? "\n";
                if (!in_array($following, [' ', "\t", "\n", "\r"], true)) {
                    throw new LocalizedException(
                        __(
                            'A `--` comment must be followed by whitespace. `%1` is ambiguous '
                            . '(MySQL reads it as arithmetic), so the query is refused.',
                            '--' . $following
                        )
                    );
                }
                $position = $this->skipToLineEnd($sql, $position);
                $pendingSpace = true;
                continue;
            }

            if ($char === '/' && ($sql[$position + 1] ?? '') === '*') {
                $marker = $sql[$position + 2] ?? '';
                if ($marker === '!' || $marker === '+') {
                    throw new LocalizedException(
                        __(
                            'The query contains a MySQL executable comment (`/*%1`). '
                            . 'Executable comments hide syntax from validation and are never accepted.',
                            $marker
                        )
                    );
                }
                $end = strpos($sql, '*/', $position + 2);
                if ($end === false) {
                    throw new LocalizedException(__('The query contains an unterminated `/* */` comment.'));
                }
                $position = $end + 2;
                $pendingSpace = true;
                continue;
            }

            if ($pendingSpace && $output !== '') {
                $output .= ' ';
            }
            $pendingSpace = false;

            if ($char === '"') {
                throw new LocalizedException(
                    __('Double-quoted values are not accepted. Use single quotes for string literals.')
                );
            }

            if ($char === '{' || $char === '}') {
                throw new LocalizedException(
                    __(
                        'The query contains `%1`. `{{table_name}}` placeholders must be resolved to real '
                        . 'table names before the query is validated.',
                        $char
                    )
                );
            }

            if ($char === "'") {
                $literal = $this->readStringLiteral($sql, $position);
                $output .= $literal['raw'];
                $tokens[] = ['type' => self::TYPE_STRING, 'value' => $literal['raw'], 'quoted' => false];
                $position = $literal['next'];
                continue;
            }

            if ($char === '`') {
                $identifier = $this->readQuotedIdentifier($sql, $position);
                $output .= $identifier['raw'];
                $tokens[] = ['type' => self::TYPE_WORD, 'value' => $identifier['value'], 'quoted' => true];
                $position = $identifier['next'];
                continue;
            }

            if ($this->isIdentifierChar($char)) {
                $word = '';
                while ($position < $length && $this->isIdentifierChar($sql[$position])) {
                    $word .= $sql[$position];
                    $position++;
                }
                $output .= $word;
                $tokens[] = [
                    'type' => ctype_digit($word) ? self::TYPE_NUMBER : self::TYPE_WORD,
                    'value' => $word,
                    'quoted' => false,
                ];
                continue;
            }

            $output .= $char;
            $tokens[] = ['type' => self::TYPE_PUNCT, 'value' => $char, 'quoted' => false];
            $position++;
        }

        return ['sql' => $output, 'tokens' => $tokens];
    }

    /**
     * @param string $sql
     * @param int $position
     * @return int
     */
    private function skipToLineEnd(string $sql, int $position): int
    {
        $newline = strcspn($sql, "\r\n", $position);

        return $position + $newline;
    }

    /**
     * Reads a `'…'` literal. Backslashes are refused outright: whether `\` escapes the next
     * byte depends on the server's `NO_BACKSLASH_ESCAPES` sql_mode (and on the connection
     * charset, where 0x5C can be a trail byte), so honouring it would make our literal
     * boundaries disagree with the server's on the very string we hand back for execution.
     * `''` doubling embeds a quote identically under every mode.
     *
     * @param string $sql
     * @param int $position Index of the opening quote.
     * @return array{raw: string, next: int}
     * @throws LocalizedException
     */
    private function readStringLiteral(string $sql, int $position): array
    {
        $length = strlen($sql);
        $raw = "'";
        $position++;

        while ($position < $length) {
            $char = $sql[$position];

            if ($char === '\\') {
                throw new LocalizedException(
                    __(
                        'Backslashes are not accepted inside string literals, because whether `\\` escapes '
                        . 'the next character depends on the server\'s sql_mode. Use `\'\'` to embed a '
                        . 'single quote.'
                    )
                );
            }

            if ($char === "'") {
                if (($sql[$position + 1] ?? '') === "'") {
                    $raw .= "''";
                    $position += 2;
                    continue;
                }

                return ['raw' => $raw . "'", 'next' => $position + 1];
            }

            $raw .= $char;
            $position++;
        }

        throw new LocalizedException(__('The query contains an unterminated string literal.'));
    }

    /**
     * @param string $sql
     * @param int $position Index of the opening backtick.
     * @return array{raw: string, value: string, next: int}
     * @throws LocalizedException
     */
    private function readQuotedIdentifier(string $sql, int $position): array
    {
        $length = strlen($sql);
        $value = '';
        $position++;

        while ($position < $length) {
            $char = $sql[$position];

            if ($char === '`') {
                if (($sql[$position + 1] ?? '') === '`') {
                    $value .= '`';
                    $position += 2;
                    continue;
                }

                return ['raw' => '`' . $value . '`', 'value' => $value, 'next' => $position + 1];
            }

            $value .= $char;
            $position++;
        }

        throw new LocalizedException(__('The query contains an unterminated `` ` `` quoted identifier.'));
    }

    /**
     * @param string $char
     * @return bool
     */
    private function isIdentifierChar(string $char): bool
    {
        return ctype_alnum($char) || $char === '_' || $char === '$' || ord($char) >= 0x80;
    }

    // ------------------------------------------------------------------ layer 2: single statement

    /**
     * @param string $sql
     * @param list<SqlToken> $tokens
     * @return array{sql: string, tokens: non-empty-list<SqlToken>}
     * @throws LocalizedException
     */
    private function assertSingleStatement(string $sql, array $tokens): array
    {
        $lastIndex = count($tokens) - 1;
        foreach ($tokens as $index => $token) {
            if ($token['type'] === self::TYPE_PUNCT && $token['value'] === ';' && $index !== $lastIndex) {
                throw new LocalizedException(
                    __('Only a single statement is accepted. Remove everything after the first `;`.')
                );
            }
        }

        $last = $tokens[$lastIndex] ?? null;
        if ($last !== null && $last['type'] === self::TYPE_PUNCT && $last['value'] === ';') {
            array_pop($tokens);
            $sql = rtrim(substr($sql, 0, -1));
        }

        if ($tokens === []) {
            throw new LocalizedException(__('The query is empty.'));
        }

        return ['sql' => $sql, 'tokens' => $tokens];
    }

    // ------------------------------------------------------------------ layer 3: leading keyword

    /**
     * @param non-empty-list<SqlToken> $tokens
     * @return string The upper-cased leading keyword.
     * @throws LocalizedException
     */
    private function assertAllowedLeadingKeyword(array $tokens): string
    {
        $first = $tokens[0];
        $keyword = strtoupper($first['value']);

        if ($first['type'] !== self::TYPE_WORD || !in_array($keyword, self::ALLOWED_LEADING_KEYWORDS, true)) {
            throw new LocalizedException(
                __(
                    'The query must begin with one of: %1. Found `%2`.',
                    implode(', ', self::ALLOWED_LEADING_KEYWORDS),
                    $first['value']
                )
            );
        }

        return $keyword;
    }

    // ------------------------------------------------------------------ layer 4: keyword deny-list

    /**
     * @param list<SqlToken> $tokens
     * @return void
     * @throws LocalizedException
     */
    private function assertNoDeniedKeywords(array $tokens): void
    {
        foreach ($tokens as $token) {
            if ($token['type'] === self::TYPE_PUNCT && $token['value'] === '@') {
                throw new LocalizedException(
                    __(
                        'Session and system variables (`@name`, `@@name`) are not permitted — '
                        . 'this also refuses `SELECT … INTO @var` and variable assignment.'
                    )
                );
            }
        }

        foreach ($tokens as $index => $token) {
            if ($token['type'] !== self::TYPE_WORD) {
                continue;
            }

            $keyword = strtoupper($token['value']);
            if ($keyword === 'INTO') {
                $next = $tokens[$index + 1] ?? null;
                $target = $next !== null ? strtoupper($next['value']) : '';
                if ($target === 'OUTFILE' || $target === 'DUMPFILE') {
                    throw new LocalizedException(
                        __('`INTO %1` writes to the server filesystem and is not permitted.', $target)
                    );
                }
            }

            $this->assertNotASequenceValueForm($tokens, $index, $keyword);

            if (in_array($keyword, self::DENIED_KEYWORDS, true)) {
                throw new LocalizedException(
                    __('The keyword `%1` is not permitted in a read-only query.', $keyword)
                );
            }
        }
    }

    /**
     * `NEXT VALUE FOR seq` advances a sequence — a write, in a guard whose whole contract is
     * read-only — and neither form's operand ever reaches a table position, so the sequence is not
     * allowlisted either. Matched as a three-token phrase rather than by denying the bare word
     * `VALUE`: that word names a column on every EAV value table in Magento, three of which are in
     * the module's own suggested allowlist, so denying it would cost far more than it buys.
     *
     * @param list<SqlToken> $tokens
     * @param int $index
     * @param string $keyword
     * @return void
     * @throws LocalizedException
     */
    private function assertNotASequenceValueForm(array $tokens, int $index, string $keyword): void
    {
        if ($keyword !== 'NEXT' && $keyword !== 'PREVIOUS') {
            return;
        }

        $value = $tokens[$index + 1] ?? null;
        $for = $tokens[$index + 2] ?? null;

        if (
            $value !== null && $value['type'] === self::TYPE_WORD && strtoupper($value['value']) === 'VALUE'
            && $for !== null && $for['type'] === self::TYPE_WORD && strtoupper($for['value']) === 'FOR'
        ) {
            throw new LocalizedException(
                __(
                    '`%1 VALUE FOR` reads or advances a database sequence and is not permitted in a '
                    . 'read-only query.',
                    $keyword
                )
            );
        }
    }

    // ------------------------------------------------- layer 4b: function allowlist

    /**
     * Every `name(` in the statement must name a built-in read-only function. Stored
     * functions and UDFs are refused whatever they are called, since a name-based deny-list
     * cannot know that `helper_totals()` is declared MODIFIES SQL DATA.
     *
     * @param list<SqlToken> $tokens
     * @return void
     * @throws LocalizedException
     */
    private function assertOnlyAllowedFunctions(array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if ($token['type'] !== self::TYPE_WORD) {
                continue;
            }

            $next = $tokens[$index + 1] ?? null;
            if ($next === null || $next['type'] !== self::TYPE_PUNCT || $next['value'] !== '(') {
                continue;
            }

            // A built-in can never be schema-qualified, so `db.count(1)` always resolves to a stored
            // function even though the word touching the `(` is allowlisted. The qualifier sits in a
            // non-table position and is not validated anywhere either, so the shape is refused whole.
            $previous = $tokens[$index - 1] ?? null;
            if ($previous !== null && $previous['type'] === self::TYPE_PUNCT && $previous['value'] === '.') {
                throw new LocalizedException(
                    __(
                        '`%1.%2()` is a schema-qualified function call. Only stored functions can be '
                        . 'qualified, and those are always refused; built-in functions never are.',
                        $tokens[$index - 2]['value'] ?? '',
                        $token['value']
                    )
                );
            }

            $name = strtoupper($token['value']);
            if (in_array($name, self::ALLOWED_FUNCTIONS, true)) {
                continue;
            }
            if (!$token['quoted'] && in_array($name, self::STRUCTURAL_KEYWORDS, true)) {
                continue;
            }

            throw new LocalizedException(
                __(
                    '`%1()` is not a permitted function. Only built-in read-only functions are accepted; '
                    . 'stored functions and user-defined functions are always refused.',
                    $token['value']
                )
            );
        }
    }

    // ------------------------------------------------------------------ layer 5: protected veto

    /**
     * The protected set, applied to every identifier in the statement rather than only to table
     * positions. Wider than strictly necessary — a column named `admin_user_id` is refused too —
     * and deliberately so: this is the layer that must keep holding if the positional scan in
     * {@see assertTablesAreAllowlisted()} ever misreads a construct.
     *
     * @param list<SqlToken> $tokens
     * @return void
     * @throws LocalizedException
     */
    private function assertNoProtectedIdentifiers(array $tokens): void
    {
        foreach ($this->identifierScanOrder($tokens) as $identifier) {
            $protected = $this->tablePolicy->findProtectedIdentifier($identifier);
            if ($protected !== null) {
                throw new LocalizedException(
                    __('The table `%1` is protected and cannot be queried.', $protected)
                );
            }
        }
    }

    /**
     * Identifier values in the order they are checked: for a qualified `schema.name`, the name
     * part comes first, so `information_schema.innodb_trx` is refused as `innodb_trx` — the view
     * that is actually the problem — rather than as its schema.
     *
     * @param list<SqlToken> $tokens
     * @return list<string>
     */
    private function identifierScanOrder(array $tokens): array
    {
        $identifiers = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if ($tokens[$index]['type'] !== self::TYPE_WORD) {
                continue;
            }

            $qualified = $this->readQualifier($tokens, $index);
            if ($qualified !== null) {
                $identifiers[] = $qualified;
                $identifiers[] = $tokens[$index]['value'];
                $index += 2;
                continue;
            }

            $identifiers[] = $tokens[$index]['value'];
        }

        return $identifiers;
    }

    /**
     * @param list<SqlToken> $tokens
     * @param int $index Index of the word that may be a schema qualifier.
     * @return string|null The qualified name following `<word>.`, or null when there is none.
     */
    private function readQualifier(array $tokens, int $index): ?string
    {
        $separator = $tokens[$index + 1] ?? null;
        $qualified = $tokens[$index + 2] ?? null;

        if (
            $separator !== null && $separator['type'] === self::TYPE_PUNCT && $separator['value'] === '.'
            && $qualified !== null && $qualified['type'] === self::TYPE_WORD
        ) {
            return $qualified['value'];
        }

        return null;
    }

    // ------------------------------------------------------------------ layer 5b: table allowlist

    /**
     * Requires every identifier in a **table position** to be allowlisted. Table positions are
     * the ones a table reference can occupy in the accepted grammar: after `FROM`, after any
     * `JOIN`, after a comma that continues a table list, after `IN` in a `SHOW` statement, as the
     * target of `DESCRIBE`/`DESC`/`EXPLAIN`, and inside parentheses that themselves stand in a
     * table position — MariaDB reads `FROM (t)` as a plain read of `t`, so the expectation travels
     * into the parentheses.
     *
     * Everything else is left alone, which is what keeps table aliases, derived-table aliases and
     * column aliases out of the allowlist. CTE names are exempt as well, but only where the server
     * agrees they are in scope — see {@see TableScanScope}: exempt within the defining scope only
     * (a `WITH` inside a derived table is scoped to that derived table), and for a plain `WITH`
     * only from the close of its own body, because inside that body the name still resolves to the
     * base table. Registration is armed exclusively by a positional `WITH` clause opener
     * ({@see opensCteList()}), so neither `GROUP BY … WITH ROLLUP` nor a `WINDOW <name> AS (…)`
     * clause can smuggle a name into the exempt set.
     *
     * @param non-empty-list<SqlToken> $tokens
     * @param string $leadingKeyword
     * @return void
     * @throws LocalizedException
     */
    private function assertTablesAreAllowlisted(array $tokens, string $leadingKeyword): void
    {
        $scopes = [new TableScanScope(false, false)];
        $isShow = $leadingKeyword === 'SHOW';
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            $scope = $scopes[count($scopes) - 1];

            if ($token['type'] === self::TYPE_PUNCT) {
                if ($token['value'] === '(') {
                    // A parenthesis standing in a table position is either a derived table or a
                    // parenthesised table reference — MariaDB reads `FROM (t)` as a read of `t` —
                    // so the expectation has to travel inside.
                    $scopes[] = new TableScanScope(
                        $scope->expectTable,
                        $scope->expectTable,
                        $this->opensFunctionArguments($tokens, $index)
                    );
                    $scope->expectTable = false;
                    continue;
                }
                if ($token['value'] === ')') {
                    if (count($scopes) > 1) {
                        array_pop($scopes);
                        // A CTE body just closed: its name (if deferred) comes into scope now.
                        $scopes[count($scopes) - 1]->closeCommonTableExpressionBody();
                    }
                    continue;
                }
                if ($token['value'] === ',') {
                    $scope->expectTable = $scope->inTableList;
                    if ($scope->continuesCteList) {
                        $scope->expectCteName = true;
                        $scope->continuesCteList = false;
                    }
                    continue;
                }
                $scope->expectTable = false;
                continue;
            }

            if ($token['type'] !== self::TYPE_WORD) {
                $scope->expectTable = false;
                continue;
            }

            $keyword = strtoupper($token['value']);

            if ($scope->expectTable) {
                if ($token['quoted'] || !$this->isNonTableWord($keyword, $tokens, $index, $leadingKeyword)) {
                    $index = $this->assertTableReferenceAllowed($tokens, $index, $scopes);
                    $scope->expectTable = false;
                    continue;
                }
                $scope->expectTable = false;
            }

            // A quoted CTE name is still a CTE name: what makes the exemption safe is the WITH
            // position, the scope and the registration timing, not the quoting.
            if ($scope->expectCteName && $this->definesCommonTableExpression($tokens, $index)) {
                if ($scope->recursiveCteList) {
                    $scope->defineCommonTableExpression($token['value']);
                } else {
                    $scope->deferCommonTableExpression($token['value']);
                }
                $scope->expectCteName = false;
                continue;
            }

            if ($token['quoted']) {
                continue;
            }

            switch ($keyword) {
                case 'WITH':
                    // Only a real CTE-list opener arms registration. `GROUP BY … WITH ROLLUP` is
                    // the same token in a completely different role.
                    if ($this->opensCteList($tokens, $index, $leadingKeyword)) {
                        $scope->expectCteName = true;
                        $scope->recursiveCteList = $this->isRecursiveCteList($tokens, $index);
                    }
                    break;
                case 'SELECT':
                    // The statement's own query begins: the WITH list, if any, is over.
                    $scope->endCteList();
                    $scope->inTableList = false;
                    break;
                case 'DESCRIBE':
                case 'DESC':
                case 'EXPLAIN':
                    if ($index === 0) {
                        $scope->expectTable = true;
                    }
                    break;
                case 'FROM':
                    // Inside a function's argument list `FROM` separates arguments — it is how
                    // `EXTRACT(YEAR FROM col)` and `TRIM(LEADING '0' FROM col)` are written — and
                    // names a column, never a table. A subquery there has its own parentheses, so
                    // it lands in a scope of its own and is still checked.
                    if (!$scope->inFunctionArguments) {
                        $scope->expectTable = true;
                        $scope->inTableList = true;
                    }
                    $scope->endCteList();
                    break;
                case 'JOIN':
                    $scope->expectTable = true;
                    $scope->inTableList = true;
                    break;
                case 'STRAIGHT_JOIN':
                    // Also a SELECT modifier (`SELECT STRAIGHT_JOIN col …`), where no table follows.
                    if (strtoupper($tokens[$index - 1]['value'] ?? '') !== 'SELECT') {
                        $scope->expectTable = true;
                        $scope->inTableList = true;
                    }
                    break;
                case 'IN':
                    // `SHOW COLUMNS IN <table>`; elsewhere `IN` introduces a value list, and inside
                    // function arguments it separates them (`POSITION('a' IN col)`).
                    $scope->expectTable = $isShow && !$scope->inFunctionArguments;
                    break;
                default:
                    if (in_array($keyword, self::TABLE_LIST_TERMINATORS, true)) {
                        $scope->inTableList = false;
                    }
            }
        }
    }

    /**
     * @param string $keyword
     * @param list<SqlToken> $tokens
     * @param int $index
     * @param string $leadingKeyword
     * @return bool
     */
    private function isNonTableWord(string $keyword, array $tokens, int $index, string $leadingKeyword): bool
    {
        if (in_array($keyword, self::NON_TABLE_WORDS, true)) {
            return true;
        }

        // `EXPLAIN FORMAT = JSON SELECT …` and nothing else: `format` is non-reserved, so anywhere
        // but right here it could be a real table name.
        return $keyword === 'FORMAT'
            && $leadingKeyword === 'EXPLAIN'
            && $index === 1
            && ($tokens[$index + 1]['value'] ?? '') === '=';
    }

    /**
     * Whether the `(` at $index opens a function call's argument list rather than a subquery, a
     * grouped expression or a parenthesised table reference. A word directly before `(` has already
     * been through layer 4b, so it is either an allowed built-in function or one of the structural
     * keywords — which makes "a non-structural word precedes it" an exact test for a function call.
     *
     * @param list<SqlToken> $tokens
     * @param int $index Index of the `(`.
     * @return bool
     */
    private function opensFunctionArguments(array $tokens, int $index): bool
    {
        $previous = $tokens[$index - 1] ?? null;

        if ($previous === null || $previous['type'] !== self::TYPE_WORD) {
            return false;
        }

        return $previous['quoted']
            || !in_array(strtoupper($previous['value']), self::STRUCTURAL_KEYWORDS, true);
    }

    /**
     * Whether the `WITH` at $index opens a CTE list. Positional on purpose: `WITH` also introduces
     * `GROUP BY … WITH ROLLUP`, and treating that as a CTE list let a following `WINDOW <name> AS
     * (…)` register `<name>` as an exempt phantom table. A CTE list can only start a statement, sit
     * right after `(`, or follow `EXPLAIN`; and it must be followed by `RECURSIVE` or by a real
     * `<name> AS (` definition.
     *
     * @param list<SqlToken> $tokens
     * @param int $index
     * @param string $leadingKeyword
     * @return bool
     */
    private function opensCteList(array $tokens, int $index, string $leadingKeyword): bool
    {
        $previous = $tokens[$index - 1] ?? null;
        $positional = $index === 0
            || ($previous !== null && $previous['type'] === self::TYPE_PUNCT && $previous['value'] === '(')
            || ($index === 1 && $leadingKeyword === 'EXPLAIN');

        if (!$positional) {
            return false;
        }

        $next = $tokens[$index + 1] ?? null;
        if ($next === null || $next['type'] !== self::TYPE_WORD) {
            return false;
        }
        if (!$next['quoted'] && strtoupper($next['value']) === 'ROLLUP') {
            return false;
        }

        return $this->isRecursiveCteList($tokens, $index)
            ? $this->definesCommonTableExpression($tokens, $index + 2)
            : $this->definesCommonTableExpression($tokens, $index + 1);
    }

    /**
     * @param list<SqlToken> $tokens
     * @param int $index Index of the `WITH` token.
     * @return bool
     */
    private function isRecursiveCteList(array $tokens, int $index): bool
    {
        $next = $tokens[$index + 1] ?? null;

        return $next !== null
            && $next['type'] === self::TYPE_WORD
            && !$next['quoted']
            && strtoupper($next['value']) === 'RECURSIVE';
    }

    /**
     * Whether the word at $index is a CTE name — `<name> AS (`. Only ever consulted where a CTE
     * name is expected (right after a `WITH` opener or a comma continuing its list), so a window
     * definition (`WINDOW w AS (…)`) can never be mistaken for one.
     *
     * @param list<SqlToken> $tokens
     * @param int $index
     * @return bool
     */
    private function definesCommonTableExpression(array $tokens, int $index): bool
    {
        $as = $tokens[$index + 1] ?? null;
        $body = $tokens[$index + 2] ?? null;

        return $as !== null && $as['type'] === self::TYPE_WORD && strtoupper($as['value']) === 'AS'
            && $body !== null && $body['type'] === self::TYPE_PUNCT && $body['value'] === '(';
    }

    /**
     * @param list<SqlToken> $tokens
     * @param int $index Index of the first word of the table reference.
     * @param non-empty-list<TableScanScope> $scopes Innermost scope last.
     * @return int Index of the last token consumed by the reference.
     * @throws LocalizedException
     */
    private function assertTableReferenceAllowed(array $tokens, int $index, array $scopes): int
    {
        $name = $tokens[$index]['value'];
        $schema = null;
        $qualified = $this->readQualifier($tokens, $index);

        if ($qualified !== null) {
            $schema = $name;
            $name = $qualified;
            $index += 2;
        }

        if ($schema !== null && !$this->tablePolicy->isCurrentDatabase($schema)) {
            throw new LocalizedException(
                __(
                    'The reference `%1.%2` names another database. Only tables in this store\'s own '
                    . 'database can be read.',
                    $schema,
                    $name
                )
            );
        }

        if ($schema === null) {
            foreach ($scopes as $scope) {
                if ($scope->hasCommonTableExpression($name)) {
                    return $index;
                }
            }
        }

        if (!$this->tablePolicy->isAllowed($name)) {
            throw new LocalizedException(
                __(
                    'The table `%1` is not in the Allowed Tables list, so it cannot be read. Add it under '
                    . 'Stores > Configuration > Magebit > MCP Server > MCP Database Reader > Allowed Tables '
                    . '(%2) if this tool should be able to read it.',
                    $name,
                    DbToolsConfig::XML_PATH_ALLOWED_TABLES
                )
            );
        }

        return $index;
    }

    // ------------------------------------------------------------------ layer 5c: SHOW forms

    /**
     * @param non-empty-list<SqlToken> $tokens
     * @return void
     * @throws LocalizedException
     */
    private function assertAllowedShowForm(array $tokens): void
    {
        $index = 1;
        while (
            ($tokens[$index] ?? null) !== null
            && $tokens[$index]['type'] === self::TYPE_WORD
            && !$tokens[$index]['quoted']
            && in_array(strtoupper($tokens[$index]['value']), self::SHOW_FORM_MODIFIERS, true)
        ) {
            $index++;
        }

        $form = $tokens[$index] ?? null;
        $name = $form !== null && $form['type'] === self::TYPE_WORD && !$form['quoted']
            ? strtoupper($form['value'])
            : '';

        if (!in_array($name, self::ALLOWED_SHOW_FORMS, true)) {
            throw new LocalizedException(
                __(
                    '`SHOW %1` is not accepted, because it can expose tables that are not in the Allowed '
                    . 'Tables list. Accepted forms are: %2.',
                    $form['value'] ?? '',
                    implode(', ', self::ALLOWED_SHOW_FORMS)
                )
            );
        }
    }

    // ------------------------------------------------------------------ layer 6: column deny-list

    /**
     * @param list<SqlToken> $tokens
     * @return void
     * @throws LocalizedException
     */
    private function assertNoDeniedColumns(array $tokens): void
    {
        foreach ($tokens as $token) {
            if ($token['type'] !== self::TYPE_WORD) {
                continue;
            }

            $denied = $this->columnDenyList->findDeniedColumn($token['value']);
            if ($denied !== null) {
                throw new LocalizedException(
                    __(
                        'The column `%1` is protected and cannot be referenced. '
                        . 'Select only the columns you actually need.',
                        $denied
                    )
                );
            }
        }
    }

    // ------------------------------------------------------------------ layer 7: wildcards

    /**
     * @param list<SqlToken> $tokens
     * @return void
     * @throws LocalizedException
     */
    private function assertNoWildcard(array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if ($token['type'] !== self::TYPE_PUNCT || $token['value'] !== '*') {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;
            $isAggregate = $previous !== null && $previous['value'] === '('
                && $next !== null && $next['value'] === ')';

            if (!$isAggregate) {
                throw new LocalizedException(
                    __(
                        '`*` is not permitted — list the columns you need explicitly, so that protected '
                        . 'columns cannot be returned by accident. `COUNT(*)` is the only accepted use.'
                    )
                );
            }
        }
    }

    // ------------------------------------------------------------------ layer 8: row limit

    /**
     * @param string $sql
     * @param list<SqlToken> $tokens
     * @param int|null $limit
     * @return string
     * @throws LocalizedException
     */
    private function applyRowLimit(string $sql, array $tokens, ?int $limit): string
    {
        $ceiling = max(1, $this->config->getRowLimitCeiling());
        $limitIndex = $this->findLimitIndex($tokens);

        if ($limitIndex !== null) {
            $rows = $this->readTrailingLimit($tokens, $limitIndex);
            if ($rows === null) {
                throw new LocalizedException(
                    __(
                        'LIMIT must be a literal integer and must be the final clause of the query '
                        . '(`LIMIT n`, `LIMIT offset, n` or `LIMIT n OFFSET offset`).'
                    )
                );
            }
            if ($rows > $ceiling) {
                throw new LocalizedException(
                    __('LIMIT %1 exceeds the configured maximum of %2 rows. Request %2 rows or fewer.', $rows, $ceiling)
                );
            }

            return $sql;
        }

        if ($limit !== null) {
            if ($limit < 1) {
                throw new LocalizedException(__('The row limit must be at least 1.'));
            }
            if ($limit > $ceiling) {
                throw new LocalizedException(
                    __('A limit of %1 exceeds the configured maximum of %2 rows.', $limit, $ceiling)
                );
            }
            $rows = $limit;
        } else {
            $configured = $this->config->getDefaultRows();
            $rows = min($configured > 0 ? $configured : self::FALLBACK_ROWS, $ceiling);
        }

        return $sql . ' LIMIT ' . $rows;
    }

    /**
     * @param list<SqlToken> $tokens
     * @return int|null Index of the single LIMIT keyword, or null when there is none.
     * @throws LocalizedException When LIMIT appears more than once.
     */
    private function findLimitIndex(array $tokens): ?int
    {
        $found = null;
        foreach ($tokens as $index => $token) {
            if ($token['type'] === self::TYPE_WORD && strtoupper($token['value']) === 'LIMIT') {
                if ($found !== null) {
                    throw new LocalizedException(
                        __('LIMIT may appear only once, as the final clause of the query.')
                    );
                }
                $found = $index;
            }
        }

        return $found;
    }

    /**
     * Row count of a trailing LIMIT clause: the second number of `LIMIT offset, n`, else the first.
     *
     * @param list<SqlToken> $tokens
     * @param int $limitIndex
     * @return int|null Null when the tail is not a well-formed trailing LIMIT clause.
     */
    private function readTrailingLimit(array $tokens, int $limitIndex): ?int
    {
        $tail = array_slice($tokens, $limitIndex + 1);
        $rows = $tail[0] ?? null;
        $separator = $tail[1] ?? null;
        $second = $tail[2] ?? null;

        if ($rows === null || $rows['type'] !== self::TYPE_NUMBER || count($tail) > 3) {
            return null;
        }
        if ($separator === null) {
            return (int) $rows['value'];
        }
        if ($second === null || $second['type'] !== self::TYPE_NUMBER) {
            return null;
        }
        if ($separator['type'] === self::TYPE_PUNCT && $separator['value'] === ',') {
            return (int) $second['value'];
        }
        if ($separator['type'] === self::TYPE_WORD && strtoupper($separator['value']) === 'OFFSET') {
            return (int) $rows['value'];
        }

        return null;
    }
}
