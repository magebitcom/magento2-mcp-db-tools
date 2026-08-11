<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model;

use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magebit\McpDbTools\Model\Sql\Lexer;
use Magebit\McpDbTools\Model\Sql\TableReferenceScanner;
use Magento\Framework\Exception\LocalizedException;

/**
 * Validates that an operator-supplied statement is a single, read-only, bounded query and
 * returns the exact SQL the caller must execute — so what was validated is what runs.
 *
 * @phpstan-import-type SqlToken from Lexer
 */
class QueryGuard
{
    /**
     * Row cap used if `default_rows` is missing or non-positive.
     */
    public const FALLBACK_ROWS = 100;

    private const ALLOWED_LEADING_KEYWORDS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH'];

    /**
     * Statements that return metadata rather than table rows: exempt from layers 7 and 8.
     */
    private const METADATA_KEYWORDS = ['SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];

    private const DENIED_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'RENAME',
        'GRANT', 'GRANTS', 'REVOKE', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'LOCK', 'UNLOCK', 'SHARE', 'CALL',
        'DO', 'HANDLER', 'LOAD', 'PREPARE', 'EXECUTE', 'DEALLOCATE', 'SET', 'FLUSH', 'RESET', 'SHUTDOWN',
        'KILL', 'INSTALL', 'UNINSTALL', 'BINLOG', 'PURGE', 'OPTIMIZE', 'REPAIR', 'ANALYZE', 'ANALYSE',
        'CHECKSUM', 'PROCEDURE', 'INTO', 'OUTFILE', 'DUMPFILE', 'LOAD_FILE', 'SLEEP', 'BENCHMARK',
        'TABLE', 'VALUES',
        'GET_LOCK', 'RELEASE_LOCK', 'IS_USED_LOCK', 'IS_FREE_LOCK', 'SYS_EXEC', 'SYS_EVAL',
        'GROUP_CONCAT', 'JSON_ARRAYAGG', 'JSON_OBJECTAGG',
        'MASTER_POS_WAIT', 'SOURCE_POS_WAIT', 'WAIT_FOR_EXECUTED_GTID_SET',
        'WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS',
        // Parenless server-info keywords: the function allowlist only inspects words followed by
        // `(`, so these leak the connecting account unless denied outright.
        'CURRENT_USER', 'CURRENT_ROLE', 'SESSION_USER', 'SYSTEM_USER',
    ];

    /**
     * Read-only built-in functions a diagnostic query realistically needs.
     */
    private const ALLOWED_FUNCTIONS = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'STD', 'STDDEV', 'STDDEV_POP', 'STDDEV_SAMP', 'VARIANCE',
        'VAR_POP', 'VAR_SAMP', 'BIT_AND', 'BIT_OR', 'BIT_XOR', 'ANY_VALUE', 'ROW_NUMBER', 'RANK',
        'DENSE_RANK', 'PERCENT_RANK', 'CUME_DIST', 'NTILE', 'LAG', 'LEAD', 'FIRST_VALUE', 'LAST_VALUE',
        'NTH_VALUE',
        'CONCAT', 'CONCAT_WS', 'SUBSTRING', 'SUBSTR', 'MID', 'LEFT', 'RIGHT', 'LENGTH', 'CHAR_LENGTH',
        'CHARACTER_LENGTH', 'OCTET_LENGTH', 'LOWER', 'UPPER', 'LCASE', 'UCASE', 'TRIM', 'LTRIM', 'RTRIM',
        'LPAD', 'RPAD', 'REVERSE', 'REPEAT', 'INSTR', 'LOCATE', 'POSITION', 'FIELD', 'FIND_IN_SET', 'ELT',
        'HEX', 'UNHEX', 'ASCII', 'ORD', 'BIN', 'OCT', 'SPACE', 'STRCMP', 'SUBSTRING_INDEX', 'QUOTE',
        'FORMAT', 'REGEXP_LIKE', 'REGEXP_INSTR', 'REGEXP_SUBSTR', 'REGEXP_REPLACE', 'TO_BASE64',
        'FROM_BASE64', 'MD5', 'SHA1', 'SHA2', 'UUID', 'CRC32', 'BIT_COUNT', 'INET_ATON', 'INET_NTOA',
        'ABS', 'CEIL', 'CEILING', 'FLOOR', 'ROUND', 'MOD', 'POW', 'POWER', 'SQRT', 'EXP', 'LOG', 'LOG2',
        'LOG10', 'LN', 'SIGN', 'RAND', 'PI', 'GREATEST', 'LEAST', 'DEGREES', 'RADIANS', 'SIN', 'COS',
        'TAN', 'COT', 'ASIN', 'ACOS', 'ATAN', 'ATAN2',
        'NOW', 'CURDATE', 'CURTIME', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'LOCALTIME',
        'LOCALTIMESTAMP', 'UNIX_TIMESTAMP', 'FROM_UNIXTIME', 'DATE', 'TIME', 'TIMESTAMP', 'YEAR', 'MONTH',
        'DAY', 'DAYOFMONTH', 'DAYOFWEEK', 'DAYOFYEAR', 'WEEK', 'WEEKDAY', 'WEEKOFYEAR', 'HOUR', 'MINUTE',
        'SECOND', 'MICROSECOND', 'QUARTER', 'MONTHNAME', 'DAYNAME', 'LAST_DAY', 'DATE_ADD', 'DATE_SUB',
        'DATE_FORMAT', 'STR_TO_DATE', 'DATEDIFF', 'TIMEDIFF', 'TIMESTAMPDIFF', 'TIMESTAMPADD', 'ADDDATE',
        'SUBDATE', 'ADDTIME', 'SUBTIME', 'MAKEDATE', 'MAKETIME', 'PERIOD_ADD', 'PERIOD_DIFF',
        'SEC_TO_TIME', 'TIME_TO_SEC', 'TIME_FORMAT', 'UTC_DATE', 'UTC_TIME', 'UTC_TIMESTAMP', 'YEARWEEK',
        'EXTRACT', 'CONVERT_TZ',
        'CAST', 'CONVERT', 'IF', 'IFNULL', 'NULLIF', 'COALESCE', 'ISNULL', 'CHAR', 'NCHAR', 'BINARY',
        'VARBINARY', 'VARCHAR', 'DECIMAL', 'SIGNED', 'UNSIGNED', 'INTEGER', 'DATETIME', 'FLOAT', 'DOUBLE',
        'JSON',
        'JSON_EXTRACT', 'JSON_UNQUOTE', 'JSON_VALUE', 'JSON_CONTAINS', 'JSON_CONTAINS_PATH', 'JSON_KEYS',
        'JSON_LENGTH', 'JSON_DEPTH', 'JSON_TYPE', 'JSON_VALID', 'JSON_QUOTE', 'JSON_OVERLAPS',
        'JSON_SEARCH', 'JSON_ARRAY', 'JSON_OBJECT',
        'DATABASE', 'VERSION',
    ];

    private const ALLOWED_SHOW_FORMS = [
        'TABLES', 'COLUMNS', 'FIELDS', 'INDEX', 'INDEXES', 'KEYS', 'DATABASES', 'SCHEMAS', 'ENGINES',
        'CHARACTER', 'CHARSET', 'COLLATION', 'VARIABLES', 'STATUS', 'WARNINGS', 'ERRORS',
    ];

    private const SHOW_FORM_MODIFIERS = ['FULL', 'EXTENDED', 'GLOBAL', 'SESSION'];

    /**
     * @param DbToolsConfig $config
     * @param TableAccessPolicy $tablePolicy
     * @param ColumnDenyList $columnDenyList
     * @param Lexer $lexer
     * @param TableReferenceScanner $tableScanner
     */
    public function __construct(
        private readonly DbToolsConfig $config,
        private readonly TableAccessPolicy $tablePolicy,
        private readonly ColumnDenyList $columnDenyList,
        private readonly Lexer $lexer,
        private readonly TableReferenceScanner $tableScanner
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
        $scan = $this->lexer->tokenize($sql);
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
            $tokens = $this->lexer->tokenize($sql)['tokens'];
            $index = $this->findLimitIndex($tokens);

            return $index === null ? null : $this->readTrailingLimit($tokens, $index);
        } catch (LocalizedException) {
            return null;
        }
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
            if ($token['type'] === Lexer::TYPE_PUNCT && $token['value'] === ';' && $index !== $lastIndex) {
                throw new LocalizedException(
                    __('Only a single statement is accepted. Remove everything after the first `;`.')
                );
            }
        }

        $last = $tokens[$lastIndex] ?? null;
        if ($last !== null && $last['type'] === Lexer::TYPE_PUNCT && $last['value'] === ';') {
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

        if ($first['type'] !== Lexer::TYPE_WORD || !in_array($keyword, self::ALLOWED_LEADING_KEYWORDS, true)) {
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
            if ($token['type'] === Lexer::TYPE_PUNCT && $token['value'] === '@') {
                throw new LocalizedException(
                    __(
                        'Session and system variables (`@name`, `@@name`) are not permitted — '
                        . 'this also refuses `SELECT … INTO @var` and variable assignment.'
                    )
                );
            }
        }

        foreach ($tokens as $index => $token) {
            if ($token['type'] !== Lexer::TYPE_WORD) {
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
     * `NEXT VALUE FOR seq` advances a sequence — a write — and its operand never reaches a table
     * position, so the sequence is not allowlisted either. Matched as a three-token phrase because
     * the bare word `VALUE` names a column on every Magento EAV value table and cannot be denied.
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

        if ($value !== null && $value['type'] === Lexer::TYPE_WORD && strtoupper($value['value']) === 'VALUE'
            && $for !== null && $for['type'] === Lexer::TYPE_WORD && strtoupper($for['value']) === 'FOR'
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
            if ($token['type'] !== Lexer::TYPE_WORD) {
                continue;
            }

            $next = $tokens[$index + 1] ?? null;
            if ($next === null || $next['type'] !== Lexer::TYPE_PUNCT || $next['value'] !== '(') {
                continue;
            }

            // A built-in is never schema-qualified, so `db.count(1)` is always a stored function even
            // when the word touching the `(` is allowlisted — and the qualifier is validated nowhere.
            $previous = $tokens[$index - 1] ?? null;
            if ($previous !== null && $previous['type'] === Lexer::TYPE_PUNCT && $previous['value'] === '.') {
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
            if (!$token['quoted'] && in_array($name, TableReferenceScanner::STRUCTURAL_KEYWORDS, true)) {
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
     * Applied to every identifier, not only table positions. Deliberately over-wide: this layer must
     * still hold if {@see assertTablesAreAllowlisted()} ever misreads a construct.
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
            if ($tokens[$index]['type'] !== Lexer::TYPE_WORD) {
                continue;
            }

            $qualified = $this->readQualifier($tokens, $index);
            if ($qualified !== null) {
                $this->collectIdentifier($identifiers, $qualified);
                $this->collectIdentifier($identifiers, $tokens[$index]['value']);
                $index += 2;
                continue;
            }

            $this->collectIdentifier($identifiers, $tokens[$index]['value']);
        }

        return $identifiers;
    }

    /**
     * A quoted identifier holding a backtick is inert once reconstructed, but this layer is the
     * backstop for a misread construct, so its glued-together segments are vetoed as names too.
     *
     * @param list<string> $identifiers
     * @param string $value
     * @return void
     */
    private function collectIdentifier(array &$identifiers, string $value): void
    {
        $identifiers[] = $value;

        if (!str_contains($value, '`')) {
            return;
        }

        foreach (explode('`', $value) as $segment) {
            $segment = trim($segment);
            if ($segment !== '') {
                $identifiers[] = $segment;
            }
        }
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

        if ($separator !== null && $separator['type'] === Lexer::TYPE_PUNCT && $separator['value'] === '.'
            && $qualified !== null && $qualified['type'] === Lexer::TYPE_WORD
        ) {
            return $qualified['value'];
        }

        return null;
    }

    // ------------------------------------------------------------------ layer 5b: table allowlist

    /**
     * Every identifier the scanner reads as a table reference must be allowlisted and must live in
     * this store's own database; aliases and in-scope CTE names never reach here.
     *
     * @param non-empty-list<SqlToken> $tokens
     * @param string $leadingKeyword
     * @return void
     * @throws LocalizedException
     */
    private function assertTablesAreAllowlisted(array $tokens, string $leadingKeyword): void
    {
        foreach ($this->tableScanner->extractReferences($tokens, $leadingKeyword) as $reference) {
            $this->assertTableReferenceAllowed($reference['schema'], $reference['name']);
        }
    }

    /**
     * @param string|null $schema
     * @param string $name
     * @return void
     * @throws LocalizedException
     */
    private function assertTableReferenceAllowed(?string $schema, string $name): void
    {
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

        if (!$this->tablePolicy->isAllowed($name)) {
            throw new LocalizedException(
                __(
                    'The table `%1` is not in the Allowed Tables list, so it cannot be read. Add it under '
                    . 'Stores > Configuration > Magebit > MCP Server - Database Reader > MCP Database Reader '
                    . '> Allowed Tables (%2) if this tool should be able to read it.',
                    $name,
                    DbToolsConfig::XML_PATH_ALLOWED_TABLES
                )
            );
        }
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
        while (($tokens[$index] ?? null) !== null
            && $tokens[$index]['type'] === Lexer::TYPE_WORD
            && !$tokens[$index]['quoted']
            && in_array(strtoupper($tokens[$index]['value']), self::SHOW_FORM_MODIFIERS, true)
        ) {
            $index++;
        }

        $form = $tokens[$index] ?? null;
        $name = $form !== null && $form['type'] === Lexer::TYPE_WORD && !$form['quoted']
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
            if ($token['type'] !== Lexer::TYPE_WORD) {
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
            if ($token['type'] !== Lexer::TYPE_PUNCT || $token['value'] !== '*') {
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
            if ($token['type'] === Lexer::TYPE_WORD && strtoupper($token['value']) === 'LIMIT') {
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

        if ($rows === null || $rows['type'] !== Lexer::TYPE_NUMBER || count($tail) > 3) {
            return null;
        }
        if ($separator === null) {
            return (int) $rows['value'];
        }
        if ($second === null || $second['type'] !== Lexer::TYPE_NUMBER) {
            return null;
        }
        if ($separator['type'] === Lexer::TYPE_PUNCT && $separator['value'] === ',') {
            return (int) $second['value'];
        }
        if ($separator['type'] === Lexer::TYPE_WORD && strtoupper($separator['value']) === 'OFFSET') {
            return (int) $rows['value'];
        }

        return null;
    }
}
