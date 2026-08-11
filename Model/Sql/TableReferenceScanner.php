<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model\Sql;

use Magebit\McpDbTools\Model\TableScanScope;

/**
 * Reads the identifiers that sit in a table position — after `FROM`/`JOIN`, table-list commas,
 * `SHOW … IN`, `DESCRIBE`/`EXPLAIN` targets, travelling into parentheses. Aliases are untouched and
 * CTE names are dropped per {@see TableScanScope}. Positional only: it makes no policy decision.
 *
 * @phpstan-import-type SqlToken from Lexer
 * @phpstan-type TableReference array{schema: string|null, name: string}
 */
class TableReferenceScanner
{
    private const TABLE_LIST_TERMINATORS = [
        'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET', 'WINDOW', 'UNION', 'EXCEPT', 'INTERSECT',
        'INTO', 'PROCEDURE', 'FOR', 'SET', 'LIKE',
    ];

    private const NON_TABLE_WORDS = ['SELECT', 'WITH', 'DUAL'];

    /**
     * Words that are grammar rather than a name. Shared with the function allowlist on purpose:
     * {@see opensFunctionArguments()} is only exact because both layers read the same set.
     */
    public const STRUCTURAL_KEYWORDS = [
        'SELECT', 'FROM', 'WHERE', 'HAVING', 'GROUP', 'ORDER', 'BY', 'AND', 'OR', 'XOR', 'NOT', 'IN',
        'EXISTS', 'AS', 'ON', 'USING', 'JOIN', 'INNER', 'CROSS', 'NATURAL', 'STRAIGHT_JOIN',
        'UNION', 'EXCEPT', 'INTERSECT', 'ALL', 'SOME', 'DISTINCT', 'CASE', 'WHEN', 'THEN', 'ELSE',
        'IS', 'LIKE', 'RLIKE', 'REGEXP', 'BETWEEN', 'OVER', 'PARTITION', 'MATCH', 'INDEX',
        'KEY', 'INTERVAL', 'ROW', 'VALUES', 'WITH', 'RECURSIVE',
    ];

    /**
     * Names of the tables the statement reads, in the order they are written.
     *
     * @param list<SqlToken> $tokens
     * @param string $leadingKeyword
     * @return list<string>
     */
    public function extractTables(array $tokens, string $leadingKeyword): array
    {
        $names = [];

        foreach ($this->extractReferences($tokens, $leadingKeyword) as $reference) {
            $names[] = $reference['name'];
        }

        return $names;
    }

    /**
     * As {@see extractTables()}, but keeping each reference's schema qualifier, which the caller
     * needs to tell a cross-database reference from a local one.
     *
     * @param list<SqlToken> $tokens
     * @param string $leadingKeyword
     * @return list<TableReference>
     */
    public function extractReferences(array $tokens, string $leadingKeyword): array
    {
        $scopes = [new TableScanScope(false, false)];
        $references = [];
        $isShow = $leadingKeyword === 'SHOW';
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            $scope = $scopes[count($scopes) - 1];

            if ($token['type'] === Lexer::TYPE_PUNCT) {
                if ($token['value'] === '(') {
                    // MariaDB reads `FROM (t)` as a read of `t`, so a `(` in a table position is a
                    // derived table *or* a table reference — the expectation must travel inside.
                    $scopes[] = new TableScanScope(
                        $scope->expectsTable(),
                        $scope->expectsTable(),
                        $this->opensFunctionArguments($tokens, $index)
                    );
                    $scope->clearTableExpectation();
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
                    $scope->sawComma();
                    continue;
                }
                $scope->clearTableExpectation();
                continue;
            }

            if ($token['type'] !== Lexer::TYPE_WORD) {
                $scope->clearTableExpectation();
                continue;
            }

            $keyword = strtoupper($token['value']);

            if ($scope->expectsTable()) {
                if ($token['quoted'] || !$this->isNonTableWord($keyword, $tokens, $index, $leadingKeyword)) {
                    $read = $this->readTableReference($tokens, $index, $scopes);
                    if ($read['reference'] !== null) {
                        $references[] = $read['reference'];
                    }
                    $index = $read['next'];
                    $scope->clearTableExpectation();
                    continue;
                }
                $scope->clearTableExpectation();
            }

            // A quoted CTE name is still a CTE name: what makes the exemption safe is the WITH
            // position, the scope and the registration timing, not the quoting.
            if ($scope->expectsCteName() && $this->definesCommonTableExpression($tokens, $index)) {
                if ($scope->isRecursiveCteList()) {
                    $scope->defineCommonTableExpression($token['value']);
                } else {
                    $scope->deferCommonTableExpression($token['value']);
                }
                $scope->clearCteNameExpectation();
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
                        $scope->beginCteList($this->isRecursiveCteList($tokens, $index));
                    }
                    break;
                case 'SELECT':
                    // The statement's own query begins: the WITH list, if any, is over.
                    $scope->endCteList();
                    $scope->leaveTableList();
                    break;
                case 'DESCRIBE':
                case 'DESC':
                case 'EXPLAIN':
                    if ($index === 0) {
                        $scope->expectTable();
                    }
                    break;
                case 'FROM':
                    // In a function's argument list `FROM` separates arguments (`EXTRACT(YEAR FROM
                    // col)`) and names a column. A subquery there gets its own scope, so it is checked.
                    if (!$scope->isInFunctionArguments()) {
                        $scope->enterTableList();
                    }
                    $scope->endCteList();
                    break;
                case 'JOIN':
                    $scope->enterTableList();
                    break;
                case 'STRAIGHT_JOIN':
                    // Also a SELECT modifier (`SELECT STRAIGHT_JOIN col …`), where no table follows.
                    if (strtoupper($tokens[$index - 1]['value'] ?? '') !== 'SELECT') {
                        $scope->enterTableList();
                    }
                    break;
                case 'IN':
                    // `SHOW COLUMNS IN <table>`; elsewhere `IN` introduces a value list, and inside
                    // function arguments it separates them (`POSITION('a' IN col)`).
                    $scope->expectShowTarget($isShow);
                    break;
                default:
                    if (in_array($keyword, self::TABLE_LIST_TERMINATORS, true)) {
                        $scope->leaveTableList();
                    }
            }
        }

        return $references;
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
     * Whether the `(` at $index opens a function call's argument list rather than a subquery,
     * grouped expression or parenthesised table reference. A word directly before `(` has already
     * passed the function allowlist, so "a non-structural word precedes it" is an exact test.
     *
     * @param list<SqlToken> $tokens
     * @param int $index Index of the `(`.
     * @return bool
     */
    private function opensFunctionArguments(array $tokens, int $index): bool
    {
        $previous = $tokens[$index - 1] ?? null;

        if ($previous === null || $previous['type'] !== Lexer::TYPE_WORD) {
            return false;
        }

        return $previous['quoted']
            || !in_array(strtoupper($previous['value']), self::STRUCTURAL_KEYWORDS, true);
    }

    /**
     * Whether the `WITH` at $index opens a CTE list. Positional on purpose: `WITH` also appears in
     * `GROUP BY … WITH ROLLUP`, and treating that as a CTE list let a following `WINDOW <name> AS
     * (…)` register an exempt phantom table. Valid only at statement start, after `(`, or after `EXPLAIN`.
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
            || ($previous !== null && $previous['type'] === Lexer::TYPE_PUNCT && $previous['value'] === '(')
            || ($index === 1 && $leadingKeyword === 'EXPLAIN');

        if (!$positional) {
            return false;
        }

        $next = $tokens[$index + 1] ?? null;
        if ($next === null || $next['type'] !== Lexer::TYPE_WORD) {
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
            && $next['type'] === Lexer::TYPE_WORD
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

        return $as !== null && $as['type'] === Lexer::TYPE_WORD && strtoupper($as['value']) === 'AS'
            && $body !== null && $body['type'] === Lexer::TYPE_PUNCT && $body['value'] === '(';
    }

    /**
     * @param list<SqlToken> $tokens
     * @param int $index Index of the first word of the table reference.
     * @param non-empty-list<TableScanScope> $scopes Innermost scope last.
     * @return array{reference: TableReference|null, next: int} Null reference for a CTE in scope;
     *         `next` is the index of the last token the reference consumed.
     */
    private function readTableReference(array $tokens, int $index, array $scopes): array
    {
        $name = $tokens[$index]['value'];
        $schema = null;
        $qualified = $this->readQualifier($tokens, $index);

        if ($qualified !== null) {
            $schema = $name;
            $name = $qualified;
            $index += 2;
        }

        if ($schema === null) {
            foreach ($scopes as $scope) {
                if ($scope->hasCommonTableExpression($name)) {
                    return ['reference' => null, 'next' => $index];
                }
            }
        }

        return ['reference' => ['schema' => $schema, 'name' => $name], 'next' => $index];
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
}
