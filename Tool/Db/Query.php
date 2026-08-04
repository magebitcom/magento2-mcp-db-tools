<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Tool\Db;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magebit\McpDbTools\Model\QueryGuard;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Throwable;

/**
 * `db.query` — runs one operator-supplied read-only statement and returns its rows.
 *
 * This class is deliberately thin: every decision about *what may run* belongs to
 * {@see QueryGuard}, and the string the guard returns is executed **verbatim**, so the form that
 * was validated is the form the server sees. The order below is part of the security contract:
 *
 * 1. the `magebit_mcp/db_tools/enabled` gate, before the connection is touched at all;
 * 2. `{{table_name}}` substitution — mandatory, because leftover braces are a hard rejection
 *    inside the guard, so a wiring mistake surfaces as an actionable message instead of a
 *    mysterious SQL error;
 * 3. `QueryGuard::assertReadOnly()`, whose messages (including the configuration-level ones about
 *    the Allowed Tables list) are surfaced to the operator unchanged — they name the offending
 *    entry and the config field, which is what makes them actionable;
 * 4. the session guards: a statement timeout and a pinned `sql_mode`, both built from our own
 *    literals and never from user input. The timeout is load-bearing rather than decorative: the
 *    guard bounds the *shape* of a query and the number of rows returned, not the work done, so an
 *    unbounded cross join or a wide hashing scan is limited only by this — which is why a server
 *    that accepts neither timeout variable gets the query refused rather than run unbounded;
 * 5. **streamed** execution: result buffering is switched off for the duration of the read (Magento
 *    turns it on at connect time, which makes the driver materialise the whole set inside PHP before
 *    the first row can be inspected), rows are pulled one at a time, and the read stops at the byte
 *    budget. The guard's row cap bounds the number of rows, never their width, so this is the only
 *    bound on how much a single query can pull into the process. Buffering is restored, and the
 *    cursor closed, on every exit path — an unbuffered cursor left open would break the next query
 *    on the shared connection, the audit-log insert included.
 *
 * **Audit note:** the executed SQL is written to `magebit_mcp_audit_log` verbatim (capped at
 * {@see self::AUDIT_SQL_LENGTH} characters) and is *not* PII-redacted, because it is SQL rather
 * than a named argument. A query such as `… WHERE email = 'a@b.com'` therefore leaves that literal
 * in the audit row. That is intentional — an audit trail for raw SQL access is worth little without
 * the statement — but it means the audit table inherits the sensitivity of the queries run.
 */
class Query implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'db.query';
    public const ACL_RESOURCE = 'Magebit_McpDbTools::tool_db_query';

    /**
     * Ceiling on the serialised response. A row cap alone does not bound bytes: one row of
     * concatenated `TEXT` columns can be megabytes on its own.
     */
    public const MAX_PAYLOAD_BYTES = 1048576;

    /**
     * Bytes held back from {@see self::MAX_PAYLOAD_BYTES} for the response's own scaffolding
     * (keys, flags, and the echoed SQL) so the trimmed payload still fits the cap.
     */
    private const PAYLOAD_OVERHEAD_BYTES = 1024;

    private const AUDIT_SQL_LENGTH = 1000;

    /**
     * Cap on how much of a driver message is echoed back to the caller.
     */
    private const DRIVER_MESSAGE_LENGTH = 500;

    /**
     * Failure classes whose message may be shown to the caller: they describe *this statement*
     * (unknown column, missing table, lock wait, deadlock) and carry nothing about the
     * infrastructure. An allowlist rather than a deny-list, so an unfamiliar failure class is
     * summarised instead of echoed — connection-level messages name the DB account and the
     * application host (`Access denied for user 'magento'@'10.1.0.10'`).
     *
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
     */
    public function __construct(
        private readonly DbToolsConfig $config,
        private readonly QueryGuard $queryGuard,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
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
            . '`GET_LOCK`, `/*! */` executable comments, references to any other database, the '
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
                ->required()
            )
            ->integer('limit', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->maximum($this->config->getRowLimitCeiling())
                ->description(
                    'Row cap, used only when the statement carries no LIMIT of its own. '
                    . 'Defaults to the store\'s configured default row limit.'
                )
            )
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
     * No admin-UI screen runs arbitrary SQL, so there is no underlying Magento resource that could
     * express "the admin UI can already do this" — the `enabled` config flag plus the dedicated
     * `tool_db_query` ACL are the whole gate. Returning a stand-in resource here would either
     * widen access (a broad one) or misdescribe the capability (a narrow one).
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        return null;
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

        $connection = $this->resourceConnection->getConnection();
        $this->applySessionGuards($connection);

        $result = $this->streamRows($connection, $executable);
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
                . 'Stores > Configuration > Magebit > MCP Server > MCP Database Reader > '
                . 'Enable Read-Only SQL Tool (%1).',
                DbToolsConfig::XML_PATH_ENABLED
            )
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

        // Ordered by server flavour, not tried blindly: MariaDB has no `max_execution_time` and
        // MySQL has no `max_statement_time`, so probing in the wrong order would log a failure on
        // every healthy call and drown the one case that matters.
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

                // `sql_mode`: `NO_BACKSLASH_ESCAPES` makes the server's literal boundaries match the
                // guard's, which refuses backslashes outright. Appended rather than assigned — this
                // is the shared request connection, so replacing the mode set would change
                // behaviour for everything else running on it.
                $sqlModeFailure = $this->tryStatement(
                    $connection,
                    "SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',NO_BACKSLASH_ESCAPES')"
                );
                if ($sqlModeFailure !== null) {
                    // Deliberately no `SET SESSION sql_mode = '…'` fallback: assigning would drop
                    // modes such as STRICT_TRANS_TABLES for the rest of the request. Skipping the
                    // pin is safe — the guard refuses backslashes and `"` whatever the mode is.
                    $this->logger->warning(
                        'db.query could not pin sql_mode; continuing without the pin.',
                        ['exception' => $sqlModeFailure]
                    );
                }

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
     * Reads the result set with buffering switched off for the duration, restoring it afterwards
     * whatever happens. Without this the whole set is materialised inside PHP by the driver at
     * `query()` time — Magento sets `MYSQL_ATTR_USE_BUFFERED_QUERY` on connect — and no cap applied
     * afterwards can bound memory.
     *
     * One attempt escapes that bound: Magento retries a statement failing with MySQL 2006/2013 on a
     * fresh connection, and `closeConnection()` leaves `_connectionFlagsSet` set, so `_connect()`
     * skips the attribute block and the new handle keeps PDO's buffered default. The shared
     * connection is never left unbuffered, but the retried attempt reads buffered.
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
     * log insert included — so a cursor that will not close costs us the connection instead. Note
     * that dropping the connection also discards any transaction open on it; only reachable when the
     * cursor refuses to close, and an MCP call has no transaction of its own open at this point.
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
