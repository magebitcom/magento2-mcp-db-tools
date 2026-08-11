<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Tool\Db;

use Magebit\Mcp\Api\ContextualAclAwareInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magebit\McpDbTools\Model\QueryGuard;
use Magebit\McpDbTools\Model\Sql\Lexer;
use Magebit\McpDbTools\Model\Sql\TableReferenceScanner;
use Magebit\McpDbTools\Model\TableAclMap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Throwable;

class Query implements ToolInterface, ContextualAclAwareInterface
{
    public const TOOL_NAME = 'db.query';
    public const ACL_RESOURCE = 'Magebit_McpDbTools::tool_db_query';

    public const MAX_PAYLOAD_BYTES = 1048576;
    private const PAYLOAD_OVERHEAD_BYTES = 1024;
    private const AUDIT_SQL_LENGTH = 1000;
    private const DRIVER_MESSAGE_LENGTH = 500;

    /**
     * @var list<class-string<Throwable>>
     */
    private const ECHOABLE_FAILURES = [
        \Zend_Db_Statement_Exception::class,
        \Magento\Framework\DB\Adapter\TableNotFoundException::class,
        \Magento\Framework\DB\Adapter\LockWaitException::class,
        \Magento\Framework\DB\Adapter\DeadlockException::class,
        \Magento\Framework\DB\Adapter\DuplicateException::class,
    ];

    private const MIN_QUERY_LENGTH = 6;
    private const MAX_QUERY_LENGTH = 8000;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Memoised server flavour: the timeout variable's name differs between MySQL and MariaDB, and
     * probing in the wrong order would log a failure on every healthy call.
     *
     * @var bool|null
     */
    private ?bool $isMariaDb = null;

    /**
     * @param DbToolsConfig $config
     * @param QueryGuard $queryGuard
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     * @param Lexer $lexer
     * @param TableReferenceScanner $tableScanner
     * @param TableAclMap $tableAclMap
     */
    public function __construct(
        private readonly DbToolsConfig $config,
        private readonly QueryGuard $queryGuard,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
        private readonly Lexer $lexer,
        private readonly TableReferenceScanner $tableScanner,
        private readonly TableAclMap $tableAclMap
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Run Read-Only SQL Query';
    }

    /**
     * The rules below are the client's only source of truth about what the guard accepts, so they
     * are stated in full: an agent that has to rediscover them burns calls on refusals.
     *
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Run one read-only SQL statement against this store\'s Magento database and get the '
            . 'rows back as JSON. Useful for questions the other MCP tools cannot answer. '
            . 'The server enforces all of the following and refuses anything else with an '
            . 'explanatory message:'
            . "\n- Read-only and a single statement: only SELECT, WITH, SHOW, DESCRIBE, DESC or "
            . 'EXPLAIN, with no `;` except a trailing one.'
            . "\n- Allowed Tables only: every table you name must be in the store operator's "
            . '"Allowed Tables" configuration list. Anything outside it is refused, and while that '
            . 'list is empty every query is refused (including `SELECT 1`) — the refusal message '
            . 'names the table and the setting, so relay it to the operator rather than retrying. '
            . 'A protected set is refused even when listed: admin accounts and passwords, MCP and '
            . 'OAuth tokens, sessions, `core_config_data`, and the server schemas '
            . '`information_schema` / `mysql` / `sys` / `performance_schema`.'
            . "\n- No `*` anywhere except `COUNT(*)`: `SELECT *` and `t.*` are refused so that "
            . 'protected columns cannot come back by accident, and this also refuses arithmetic '
            . 'multiplication — `SUM(qty * price)` will not run, and SQL has no other multiplication '
            . 'operator, so compute products from the returned columns on your side instead. '
            . 'Columns carrying secrets or bearer credentials are refused wherever they appear: '
            . '`password…`, `…token…`, `secret`, `api_key`, `session_id`/`session_data`, every '
            . '`cc_…` payment column, `confirmation` and `subscriber_confirm_code` (account '
            . 'activation keys), `link_hash`, and `additional_information` (serialized gateway '
            . 'payloads). Two selected columns with the same name collapse into '
            . 'one in the response, so alias them (`o.entity_id AS order_id`).'
            . "\n- Row cap: when you send no LIMIT, one is appended (the `limit` argument if given, "
            . 'otherwise the configured default). A LIMIT above the configured maximum is refused, '
            . 'not silently clamped — retry with a smaller number. LIMIT may appear only once, as '
            . 'the final clause.'
            . "\n- Table names: write `{{table_name}}` (e.g. `{{sales_order}}`) and the real, "
            . 'prefixed table name is substituted for you; do not guess the table prefix. Braces '
            . 'that are not a `{{name}}` placeholder are refused. The substitution is textual and '
            . 'happens everywhere, string literals included, so do not write `{{…}}` inside a '
            . 'literal you want left alone.'
            . "\n- String literals: single quotes only (`\"` is refused) and no backslashes at all "
            . '— embed a quote by doubling it (`\'\'`), and match a literal `_` or `%` with the '
            . 'ESCAPE clause: `LIKE \'a!_b\' ESCAPE \'!\'`.'
            . "\n- Functions: built-in read-only functions only. Stored functions and user-defined "
            . 'functions are always refused. `GROUP_CONCAT`, `JSON_ARRAYAGG` and `JSON_OBJECTAGG` '
            . 'are refused because they defeat the row cap — use COUNT/SUM or return rows instead. '
            . 'The keyword argument forms do work: `EXTRACT(YEAR FROM col)`, '
            . '`TRIM(LEADING \'0\' FROM col)`, `SUBSTRING(col FROM 2 FOR 3)`, '
            . '`POSITION(\'x\' IN col)`.'
            . "\n- Also refused: `@`/`@@` variables, `INTO OUTFILE`, `SLEEP`/`BENCHMARK`/"
            . '`GET_LOCK`, `/*! */`/`/*M! */` executable comments, `CURRENT_USER`/`CURRENT_ROLE`/'
            . '`SESSION_USER`/`SYSTEM_USER` (with or without parentheses — they report the '
            . 'connecting account), references to any other database, the '
            . 'table-value constructors `TABLE t` and `VALUES ROW(…)` (write `SELECT <columns> FROM '
            . 't` instead), `NEXT VALUE FOR`/`PREVIOUS VALUE FOR`, and schema-qualified function '
            . 'calls such as `db.count(1)`.'
            . "\nResponse: `columns`, `rows`, `row_count`, `limit_applied`, `truncated`, "
            . '`executed_sql`. `truncated` means there are probably more rows (the cap was hit) or '
            . 'the response was trimmed to fit its size limit — narrow the query rather than '
            . 'paging blindly. Queries run under a server-side time limit; every call is audited '
            . 'with the SQL that ran.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('query', fn (StringBuilder $s) => $s
                ->minLength(self::MIN_QUERY_LENGTH)
                ->maxLength(self::MAX_QUERY_LENGTH)
                ->description(
                    'The SQL statement to run. Single statement, read-only, explicit columns. '
                    . 'Use `{{table_name}}` for table names so the store\'s table prefix is applied.'
                )
                ->required())
            ->integer('limit', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->maximum($this->config->getRowLimitCeiling())
                ->description(
                    'Row cap, used only when the statement carries no LIMIT of its own. '
                    . 'Defaults to the store\'s configured default row limit.'
                ))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    /**
     * The admin resources the named tables require, so raw SQL cannot read what the domain tools
     * would refuse. Runs before schema validation, so it tolerates anything and never throws.
     *
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return array<int, string>
     */
    public function getContextualAclResources(array $arguments): array
    {
        $query = $arguments['query'] ?? null;
        if (!is_string($query) || trim($query) === '') {
            return [];
        }

        // Runs before schema validation, so the length cap has not applied yet; execute() refuses
        // an over-long query anyway, so there is nothing to gain from lexing it here.
        if (strlen($query) > self::MAX_QUERY_LENGTH) {
            return [];
        }

        try {
            return $this->tableAclMap->resolve($this->readTables($this->substituteTablePlaceholders($query)));
        } catch (Throwable) {
            // Malformed input is expected this early; execute() refuses what cannot be resolved.
            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function getWriteMode(): WriteMode
    {
        return WriteMode::READ;
    }

    /**
     * @inheritDoc
     */
    public function getConfirmationRequired(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        $this->assertEnabled();

        $sql = $this->substituteTablePlaceholders($this->readQuery($arguments));
        $executable = $this->queryGuard->assertReadOnly($sql, $this->readLimit($arguments));
        $this->assertEveryTableIsMapped($executable);

        $connection = $this->resourceConnection->getConnection();
        $sessionState = $this->captureSessionState($connection);

        try {
            $this->applySessionGuards($connection);
            $result = $this->streamRows($connection, $executable);
        } finally {
            $this->restoreSessionState($connection, $sessionState);
        }

        $limitApplied = $this->queryGuard->findRowLimit($executable);
        $rowCount = count($result['rows']);

        $payload = [
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'row_count' => $rowCount,
            'limit_applied' => $limitApplied,
            'truncated' => $result['truncated'] || ($limitApplied !== null && $rowCount === $limitApplied),
            'executed_sql' => $executable,
        ];

        return new ToolResult(
            content: [['type' => 'text', 'text' => $this->encode($payload)]],
            auditSummary: [
                'sql' => substr($executable, 0, self::AUDIT_SQL_LENGTH),
                'row_count' => $rowCount,
            ]
        );
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    private function assertEnabled(): void
    {
        if ($this->config->isEnabled()) {
            return;
        }

        throw new LocalizedException(
            __(
                'The read-only SQL tool is turned off. An administrator has to enable it under '
                . 'Stores > Configuration > Magebit > MCP Server - Database Reader > MCP Database Reader '
                . '> Enable Read-Only SQL Tool (%1).',
                DbToolsConfig::XML_PATH_ENABLED
            )
        );
    }

    /**
     * The gate the contextual ACL check cannot close on its own: an unmapped table resolves to no
     * resource, and the dispatcher reads that as "no opinion" rather than as a denial.
     *
     * @param string $executable Already accepted by the guard, so re-reading it cannot fail.
     * @return void
     * @throws LocalizedException
     */
    private function assertEveryTableIsMapped(string $executable): void
    {
        $unmapped = $this->tableAclMap->unmapped($this->readTables($executable));
        if ($unmapped === []) {
            return;
        }

        throw new LocalizedException(
            __(
                'These tables have no admin ACL mapping, so the tool cannot check whether the '
                . 'caller may read them: %1. Add a `table:Magento_Module::resource` line for each '
                . 'under %2, or remove them from the allowlist.',
                implode(', ', $unmapped),
                DbToolsConfig::XML_PATH_TABLE_ACL_MAP
            )
        );
    }

    /**
     * Names of the tables $sql reads. Schema qualifiers are dropped: the guard already refuses every
     * reference to another database, and dropping one can only ever widen what the caller must hold.
     *
     * @param string $sql
     * @return list<string>
     * @throws LocalizedException
     */
    private function readTables(string $sql): array
    {
        $lexed = $this->lexer->tokenize($sql);

        return $this->tableScanner->extractTables(
            $lexed['tokens'],
            strtoupper((string) ($lexed['tokens'][0]['value'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string
     * @throws LocalizedException
     */
    private function readQuery(array $arguments): string
    {
        $query = $arguments['query'] ?? null;
        if (!is_string($query) || trim($query) === '') {
            throw new LocalizedException(__('The `query` argument is required and must be a SQL string.'));
        }

        // The schema caps this too; re-checked here because the guard lexes byte by byte and has no
        // length bound of its own, so this is the only place that bounds the work of validation.
        if (strlen($query) > self::MAX_QUERY_LENGTH) {
            throw new LocalizedException(
                __('The query is longer than the %1-character limit. Shorten it and try again.', self::MAX_QUERY_LENGTH)
            );
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return int|null
     */
    private function readLimit(array $arguments): ?int
    {
        $limit = $arguments['limit'] ?? null;

        return is_int($limit) ? $limit : null;
    }

    /**
     * Resolves `{{table_name}}` to the real, prefixed table name. Anything else brace-shaped is
     * left in place on purpose: the guard refuses stray braces with a message that says why.
     *
     * @param string $sql
     * @return string
     * @throws LocalizedException
     */
    private function substituteTablePlaceholders(string $sql): string
    {
        $substituted = preg_replace_callback(
            '/\{\{([a-zA-Z0-9_]+)\}\}/',
            fn (array $match): string => $this->resourceConnection->getTableName((string) $match[1]),
            $sql
        );

        if ($substituted === null) {
            throw new LocalizedException(
                __('The `{{table_name}}` placeholders in the query could not be resolved.')
            );
        }

        return $substituted;
    }

    /**
     * Statement timeout and a pinned `sql_mode`, both from our own literals. The timeout is the
     * only bound on how much work a query may do, so a server that accepts neither variable gets
     * the query refused; the `sql_mode` pin is belt-and-braces and degrades with a warning.
     *
     * @param AdapterInterface $connection
     * @return void
     * @throws LocalizedException When no statement timeout could be applied.
     */
    private function applySessionGuards(AdapterInterface $connection): void
    {
        $seconds = max(1, $this->config->getStatementTimeoutSeconds());
        $mysqlForm = 'SET SESSION max_execution_time = ' . ($seconds * 1000);
        $mariaDbForm = 'SET SESSION max_statement_time = ' . $seconds;

        // Flavour-ordered: the wrong order logs a failure on every healthy call.
        $forms = $this->isMariaDb($connection) ? [$mariaDbForm, $mysqlForm] : [$mysqlForm, $mariaDbForm];
        $failures = [];

        foreach ($forms as $form) {
            $failure = $this->tryStatement($connection, $form);
            if ($failure === null) {
                if ($failures !== []) {
                    $this->logger->info(
                        'db.query applied its statement timeout with the fallback variable.',
                        ['applied' => $form, 'rejected' => $failures]
                    );
                }

                $this->pinSqlMode($connection);

                return;
            }

            $failures[$form] = $failure;
        }

        $this->logger->critical(
            'db.query could not apply any statement timeout and refused to run the query.',
            ['rejected' => $failures]
        );

        throw new LocalizedException(
            __(
                'The query was not run: this server accepts neither `max_execution_time` '
                . '(MySQL 5.7.8+) nor `max_statement_time` (MariaDB 10.1+), so the %1-second '
                . 'statement time limit cannot be enforced — and without it one query could occupy '
                . 'the database indefinitely.',
                $seconds
            )
        );
    }

    /**
     * `NO_BACKSLASH_ESCAPES` aligns the server's literal boundaries with the guard's. Appended
     * rather than assigned: this is the shared request connection.
     *
     * @param AdapterInterface $connection
     * @return void
     */
    private function pinSqlMode(AdapterInterface $connection): void
    {
        $failure = $this->tryStatement(
            $connection,
            "SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',NO_BACKSLASH_ESCAPES')"
        );

        if ($failure !== null) {
            // No assignment fallback on purpose: it would drop STRICT_TRANS_TABLES for the rest of
            // the request. Safe to skip — the guard refuses `\` and `"` regardless.
            $this->logger->warning(
                'db.query could not pin sql_mode; continuing without the pin.',
                ['exception' => $failure]
            );
        }
    }

    /**
     * Reads the session values the guards are about to overwrite. Null means they could not be read,
     * in which case nothing is restored — writing a guessed value would be worse than leaving ours.
     *
     * @param AdapterInterface $connection
     * @return array{sql_mode: string, timeout_variable: string, timeout_value: string}|null
     */
    private function captureSessionState(AdapterInterface $connection): ?array
    {
        $variable = $this->isMariaDb($connection) ? 'max_statement_time' : 'max_execution_time';

        try {
            $row = $connection->fetchRow(
                sprintf('SELECT @@SESSION.sql_mode AS m, @@SESSION.%s AS t', $variable)
            );
        } catch (Throwable $exception) {
            $this->logger->warning(
                'db.query could not read session state, so it will not be restored.',
                ['exception' => $exception->getMessage()]
            );

            return null;
        }

        if (!is_array($row) || !isset($row['m'], $row['t']) || !is_scalar($row['m']) || !is_scalar($row['t'])) {
            return null;
        }

        return [
            'sql_mode' => (string) $row['m'],
            'timeout_variable' => $variable,
            'timeout_value' => (string) $row['t'],
        ];
    }

    /**
     * Puts the shared connection back the way it was found. Runs after the streamed read, so the
     * timeout still bounds the whole call.
     *
     * @param AdapterInterface $connection
     * @param array{sql_mode: string, timeout_variable: string, timeout_value: string}|null $state
     * @return void
     */
    private function restoreSessionState(AdapterInterface $connection, ?array $state): void
    {
        if ($state === null) {
            return;
        }

        try {
            $quoted = $connection->quote($state['sql_mode']);
            if (is_string($quoted)) {
                $this->tryStatement($connection, 'SET SESSION sql_mode = ' . $quoted);
            }
        } catch (Throwable) {
            // This runs from a `finally`; a quoting failure must not replace the real exception.
        }

        $this->tryStatement(
            $connection,
            sprintf('SET SESSION %s = %d', $state['timeout_variable'], (int) $state['timeout_value'])
        );
    }

    /**
     * @param AdapterInterface $connection
     * @param string $statement
     * @return string|null The server's message, or null when the statement was accepted.
     */
    private function tryStatement(AdapterInterface $connection, string $statement): ?string
    {
        try {
            $connection->query($statement);

            return null;
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * @param AdapterInterface $connection
     * @return bool
     */
    private function isMariaDb(AdapterInterface $connection): bool
    {
        if ($this->isMariaDb === null) {
            try {
                $version = $connection->fetchOne('SELECT VERSION()');
            } catch (Throwable) {
                $version = null;
            }

            $this->isMariaDb = is_string($version) && stripos($version, 'mariadb') !== false;
        }

        return $this->isMariaDb;
    }

    /**
     * Reads the result set unbuffered, restoring buffering on every exit path: otherwise the driver
     * materialises the whole set in PHP at `query()` time and no cap can bound memory. Known gap:
     * Magento's 2006/2013 retry reads buffered (`_connectionFlagsSet` survives the reconnect).
     *
     * @param AdapterInterface $connection
     * @param string $executable
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool}
     * @throws LocalizedException On any driver failure, or when the first row alone exceeds the cap.
     */
    private function streamRows(AdapterInterface $connection, string $executable): array
    {
        $buffering = $this->suspendResultBuffering($connection);
        $connectionDropped = false;

        try {
            return $this->readRows($connection, $executable, $connectionDropped);
        } finally {
            // Nothing inherits a dropped connection, so restoring the attribute on its orphaned
            // handle would only risk an error-level line about a situation that is already handled.
            if (!$connectionDropped) {
                $this->restoreResultBuffering($buffering);
            }
        }
    }

    /**
     * Pulls rows one at a time and stops at the byte budget, so rows beyond the cap are never
     * converted into PHP values.
     *
     * @param AdapterInterface $connection
     * @param string $executable Executed verbatim; also echoed back, so it counts against the budget.
     * @param bool $connectionDropped Set to true when closing the cursor cost us the connection.
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool}
     * @throws LocalizedException On any driver failure, or when the first row alone exceeds the cap.
     */
    private function readRows(AdapterInterface $connection, string $executable, bool &$connectionDropped): array
    {
        $budget = self::MAX_PAYLOAD_BYTES - self::PAYLOAD_OVERHEAD_BYTES - strlen($executable);
        $statement = $this->runStatement($connection, $executable);
        $columns = [];
        $rows = [];
        $used = 0;
        $truncated = false;

        try {
            while (($row = $this->fetchRow($statement)) !== null) {
                // +1 for the comma that will separate this row from the previous one.
                $size = strlen($this->encode($row)) + 1;
                if ($columns === []) {
                    // The `columns` list is part of the payload too, so it comes out of the budget.
                    $size += strlen($this->encode(array_keys($row)));
                }

                if ($used + $size > $budget) {
                    if ($rows === []) {
                        throw new LocalizedException(
                            __(
                                'The first row alone is larger than the %1 KB response limit. Select fewer '
                                . 'or narrower columns, or use LEFT()/SUBSTRING() to shorten long text values.',
                                (int) round(self::MAX_PAYLOAD_BYTES / 1024)
                            )
                        );
                    }

                    $truncated = true;
                    break;
                }

                if ($columns === []) {
                    $columns = array_keys($row);
                }
                $rows[] = $row;
                $used += $size;
            }
        } finally {
            $connectionDropped = $this->closeCursor($connection, $statement);
        }

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * @param AdapterInterface $connection
     * @return array{pdo: \PDO, buffered: bool}|null Null when buffering could not be switched off.
     */
    private function suspendResultBuffering(AdapterInterface $connection): ?array
    {
        $pdo = $this->pdoHandle($connection);
        if ($pdo === null) {
            return null;
        }

        try {
            $previous = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

            return ['pdo' => $pdo, 'buffered' => !is_bool($previous) || $previous];
        } catch (Throwable $exception) {
            $this->logger->warning(
                'db.query could not switch off result buffering; the result set will be held in PHP '
                . 'in full, so a wide result set can exhaust memory before the response cap applies.',
                ['exception' => $exception->getMessage()]
            );

            return null;
        }
    }

    /**
     * @param array{pdo: \PDO, buffered: bool}|null $buffering
     * @return void
     */
    private function restoreResultBuffering(?array $buffering): void
    {
        if ($buffering === null) {
            return;
        }

        try {
            $buffering['pdo']->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffering['buffered']);
        } catch (Throwable $exception) {
            // Leaving the shared connection unbuffered would break the next query on it, so this is
            // one of the few places worth an error-level line.
            $this->logger->error(
                'db.query could not restore result buffering on the shared connection.',
                ['exception' => $exception->getMessage()]
            );
        }
    }

    /**
     * The adapter's own PDO handle, or null when this is not a PDO adapter (or cannot connect).
     *
     * @param AdapterInterface $connection
     * @return \PDO|null
     */
    private function pdoHandle(AdapterInterface $connection): ?\PDO
    {
        if (!$connection instanceof \Zend_Db_Adapter_Abstract) {
            return null;
        }

        try {
            $handle = $connection->getConnection();
        } catch (Throwable) {
            return null;
        }

        return $handle instanceof \PDO ? $handle : null;
    }

    /**
     * An unbuffered cursor left open would fail every later query on this connection — the audit
     * log insert included — so a cursor that will not close costs us the connection instead. Safe
     * to drop: an MCP call has no transaction of its own open at this point.
     *
     * @param AdapterInterface $connection
     * @param \Zend_Db_Statement_Interface $statement
     * @return bool Whether the connection had to be dropped.
     */
    private function closeCursor(AdapterInterface $connection, \Zend_Db_Statement_Interface $statement): bool
    {
        try {
            $statement->closeCursor();

            return false;
        } catch (Throwable $exception) {
            $this->logger->warning(
                'db.query could not close its cursor; dropping the connection so nothing inherits it.',
                ['exception' => $exception->getMessage()]
            );

            try {
                // `closeConnection()` is on the Zend adapter rather than Magento's interface.
                if ($connection instanceof \Zend_Db_Adapter_Abstract) {
                    $connection->closeConnection();
                }
            } catch (Throwable) {
                // Nothing further to do: the next caller gets a fresh connection either way.
            }

            return true;
        }
    }

    /**
     * @param AdapterInterface $connection
     * @param string $executable
     * @return \Zend_Db_Statement_Interface
     * @throws LocalizedException
     */
    private function runStatement(AdapterInterface $connection, string $executable): \Zend_Db_Statement_Interface
    {
        try {
            return $connection->query($executable);
        } catch (Throwable $exception) {
            throw $this->driverFailure($exception);
        }
    }

    /**
     * @param \Zend_Db_Statement_Interface $statement
     * @return array<string, mixed>|null Null at the end of the result set.
     * @throws LocalizedException
     */
    private function fetchRow(\Zend_Db_Statement_Interface $statement): ?array
    {
        try {
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            throw $this->driverFailure($exception);
        }

        if (!is_array($row)) {
            return null;
        }

        $columns = [];
        foreach ($row as $name => $value) {
            $columns[(string) $name] = $value;
        }

        return $columns;
    }

    /**
     * A statement-level message is echoed back on purpose: `Unknown column 'x'` is the one error an
     * agent can self-correct from and a timeout kill is the one an operator needs to see. Anything
     * else is summarised and logged instead — see {@see self::ECHOABLE_FAILURES}.
     *
     * @param Throwable $exception
     * @return LocalizedException
     */
    private function driverFailure(Throwable $exception): LocalizedException
    {
        $cause = $exception instanceof \Exception ? $exception : null;

        if (!$this->mayEchoFailure($exception)) {
            $this->logger->error(
                'db.query hit a database failure whose message is not safe to return.',
                ['class' => get_class($exception), 'exception' => $exception->getMessage()]
            );

            return new LocalizedException(
                __(
                    'The query could not be run because of a database connection problem. The details '
                    . 'are in the MCP log (var/log/magebit_mcp.log); they are withheld here because '
                    . 'they describe the server rather than the query.'
                ),
                $cause
            );
        }

        $message = $exception->getMessage();
        if (strlen($message) > self::DRIVER_MESSAGE_LENGTH) {
            $message = substr($message, 0, self::DRIVER_MESSAGE_LENGTH) . '…';
        }

        return new LocalizedException(
            __(
                'The database could not complete the query: %1. If the server interrupted it on the '
                . '%2-second statement time limit, narrow the query — fewer rows, fewer joins, or a '
                . 'tighter WHERE.',
                $message,
                max(1, $this->config->getStatementTimeoutSeconds())
            ),
            $cause
        );
    }

    /**
     * @param Throwable $exception
     * @return bool
     */
    private function mayEchoFailure(Throwable $exception): bool
    {
        foreach (self::ECHOABLE_FAILURES as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return string
     * @throws LocalizedException
     */
    private function encode(array $value): string
    {
        $json = json_encode($value, self::JSON_FLAGS);
        if ($json === false) {
            throw new LocalizedException(
                __('The result could not be encoded as JSON. Cast binary columns with HEX() and try again.')
            );
        }

        return $json;
    }
}
