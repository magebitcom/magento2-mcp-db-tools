<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model;

/**
 * Bookkeeping for one parenthesis level of {@see QueryGuard::assertTablesAreAllowlisted()}.
 *
 * One instance per nesting level, pushed on `(` and popped on `)`. CTE names live on the scope
 * that defined them rather than on the statement, because a `WITH` inside a derived table is
 * scoped to that derived table: a statement-wide list would let a nested `WITH x AS (…)` exempt a
 * reference to the real table `x` sitting outside that derived table.
 */
class TableScanScope
{
    /**
     * Names defined by this scope's `WITH` list, lower-cased.
     *
     * @var array<string, true>
     */
    private array $commonTableExpressions = [];

    /**
     * @param bool $expectTable Whether the next identifier here is a table reference.
     * @param bool $inTableList Whether a comma at this level continues a `FROM`/`JOIN` table list.
     * @param bool $inWithList Whether this level is inside a `WITH` clause's definition list.
     */
    public function __construct(
        public bool $expectTable,
        public bool $inTableList,
        public bool $inWithList = false
    ) {
    }

    /**
     * @param string $name
     * @return void
     */
    public function defineCommonTableExpression(string $name): void
    {
        $this->commonTableExpressions[strtolower($name)] = true;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasCommonTableExpression(string $name): bool
    {
        return isset($this->commonTableExpressions[strtolower($name)]);
    }
}
