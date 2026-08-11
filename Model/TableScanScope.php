<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model;

/**
 * One parenthesis-level scope of {@see \Magebit\McpDbTools\Model\Sql\TableReferenceScanner}. CTE
 * names live on the defining scope — a nested `WITH x` must not exempt the real table `x` outside
 * it — and a plain CTE enters scope only once its body closes; only `WITH RECURSIVE` is visible
 * inside its own body.
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
    private bool $expectCteName = false;

    /**
     * Whether this level's `WITH` list was written `WITH RECURSIVE`.
     */
    private bool $recursiveCteList = false;

    /**
     * Whether a comma here continues the `WITH` list with another definition.
     */
    private bool $continuesCteList = false;

    /**
     * @param bool $expectTable Whether the next identifier here is a table reference.
     * @param bool $inTableList Whether a comma at this level continues a `FROM`/`JOIN` table list.
     * @param bool $inFunctionArguments Whether this level is a function call's argument list, where
     *        `FROM` and `IN` are argument separators (`EXTRACT(YEAR FROM col)`), not clauses.
     */
    public function __construct(
        private bool $expectTable,
        private bool $inTableList,
        private bool $inFunctionArguments = false
    ) {
    }

    /**
     * @return bool
     */
    public function expectsTable(): bool
    {
        return $this->expectTable;
    }

    /**
     * @return bool
     */
    public function isInFunctionArguments(): bool
    {
        return $this->inFunctionArguments;
    }

    /**
     * @return void
     */
    public function expectTable(): void
    {
        $this->expectTable = true;
    }

    /**
     * @return void
     */
    public function clearTableExpectation(): void
    {
        $this->expectTable = false;
    }

    /**
     * A `FROM`/`JOIN` opened a table list: the next identifier is a table, and so is the one after
     * each comma until the list is terminated.
     *
     * @return void
     */
    public function enterTableList(): void
    {
        $this->expectTable = true;
        $this->inTableList = true;
    }

    /**
     * @return void
     */
    public function leaveTableList(): void
    {
        $this->inTableList = false;
    }

    /**
     * A comma continues whichever list is open here — a table list, a `WITH` list, or neither.
     *
     * @return void
     */
    public function sawComma(): void
    {
        $this->expectTable = $this->inTableList;

        if ($this->continuesCteList) {
            $this->expectCteName = true;
            $this->continuesCteList = false;
        }
    }

    /**
     * `IN` names a table only in `SHOW … IN <table>`; elsewhere it introduces a value list, and
     * inside function arguments it separates them (`POSITION('a' IN col)`).
     *
     * @param bool $isShowStatement
     * @return void
     */
    public function expectShowTarget(bool $isShowStatement): void
    {
        $this->expectTable = $isShowStatement && !$this->inFunctionArguments;
    }

    /**
     * @param bool $recursive
     * @return void
     */
    public function beginCteList(bool $recursive): void
    {
        $this->expectCteName = true;
        $this->recursiveCteList = $recursive;
    }

    /**
     * @return bool
     */
    public function expectsCteName(): bool
    {
        return $this->expectCteName;
    }

    /**
     * @return bool
     */
    public function isRecursiveCteList(): bool
    {
        return $this->recursiveCteList;
    }

    /**
     * @return void
     */
    public function clearCteNameExpectation(): void
    {
        $this->expectCteName = false;
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
            // The main query block closed, so the WITH list is over: a later comma must not re-arm it.
            $this->continuesCteList = false;
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
