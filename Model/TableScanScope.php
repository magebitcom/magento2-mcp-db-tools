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
 *
 * Registration *timing* matters as much as scoping. Inside a **plain** `WITH`'s own body the name
 * still resolves to the base table on MariaDB/MySQL, so a plain CTE is only brought into scope once
 * its body closes ({@see deferCommonTableExpression()}) — otherwise a self-reference would inherit
 * the exemption and skip the allowlist. `WITH RECURSIVE` is the one form where the name must be
 * visible inside the body ({@see defineCommonTableExpression()}): there a self-reference *is* the
 * CTE and can never fall through to a base table, because the server refuses an unanchored
 * recursive element outright (`ERROR 4005`).
 */
class TableScanScope
{
    /**
     * Names in scope at this level, lower-cased.
     *
     * @var array<string, true>
     */
    private array $commonTableExpressions = [];

    /**
     * Name that comes into scope when the body currently being scanned closes.
     */
    private ?string $pendingCommonTableExpression = null;

    /**
     * Whether a CTE body opened at this level is still being scanned.
     */
    private bool $insideCommonTableExpressionBody = false;

    /**
     * Whether the next identifier here is a CTE name. Armed by a `WITH` clause opener and by a
     * comma continuing its list — by nothing else, so no other construct can register a name.
     */
    public bool $expectCteName = false;

    /**
     * Whether this level's `WITH` list was written `WITH RECURSIVE`.
     */
    public bool $recursiveCteList = false;

    /**
     * Whether a comma here continues the `WITH` list with another definition.
     */
    public bool $continuesCteList = false;

    /**
     * @param bool $expectTable Whether the next identifier here is a table reference.
     * @param bool $inTableList Whether a comma at this level continues a `FROM`/`JOIN` table list.
     * @param bool $inFunctionArguments Whether this level is a function call's argument list, where
     *        `FROM` and `IN` are argument separators (`EXTRACT(YEAR FROM col)`), not clauses.
     */
    public function __construct(
        public bool $expectTable,
        public bool $inTableList,
        public bool $inFunctionArguments = false
    ) {
    }

    /**
     * Brings the name into scope immediately, including inside its own body. `WITH RECURSIVE` only.
     *
     * @param string $name
     * @return void
     */
    public function defineCommonTableExpression(string $name): void
    {
        $this->commonTableExpressions[strtolower($name)] = true;
        $this->insideCommonTableExpressionBody = true;
    }

    /**
     * Brings the name into scope only once its body closes, which is what keeps a self-reference
     * inside that body pointing at the base table — and therefore allow-checked.
     *
     * @param string $name
     * @return void
     */
    public function deferCommonTableExpression(string $name): void
    {
        $this->pendingCommonTableExpression = strtolower($name);
        $this->insideCommonTableExpressionBody = true;
    }

    /**
     * Called when a parenthesis closes back into this scope: brings a deferred name into scope and
     * arms the comma that may introduce the next definition.
     *
     * @return void
     */
    public function closeCommonTableExpressionBody(): void
    {
        if (!$this->insideCommonTableExpressionBody) {
            return;
        }

        if ($this->pendingCommonTableExpression !== null) {
            $this->commonTableExpressions[$this->pendingCommonTableExpression] = true;
            $this->pendingCommonTableExpression = null;
        }

        $this->insideCommonTableExpressionBody = false;
        $this->continuesCteList = true;
    }

    /**
     * @return void
     */
    public function endCteList(): void
    {
        $this->expectCteName = false;
        $this->continuesCteList = false;
        $this->recursiveCteList = false;
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
