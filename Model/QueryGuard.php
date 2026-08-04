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
 * Eight layers, in order, each with its own rejection message:
 *
 * 1. **Lexing + comment stripping.** The statement is scanned byte by byte into tokens
 *    (words, string literals, numbers, punctuation) while comments are replaced by a single
 *    space and whitespace runs collapsed. Analysis happens on the token stream, never on the
 *    raw text, so a keyword inside a string literal cannot trip a scanner and a `--` inside a
 *    literal cannot truncate one. MySQL executable comments (`/*!`, `/*+`) are rejected
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
 * 5. **Table deny-list** — see {@see DenyList}. Scanned over every identifier in the
 *    statement, including subqueries and joins; no clause-aware parsing is attempted.
 * 6. **Column deny-list** — same scan, secret-bearing column names.
 * 7. **No wildcards.** `SELECT *` / `t.*` would return secret columns that layer 6 cannot
 *    see, so an explicit column list is required. `COUNT(*)` is the one accepted `*` shape.
 * 8. **Row limit.** SELECT/WITH get `LIMIT <default>` appended when they carry none; a
 *    caller LIMIT above the configured ceiling is rejected rather than silently clamped,
 *    because clamping would let a client believe it had seen every matching row.
 *
 * Layers 7 and 8 are skipped for SHOW/DESCRIBE/DESC/EXPLAIN, which return no table rows.
 *
 * @phpstan-type SqlToken array{type: string, value: string}
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
        'GRANT', 'GRANTS', 'REVOKE', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'LOCK', 'UNLOCK', 'CALL', 'DO',
        'HANDLER', 'LOAD', 'PREPARE', 'EXECUTE', 'DEALLOCATE', 'SET', 'FLUSH', 'RESET', 'SHUTDOWN', 'KILL',
        'INSTALL', 'UNINSTALL', 'BINLOG', 'PURGE', 'OPTIMIZE', 'REPAIR', 'ANALYZE', 'ANALYSE', 'CHECKSUM',
        'PROCEDURE', 'INTO', 'OUTFILE', 'DUMPFILE', 'LOAD_FILE', 'SLEEP', 'BENCHMARK', 'GET_LOCK',
        'RELEASE_LOCK', 'IS_USED_LOCK', 'IS_FREE_LOCK', 'MASTER_POS_WAIT', 'SYS_EXEC', 'SYS_EVAL',
    ];

    /**
     * @param DbToolsConfig $config
     * @param DenyList $denyList
     */
    public function __construct(
        private readonly DbToolsConfig $config,
        private readonly DenyList $denyList
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
        $this->assertNoDeniedTables($tokens);
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
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $sql) === 1) {
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

            if ($char === "'") {
                $literal = $this->readStringLiteral($sql, $position);
                $output .= $literal['raw'];
                $tokens[] = ['type' => self::TYPE_STRING, 'value' => $literal['raw']];
                $position = $literal['next'];
                continue;
            }

            if ($char === '`') {
                $identifier = $this->readQuotedIdentifier($sql, $position);
                $output .= $identifier['raw'];
                $tokens[] = ['type' => self::TYPE_WORD, 'value' => $identifier['value']];
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
                ];
                continue;
            }

            $output .= $char;
            $tokens[] = ['type' => self::TYPE_PUNCT, 'value' => $char];
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
     * Reads a `'…'` literal, honouring `\'` escapes and `''` doubling.
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
                if ($position + 1 >= $length) {
                    break;
                }
                $raw .= $char . $sql[$position + 1];
                $position += 2;
                continue;
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

            if (in_array($keyword, self::DENIED_KEYWORDS, true)) {
                throw new LocalizedException(
                    __('The keyword `%1` is not permitted in a read-only query.', $keyword)
                );
            }
        }
    }

    // ------------------------------------------------------------------ layers 5 and 6: identifiers

    /**
     * @param list<SqlToken> $tokens
     * @return void
     * @throws LocalizedException
     */
    private function assertNoDeniedTables(array $tokens): void
    {
        foreach ($tokens as $token) {
            if ($token['type'] !== self::TYPE_WORD) {
                continue;
            }

            $denied = $this->denyList->findDeniedTable($token['value']);
            if ($denied !== null) {
                throw new LocalizedException(
                    __('The table `%1` is protected and cannot be queried.', $denied)
                );
            }
        }
    }

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

            $denied = $this->denyList->findDeniedColumn($token['value']);
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
