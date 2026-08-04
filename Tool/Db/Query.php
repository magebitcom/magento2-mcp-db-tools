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
 *    unbounded cross join or a wide hashing scan is limited only by this;
 * 5. execution and response shaping.
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

    private const MIN_QUERY_LENGTH = 6;
    private const MAX_QUERY_LENGTH = 8000;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

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
            . "\n- Explicit columns: `SELECT *` and `t.*` are refused so that protected columns "
            . 'cannot come back by accident. `COUNT(*)` is the one accepted `*`. Columns whose '
            . 'names carry secrets (`password…`, `…token…`, `secret`, `api_key`, `cc_…`) are '
            . 'refused wherever they appear. Two selected columns with the same name collapse into '
            . 'one in the response, so alias them (`o.entity_id AS order_id`).'
            . "\n- Row cap: when you send no LIMIT, one is appended (the `limit` argument if given, "
            . 'otherwise the configured default). A LIMIT above the configured maximum is refused, '
            . 'not silently clamped — retry with a smaller number. LIMIT may appear only once, as '
            . 'the final clause.'
            . "\n- Table names: write `{{table_name}}` (e.g. `{{sales_order}}`) and the real, "
            . 'prefixed table name is substituted for you; do not guess the table prefix. Braces '
            . 'that are not a `{{name}}` placeholder are refused.'
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
            . '`GET_LOCK`, `/*! */` executable comments, and references to any other database.'
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

        $rows = $this->normaliseRows($connection->fetchAll($executable, [], \PDO::FETCH_ASSOC));
        $trimmed = $this->trimToPayloadBudget($rows, $executable);
        $limitApplied = $this->queryGuard->findRowLimit($executable);
        $rowCount = count($trimmed['rows']);

        $payload = [
            'columns' => array_keys($rows[0] ?? []),
            'rows' => $trimmed['rows'],
            'row_count' => $rowCount,
            'limit_applied' => $limitApplied,
            'truncated' => $trimmed['truncated'] || ($limitApplied !== null && $rowCount === $limitApplied),
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
     * Statement timeout and a pinned `sql_mode`, both from our own literals. Each failure degrades
     * rather than aborting — server flavours differ on the variable names — but it is logged,
     * because losing the timeout means losing the only bound on how much work a query may do.
     *
     * @param AdapterInterface $connection
     * @return void
     */
    private function applySessionGuards(AdapterInterface $connection): void
    {
        $seconds = max(1, $this->config->getStatementTimeoutSeconds());

        // MySQL 5.7.8+ (milliseconds, SELECT only), then the MariaDB 10.1+ equivalent (seconds).
        if (!$this->tryStatement($connection, 'SET SESSION max_execution_time = ' . ($seconds * 1000))) {
            $this->tryStatement($connection, 'SET SESSION max_statement_time = ' . $seconds);
        }

        // `NO_BACKSLASH_ESCAPES` makes the server's literal boundaries match the guard's, which
        // refuses backslashes outright. Appended to the session's existing modes so nothing the
        // rest of the request depends on is dropped; the plain form is the fallback.
        $appended = "SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',NO_BACKSLASH_ESCAPES')";
        if (!$this->tryStatement($connection, $appended)) {
            $this->tryStatement($connection, "SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'");
        }
    }

    /**
     * @param AdapterInterface $connection
     * @param string $statement
     * @return bool Whether the server accepted it.
     */
    private function tryStatement(AdapterInterface $connection, string $statement): bool
    {
        try {
            $connection->query($statement);

            return true;
        } catch (Throwable $exception) {
            $this->logger->warning(
                'db.query could not apply a session guard; continuing without it.',
                ['statement' => $statement, 'exception' => $exception->getMessage()]
            );

            return false;
        }
    }

    /**
     * @param array<array-key, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function normaliseRows(array $rows): array
    {
        $normalised = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $columns = [];
            foreach ($row as $name => $value) {
                $columns[(string) $name] = $value;
            }
            $normalised[] = $columns;
        }

        return $normalised;
    }

    /**
     * Drops trailing rows until the serialised payload fits {@see self::MAX_PAYLOAD_BYTES}.
     *
     * @param list<array<string, mixed>> $rows
     * @param string $executable Echoed back in the response, so it counts against the budget.
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     * @throws LocalizedException When even the first row is larger than the cap.
     */
    private function trimToPayloadBudget(array $rows, string $executable): array
    {
        $budget = self::MAX_PAYLOAD_BYTES - self::PAYLOAD_OVERHEAD_BYTES - strlen($executable);
        $used = 0;
        $kept = [];

        foreach ($rows as $row) {
            // +1 for the comma that will separate this row from the previous one.
            $size = strlen($this->encode($row)) + 1;

            if ($used + $size > $budget) {
                if ($kept === []) {
                    throw new LocalizedException(
                        __(
                            'The first row alone is larger than the %1 KB response limit. Select fewer '
                            . 'or narrower columns, or use LEFT()/SUBSTRING() to shorten long text values.',
                            (int) round(self::MAX_PAYLOAD_BYTES / 1024)
                        )
                    );
                }

                return ['rows' => $kept, 'truncated' => true];
            }

            $kept[] = $row;
            $used += $size;
        }

        return ['rows' => $kept, 'truncated' => false];
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
