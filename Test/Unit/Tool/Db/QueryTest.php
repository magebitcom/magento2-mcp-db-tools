<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Test\Unit\Tool\Db;

use Magebit\Mcp\Api\ContextualAclAwareInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magebit\McpDbTools\Model\IdentifierMatcher;
use Magebit\McpDbTools\Model\QueryGuard;
use Magebit\McpDbTools\Model\Sql\Lexer;
use Magebit\McpDbTools\Model\Sql\TableReferenceScanner;
use Magebit\McpDbTools\Model\TableAclMap;
use Magebit\McpDbTools\Tool\Db\Query;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the thin tool layer: the order of operations, that the guard's returned string is
 * the string that reaches the connection, and that the response/audit shapes are stable.
 */
class QueryTest extends TestCase
{
    /**
     * What the session-state probe reads back before the guards are applied.
     */
    private const SESSION_STATE_ROW = ['m' => 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', 't' => '0'];

    /**
     * Every table the suite's statements name. execute() refuses an unmapped one, so a fixture
     * statement has to be as mapped as a real one would be.
     */
    private const ACL_MAP = [
        'sales_order:Magento_Sales::sales_order',
        'sales_order_grid:Magento_Sales::sales_order',
        'mage_sales_order:Magento_Sales::sales_order',
        'mage_sales_order_grid:Magento_Sales::sales_order',
        't:Magento_Backend::admin',
    ];

    /** @var list<string> */
    private array $statements = [];

    /** @var int Rows actually pulled from the driver, so early stops are observable. */
    private int $fetchedRows = 0;

    /** @var list<array<string, mixed>> */
    private array $rowsToStream = [];

    /** @var bool Makes the next executed statement fail, so exit paths can be exercised. */
    private bool $failNextStatement = false;

    /** @var DbToolsConfig&MockObject */
    private DbToolsConfig $config;

    /** @var QueryGuard&MockObject */
    private QueryGuard $guard;

    /** @var ResourceConnection&MockObject */
    private ResourceConnection $resourceConnection;

    /** @var AdapterInterface&MockObject */
    private AdapterInterface $connection;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->statements = [];
        $this->fetchedRows = 0;
        $this->failNextStatement = false;

        $this->config = $this->createMock(DbToolsConfig::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getStatementTimeoutSeconds')->willReturn(10);
        $this->config->method('getRowLimitCeiling')->willReturn(1000);

        $this->guard = $this->createMock(QueryGuard::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        // MySQL unless a test says otherwise; the flavour picks the timeout variable.
        $this->connection->method('fetchOne')->willReturn('8.0.36');
        $this->connection->method('fetchRow')->willReturn(self::SESSION_STATE_ROW);
        $this->connection->method('quote')->willReturnCallback(self::quoter());
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @return callable(mixed): string
     */
    private static function quoter(): callable
    {
        return static fn (mixed $value): string => "'" . (is_scalar($value) ? (string) $value : '') . "'";
    }

    /**
     * @param list<string>|null $aclMapLines Null uses {@see self::ACL_MAP}.
     * @return Query
     */
    private function tool(?array $aclMapLines = null): Query
    {
        return new Query(
            $this->config,
            $this->guard,
            $this->resourceConnection,
            $this->logger,
            new Lexer(),
            new TableReferenceScanner(),
            $this->aclMap($aclMapLines)
        );
    }

    /**
     * @param list<string>|null $lines Null uses {@see self::ACL_MAP}.
     * @return TableAclMap
     */
    private function aclMap(?array $lines = null): TableAclMap
    {
        $raw = implode("\n", $lines ?? self::ACL_MAP);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): mixed => $path === DbToolsConfig::XML_PATH_TABLE_ACL_MAP ? $raw : null
        );

        return new TableAclMap(
            new DbToolsConfig($scopeConfig),
            new IdentifierMatcher($this->createMock(DeploymentConfig::class))
        );
    }

    /**
     * Records every statement the tool sends, in order, and streams $rows from the statement each
     * `query()` call returns.
     *
     * @param list<array<string, mixed>> $rows
     * @return void
     */
    private function recordStatements(array $rows = []): void
    {
        $this->connection->method('query')->willReturnCallback(
            function (string $sql): \Zend_Db_Statement_Interface {
                $this->statements[] = $sql;
                if ($this->failNextStatement && str_starts_with($sql, 'SELECT')) {
                    $this->failNextStatement = false;
                    throw new \Zend_Db_Statement_Exception('SQLSTATE[42S22]: Column not found');
                }

                return $this->statementYielding($this->rowsToStream);
            }
        );
        $this->rowsToStream = $rows;
    }

    /**
     * A statement mock that hands out one row per `fetch()` call and then `false`, so a test can
     * see how many rows the tool actually pulled.
     *
     * @param list<array<string, mixed>> $rows
     * @return \Zend_Db_Statement_Interface&MockObject
     */
    private function statementYielding(array $rows): \Zend_Db_Statement_Interface
    {
        $statement = $this->createMock(\Zend_Db_Statement_Interface::class);
        $cursor = 0;
        $statement->method('fetch')->willReturnCallback(
            function () use ($rows, &$cursor) {
                if (!isset($rows[$cursor])) {
                    return false;
                }
                $this->fetchedRows++;

                return $rows[$cursor++];
            }
        );

        return $statement;
    }

    /**
     * @param string $json
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function runTool(array $arguments): array
    {
        $content = $this->tool()->execute($arguments)->getContent();
        self::assertArrayHasKey(0, $content);
        self::assertArrayHasKey('text', $content[0]);
        self::assertIsString($content[0]['text']);

        return $this->decode($content[0]['text']);
    }

    public function testDisabledConfigThrowsAndNeverTouchesTheConnection(): void
    {
        $config = $this->createMock(DbToolsConfig::class);
        $config->method('isEnabled')->willReturn(false);

        $this->resourceConnection->expects(self::never())->method('getConnection');
        $this->resourceConnection->expects(self::never())->method('getTableName');
        $this->guard->expects(self::never())->method('assertReadOnly');

        $tool = new Query(

            $config,

            $this->guard,

            $this->resourceConnection,

            $this->logger,

            new Lexer(),

            new TableReferenceScanner(),

            $this->aclMap()

        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(DbToolsConfig::XML_PATH_ENABLED);

        $tool->execute(['query' => 'SELECT entity_id FROM sales_order']);
    }

    public function testExecutesTheExactStringTheGuardReturned(): void
    {
        $validated = 'SELECT entity_id FROM sales_order LIMIT 100';
        $this->guard->method('assertReadOnly')->willReturn($validated);
        $this->guard->method('findRowLimit')->willReturn(100);
        $this->recordStatements([['entity_id' => '1']]);

        $response = $this->runTool(['query' => "SELECT entity_id\n  FROM sales_order"]);

        self::assertContains($validated, $this->statements);
        // The only statement that is not session bookkeeping is the guard's own string, verbatim.
        self::assertSame(
            [$validated],
            array_values(array_filter(
                $this->statements,
                static fn (string $s): bool => !str_starts_with($s, 'SET SESSION ')
            ))
        );
        self::assertSame($validated, $response['executed_sql']);
    }

    public function testGuardRejectionPropagatesWithoutExecuting(): void
    {
        $this->guard->method('assertReadOnly')
            ->willThrowException(new LocalizedException(__('`*` is not permitted.')));
        $this->recordStatements([['entity_id' => '1']]);

        try {
            $this->tool()->execute(['query' => 'SELECT * FROM sales_order']);
            self::fail('The guard rejection did not propagate.');
        } catch (LocalizedException $exception) {
            self::assertSame('`*` is not permitted.', $exception->getMessage());
        }

        // Not "no connection was obtained": the real getTableName() opens one while substituting.
        // Only the absence of a statement is a property of the production path.
        self::assertSame([], $this->statements);
        self::assertSame(0, $this->fetchedRows);
    }

    public function testGuardConfigurationMessageSurfacesVerbatim(): void
    {
        $message = 'No tables are listed in the Allowed Tables list '
            . '(magebit_mcp_db_tools/general/allowed_tables), so every query is refused.';
        $this->guard->method('assertReadOnly')->willThrowException(new LocalizedException(__($message)));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);

        $this->tool()->execute(['query' => 'SELECT 1']);
    }

    public function testTablePlaceholdersAreSubstitutedBeforeTheGuard(): void
    {
        $this->resourceConnection->method('getTableName')->willReturnCallback(
            static fn (string $name): string => 'mage_' . $name
        );

        $seen = null;
        $this->guard->method('assertReadOnly')->willReturnCallback(
            static function (string $sql) use (&$seen): string {
                $seen = $sql;
                return $sql . ' LIMIT 100';
            }
        );
        $this->recordStatements();

        $this->runTool(['query' => 'SELECT entity_id FROM {{sales_order}} JOIN {{sales_order_grid}}']);

        self::assertSame('SELECT entity_id FROM mage_sales_order JOIN mage_sales_order_grid', $seen);
    }

    public function testUnresolvablePlaceholderShapeIsLeftForTheGuardToReject(): void
    {
        // `{{ sales_order }}` is not a placeholder the substitution matches; the braces survive and
        // the guard refuses them with its own actionable message. Proves we do not silently strip.
        $seen = null;
        // The real guard refuses this, so it can never hand a brace back; the return value is only
        // here to let the call complete far enough to observe what the guard was given.
        $this->guard->method('assertReadOnly')->willReturnCallback(
            static function (string $sql) use (&$seen): string {
                $seen = $sql;
                return 'SELECT a FROM sales_order LIMIT 100';
            }
        );
        $this->recordStatements();

        $this->runTool(['query' => 'SELECT a FROM {{ sales_order }}']);

        self::assertSame('SELECT a FROM {{ sales_order }}', $seen);
    }

    public function testResponseShape(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id, status FROM sales_order LIMIT 100');
        $this->guard->method('findRowLimit')->willReturn(100);
        $this->recordStatements([
            ['entity_id' => '1', 'status' => 'pending'],
            ['entity_id' => '2', 'status' => 'complete'],
        ]);

        $response = $this->runTool(['query' => 'SELECT entity_id, status FROM sales_order']);

        self::assertSame(['entity_id', 'status'], $response['columns']);
        self::assertSame(
            [['entity_id' => '1', 'status' => 'pending'], ['entity_id' => '2', 'status' => 'complete']],
            $response['rows']
        );
        self::assertSame(2, $response['row_count']);
        self::assertSame(100, $response['limit_applied']);
        self::assertFalse($response['truncated']);
        self::assertSame('SELECT entity_id, status FROM sales_order LIMIT 100', $response['executed_sql']);
    }

    public function testTruncatedWhenRowCountEqualsTheAppliedLimit(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 2');
        $this->guard->method('findRowLimit')->willReturn(2);
        $this->recordStatements([['entity_id' => '1'], ['entity_id' => '2']]);

        $response = $this->runTool(['query' => 'SELECT entity_id FROM sales_order LIMIT 2']);

        self::assertSame(2, $response['row_count']);
        self::assertTrue($response['truncated']);
    }

    public function testMetadataStatementReportsNoLimit(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SHOW TABLES');
        $this->guard->method('findRowLimit')->willReturn(null);
        $this->recordStatements([['Tables_in_magento' => 'sales_order']]);

        $response = $this->runTool(['query' => 'SHOW TABLES']);

        self::assertNull($response['limit_applied']);
        self::assertFalse($response['truncated']);
        self::assertSame(1, $response['row_count']);
    }

    public function testEmptyResultSetHasNoColumns(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->guard->method('findRowLimit')->willReturn(100);
        $this->recordStatements();

        $response = $this->runTool(['query' => 'SELECT entity_id FROM sales_order']);

        self::assertSame([], $response['columns']);
        self::assertSame([], $response['rows']);
        self::assertSame(0, $response['row_count']);
        self::assertFalse($response['truncated']);
    }

    public function testPayloadCapTrimsRowsAndSetsTruncated(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT blob FROM sales_order LIMIT 40');
        $this->guard->method('findRowLimit')->willReturn(40);

        $rows = [];
        for ($index = 0; $index < 40; $index++) {
            $rows[] = ['blob' => str_repeat('a', 100000)];
        }
        $this->recordStatements($rows);

        $response = $this->runTool(['query' => 'SELECT blob FROM sales_order LIMIT 40']);

        self::assertIsArray($response['rows']);
        self::assertGreaterThan(0, $response['row_count']);
        self::assertLessThan(40, $response['row_count']);
        self::assertTrue($response['truncated']);
        self::assertSame(count($response['rows']), $response['row_count']);
        self::assertLessThanOrEqual(Query::MAX_PAYLOAD_BYTES, strlen((string) json_encode($response)));

        // The point of streaming: the rows past the budget are never pulled into PHP at all. One
        // extra fetch is expected — the row that breaches the budget is read, measured, dropped.
        self::assertSame($response['row_count'] + 1, $this->fetchedRows);
        self::assertLessThan(40, $this->fetchedRows);
    }

    public function testPayloadStaysUnderTheCapWithManyLongColumnNames(): void
    {
        // The `columns` list is part of the payload too. With 90 long names it is several KB, which
        // is what pushed the response over the cap before the budget accounted for it.
        $this->guard->method('assertReadOnly')->willReturn('SELECT … FROM sales_order LIMIT 40');
        $this->guard->method('findRowLimit')->willReturn(40);

        $row = [];
        for ($column = 0; $column < 90; $column++) {
            $name = 'a_deliberately_long_column_alias_number_' . str_pad((string) $column, 3, '0', STR_PAD_LEFT);
            $row[$name] = str_repeat('v', 500);
        }
        $this->recordStatements(array_fill(0, 40, $row));

        $response = $this->runTool(['query' => 'SELECT … FROM sales_order LIMIT 40']);

        self::assertTrue($response['truncated']);
        self::assertIsArray($response['columns']);
        self::assertCount(90, $response['columns']);
        self::assertLessThanOrEqual(Query::MAX_PAYLOAD_BYTES, strlen((string) json_encode($response)));
    }

    public function testSingleRowLargerThanThePayloadCapIsRejected(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT blob FROM sales_order LIMIT 1');
        $this->guard->method('findRowLimit')->willReturn(1);
        $this->recordStatements([['blob' => str_repeat('a', Query::MAX_PAYLOAD_BYTES + 1)]]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/first row/');

        $this->tool()->execute(['query' => 'SELECT blob FROM sales_order LIMIT 1']);
    }

    public function testSessionGuardsAreIssuedBeforeTheQuery(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->recordStatements([['entity_id' => '1']]);
        // A healthy install must log nothing at all: the timeout variable is chosen by flavour.
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('info');
        $this->logger->expects(self::never())->method('critical');

        $this->runTool(['query' => 'SELECT entity_id FROM sales_order']);

        // Two guards, the query, then the two restores that put the shared connection back.
        self::assertCount(5, $this->statements);
        self::assertSame('SET SESSION max_execution_time = 10000', $this->statements[0]);
        self::assertStringContainsString('sql_mode', $this->statements[1]);
        self::assertStringContainsString('NO_BACKSLASH_ESCAPES', $this->statements[1]);
        self::assertSame('SELECT entity_id FROM sales_order LIMIT 100', $this->statements[2]);
        self::assertTrue(self::isSqlModeRestore($this->statements[3]));
        self::assertSame('SET SESSION max_execution_time = 0', $this->statements[4]);
    }

    /**
     * @param string $statement
     * @return bool
     */
    private static function isSqlModeRestore(string $statement): bool
    {
        return str_contains($statement, 'sql_mode =') && !str_contains($statement, 'CONCAT');
    }

    public function testSessionStateIsRestoredAfterASuccessfulCall(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->recordStatements([['entity_id' => '1']]);

        $this->tool()->execute(['query' => 'SELECT entity_id FROM sales_order']);

        $restores = array_values(array_filter($this->statements, self::isSqlModeRestore(...)));
        self::assertNotEmpty($restores, 'sql_mode must be restored, not left appended.');
        self::assertStringContainsString('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', $restores[0]);

        // Restoring before the rows are read would hand the guard's own timeout back mid-stream.
        $queryIndex = array_search('SELECT entity_id FROM sales_order LIMIT 100', $this->statements, true);
        self::assertIsInt($queryIndex);
        self::assertGreaterThan($queryIndex, array_search($restores[0], $this->statements, true));
        self::assertSame('SET SESSION max_execution_time = 0', $this->statements[count($this->statements) - 1]);
    }

    public function testSessionStateIsRestoredWhenTheQueryThrows(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->recordStatements();
        $this->failNextStatement = true;

        try {
            $this->tool()->execute(['query' => 'SELECT entity_id FROM sales_order']);
            self::fail('The driver failure did not propagate.');
        } catch (LocalizedException) {
            // expected
        }

        $restores = array_values(array_filter($this->statements, self::isSqlModeRestore(...)));
        self::assertNotEmpty($restores, 'sql_mode must be restored even when the statement fails.');
        self::assertSame('SET SESSION max_execution_time = 0', $this->statements[count($this->statements) - 1]);
    }

    public function testSessionStateThatCannotBeReadIsNotRestored(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturn('8.0.36');
        $connection->method('fetchRow')->willThrowException(new \RuntimeException('no access'));
        $connection->method('query')->willReturnCallback(
            function (string $sql): \Zend_Db_Statement_Interface {
                $this->statements[] = $sql;
                return $this->statementYielding([['entity_id' => '1']]);
            }
        );
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->logger->expects(self::once())->method('warning');

        $tool = new Query(

            $this->config,

            $this->guard,

            $resourceConnection,

            $this->logger,

            new Lexer(),

            new TableReferenceScanner(),

            $this->aclMap()

        );
        $tool->execute(['query' => 'SELECT entity_id FROM sales_order']);

        self::assertSame([], array_filter($this->statements, self::isSqlModeRestore(...)));
        self::assertSame('SELECT entity_id FROM sales_order LIMIT 100', $this->statements[2]);
        self::assertCount(3, $this->statements);
    }

    public function testMariaDbGetsItsOwnTimeoutVariableFirstAndLogsNothing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturn('10.6.16-MariaDB-1:10.6.16+maria~ubu2004');
        $connection->method('fetchRow')->willReturn(self::SESSION_STATE_ROW);
        $connection->method('quote')->willReturnCallback(self::quoter());
        $connection->method('query')->willReturnCallback(
            function (string $sql): \Zend_Db_Statement_Interface {
                $this->statements[] = $sql;
                return $this->statementYielding([['entity_id' => '1']]);
            }
        );
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('info');
        $this->logger->expects(self::never())->method('critical');

        $tool = new Query(

            $this->config,

            $this->guard,

            $resourceConnection,

            $this->logger,

            new Lexer(),

            new TableReferenceScanner(),

            $this->aclMap()

        );
        $tool->execute(['query' => 'SELECT entity_id FROM sales_order']);

        self::assertSame('SET SESSION max_statement_time = 10', $this->statements[0]);
        // The restore has to follow the flavour too, or it would name a variable the server rejects.
        self::assertSame('SET SESSION max_statement_time = 0', $this->statements[count($this->statements) - 1]);
        self::assertStringNotContainsString('max_execution_time', implode(' ', $this->statements));
    }

    public function testFallbackTimeoutVariableIsUsedAndLoggedBelowWarning(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->connection->method('query')->willReturnCallback(
            function (string $sql): \Zend_Db_Statement_Interface {
                $this->statements[] = $sql;
                if (str_contains($sql, 'max_execution_time')) {
                    throw new \RuntimeException('Unknown system variable');
                }
                return $this->statementYielding([['entity_id' => '1']]);
            }
        );
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('critical');
        $this->logger->expects(self::once())->method('info');

        $response = $this->runTool(['query' => 'SELECT entity_id FROM sales_order']);

        self::assertSame('SET SESSION max_statement_time = 10', $this->statements[1]);
        self::assertSame(1, $response['row_count']);
    }

    public function testQueryIsRefusedWhenNoStatementTimeoutCanBeApplied(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->connection->method('query')->willReturnCallback(
            function (string $sql): void {
                $this->statements[] = $sql;
                throw new \RuntimeException('Unknown system variable');
            }
        );
        $this->logger->expects(self::once())->method('critical');

        try {
            $this->tool()->execute(['query' => 'SELECT entity_id FROM sales_order']);
            self::fail('A query with no enforceable time limit was allowed to run.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('max_execution_time', $exception->getMessage());
            self::assertStringContainsString('max_statement_time', $exception->getMessage());
        }

        // Both rungs tried, then only the restores — the query itself was never issued.
        self::assertCount(4, $this->statements);
        self::assertSame([], array_filter($this->statements, static fn (string $s): bool => str_contains($s, 'FROM')));
        self::assertSame(0, $this->fetchedRows);
    }

    public function testSqlModePinFailureIsNonFatalAndIsNotRetriedByAssignment(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->connection->method('query')->willReturnCallback(
            function (string $sql): \Zend_Db_Statement_Interface {
                $this->statements[] = $sql;
                if (str_contains($sql, 'sql_mode')) {
                    throw new \RuntimeException('Variable sql_mode cannot be set');
                }
                return $this->statementYielding([['entity_id' => '1']]);
            }
        );
        $this->logger->expects(self::once())->method('warning');

        $response = $this->runTool(['query' => 'SELECT entity_id FROM sales_order']);

        self::assertSame(1, $response['row_count']);
        // Exactly one sql_mode attempt: assigning a literal mode set would drop the session's
        // existing modes (STRICT_TRANS_TABLES among them) for the rest of the request.
        $pinAttempts = array_filter(
            $this->statements,
            static fn (string $statement): bool => str_contains($statement, 'CONCAT(@@SESSION.sql_mode')
        );
        self::assertCount(1, $pinAttempts);
        self::assertSame('SELECT entity_id FROM sales_order LIMIT 100', $this->statements[2]);
    }

    public function testStatementErrorOnExecutionSurfacesTheServerMessage(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT bogus FROM sales_order LIMIT 100');
        $this->connection->method('query')->willReturnCallback(
            function (string $sql): \Zend_Db_Statement_Interface {
                $this->statements[] = $sql;
                if (str_starts_with($sql, 'SELECT')) {
                    throw new \Zend_Db_Statement_Exception(
                        "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'bogus_column' in 'field list'"
                    );
                }
                return $this->statementYielding([]);
            }
        );

        try {
            $this->tool()->execute(['query' => 'SELECT bogus FROM sales_order']);
            self::fail('A driver error was swallowed.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString("Unknown column 'bogus_column'", $exception->getMessage());
            self::assertStringContainsString('10-second', $exception->getMessage());
        }
    }

    public function testStatementErrorWhileStreamingSurfacesTheServerMessage(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $statement = $this->createMock(\Zend_Db_Statement_Interface::class);
        $statement->method('fetch')->willThrowException(
            new \Zend_Db_Statement_Exception(
                'SQLSTATE[70100]: Query execution was interrupted, max_statement_time exceeded'
            )
        );
        $this->connection->method('query')->willReturn($statement);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/max_statement_time exceeded/');

        $this->tool()->execute(['query' => 'SELECT entity_id FROM sales_order']);
    }

    public function testConnectionLevelFailureIsNotEchoedToTheCaller(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $secret = "SQLSTATE[HY000] [1045] Access denied for user 'magento'@'10.1.0.10' (using password: YES)";
        $this->connection->method('query')->willReturnCallback(
            function (string $sql) use ($secret): \Zend_Db_Statement_Interface {
                $this->statements[] = $sql;
                if (str_starts_with($sql, 'SELECT')) {
                    throw new \Zend_Db_Adapter_Exception($secret);
                }
                return $this->statementYielding([]);
            }
        );
        $this->logger->expects(self::once())->method('error');

        try {
            $this->tool()->execute(['query' => 'SELECT entity_id FROM sales_order']);
            self::fail('A connection-level failure did not raise.');
        } catch (LocalizedException $exception) {
            self::assertStringNotContainsString('Access denied', $exception->getMessage());
            self::assertStringNotContainsString('magento', $exception->getMessage());
            self::assertStringNotContainsString('10.1.0.10', $exception->getMessage());
            self::assertStringContainsString('connection problem', $exception->getMessage());
        }
    }

    public function testEchoedDriverMessageIsTruncated(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->connection->method('query')->willReturnCallback(
            function (string $sql): \Zend_Db_Statement_Interface {
                if (str_starts_with($sql, 'SELECT')) {
                    throw new \Zend_Db_Statement_Exception(str_repeat('x', 4000));
                }
                return $this->statementYielding([]);
            }
        );

        try {
            $this->tool()->execute(['query' => 'SELECT entity_id FROM sales_order']);
            self::fail('A driver error was swallowed.');
        } catch (LocalizedException $exception) {
            self::assertLessThan(1200, strlen($exception->getMessage()));
            self::assertStringContainsString('…', $exception->getMessage());
        }
    }

    public function testResultBufferingIsSuspendedForTheReadAndRestoredAfterwards(): void
    {
        $calls = [];
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn(true);
        $pdo->method('setAttribute')->willReturnCallback(
            static function (int $attribute, $value) use (&$calls): bool {
                if ($attribute === \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) {
                    $calls[] = $value;
                }
                return true;
            }
        );

        $adapter = $this->getMockBuilder(\Magento\Framework\DB\Adapter\Pdo\Mysql::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query', 'fetchOne', 'getConnection', 'closeConnection'])
            ->getMock();
        $adapter->method('fetchOne')->willReturn('10.6.16-MariaDB');
        $adapter->method('getConnection')->willReturn($pdo);
        $adapter->method('query')->willReturnCallback(
            function (string $sql) use (&$calls): \Zend_Db_Statement_Interface {
                $this->statements[] = $sql;
                // Matched on the table, not on `SELECT`: the session-state probe is a SELECT too,
                // and it runs before buffering is touched.
                if (str_contains($sql, 'FROM sales_order')) {
                    // The read itself must happen while buffering is off.
                    self::assertSame([false], $calls);
                }
                return $this->statementYielding([['entity_id' => '1']]);
            }
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($adapter);
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');

        $tool = new Query(

            $this->config,

            $this->guard,

            $resourceConnection,

            $this->logger,

            new Lexer(),

            new TableReferenceScanner(),

            $this->aclMap()

        );
        $tool->execute(['query' => 'SELECT entity_id FROM sales_order']);

        self::assertSame([false, true], $calls, 'Buffering must be switched off, then restored.');
    }

    public function testResultBufferingIsRestoredWhenTheReadThrows(): void
    {
        $calls = [];
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn(true);
        $pdo->method('setAttribute')->willReturnCallback(
            static function (int $attribute, $value) use (&$calls): bool {
                if ($attribute === \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) {
                    $calls[] = $value;
                }
                return true;
            }
        );

        $adapter = $this->getMockBuilder(\Magento\Framework\DB\Adapter\Pdo\Mysql::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query', 'fetchOne', 'getConnection', 'closeConnection'])
            ->getMock();
        $adapter->method('fetchOne')->willReturn('8.0.36');
        $adapter->method('getConnection')->willReturn($pdo);
        $adapter->method('query')->willReturnCallback(
            function (string $sql): \Zend_Db_Statement_Interface {
                if (str_contains($sql, 'FROM sales_order')) {
                    throw new \Zend_Db_Statement_Exception('SQLSTATE[42S22]: Column not found');
                }
                return $this->statementYielding([]);
            }
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($adapter);
        $this->guard->method('assertReadOnly')->willReturn('SELECT bogus FROM sales_order LIMIT 100');

        $tool = new Query(

            $this->config,

            $this->guard,

            $resourceConnection,

            $this->logger,

            new Lexer(),

            new TableReferenceScanner(),

            $this->aclMap()

        );

        try {
            $tool->execute(['query' => 'SELECT bogus FROM sales_order']);
            self::fail('The driver failure did not propagate.');
        } catch (LocalizedException) {
            self::assertSame([false, true], $calls, 'Buffering must be restored on the throw path too.');
        }
    }

    public function testCursorThatWillNotCloseCostsTheConnection(): void
    {
        $statement = $this->createMock(\Zend_Db_Statement_Interface::class);
        $statement->method('fetch')->willReturn(false);
        $statement->method('closeCursor')->willThrowException(new \RuntimeException('cursor stuck'));

        $adapter = $this->getMockBuilder(\Magento\Framework\DB\Adapter\Pdo\Mysql::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query', 'fetchOne', 'getConnection', 'closeConnection'])
            ->getMock();
        $adapter->method('fetchOne')->willReturn('8.0.36');
        $adapter->method('getConnection')->willReturn(null);
        $adapter->method('query')->willReturn($statement);
        // An unbuffered cursor left open would break every later query on this connection.
        $adapter->expects(self::once())->method('closeConnection');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($adapter);
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->logger->expects(self::once())->method('warning');

        $tool = new Query(

            $this->config,

            $this->guard,

            $resourceConnection,

            $this->logger,

            new Lexer(),

            new TableReferenceScanner(),

            $this->aclMap()

        );
        $tool->execute(['query' => 'SELECT entity_id FROM sales_order']);
    }

    public function testResultBufferingIsNotRestoredOnAConnectionThatWasDropped(): void
    {
        $calls = [];
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn(true);
        $pdo->method('setAttribute')->willReturnCallback(
            static function (int $attribute, $value) use (&$calls): bool {
                if ($attribute === \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) {
                    $calls[] = $value;
                }
                return true;
            }
        );

        $statement = $this->createMock(\Zend_Db_Statement_Interface::class);
        $statement->method('fetch')->willReturn(false);
        $statement->method('closeCursor')->willThrowException(new \RuntimeException('cursor stuck'));

        $adapter = $this->getMockBuilder(\Magento\Framework\DB\Adapter\Pdo\Mysql::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query', 'fetchOne', 'getConnection', 'closeConnection'])
            ->getMock();
        $adapter->method('fetchOne')->willReturn('8.0.36');
        $adapter->method('getConnection')->willReturn($pdo);
        $adapter->method('query')->willReturn($statement);
        $adapter->expects(self::once())->method('closeConnection');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($adapter);
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        // The dropped connection is a warning, never the `error` line about a failed restore.
        $this->logger->expects(self::once())->method('warning');
        $this->logger->expects(self::never())->method('error');

        $tool = new Query(

            $this->config,

            $this->guard,

            $resourceConnection,

            $this->logger,

            new Lexer(),

            new TableReferenceScanner(),

            $this->aclMap()

        );
        $tool->execute(['query' => 'SELECT entity_id FROM sales_order']);

        self::assertSame([false], $calls, 'An orphaned handle must not be written to.');
    }

    public function testLimitArgumentReachesTheGuard(): void
    {
        $seen = 'unset';
        $this->guard->method('assertReadOnly')->willReturnCallback(
            static function (string $sql, ?int $limit) use (&$seen): string {
                $seen = $limit;
                return $sql . ' LIMIT ' . $limit;
            }
        );
        $this->recordStatements();

        $this->runTool(['query' => 'SELECT entity_id FROM sales_order', 'limit' => 25]);

        self::assertSame(25, $seen);
    }

    public function testNoLimitArgumentPassesNullToTheGuard(): void
    {
        $seen = 'unset';
        $this->guard->method('assertReadOnly')->willReturnCallback(
            static function (string $sql, ?int $limit) use (&$seen): string {
                $seen = $limit;
                return $sql;
            }
        );
        $this->recordStatements();

        $this->runTool(['query' => 'SELECT entity_id FROM sales_order']);

        self::assertNull($seen);
    }

    public function testAuditSummaryCarriesTheExecutedSqlAndRowCount(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->recordStatements([['entity_id' => '1'], ['entity_id' => '2']]);

        $summary = $this->tool()->execute(['query' => 'SELECT entity_id FROM sales_order'])->getAuditSummary();

        self::assertSame(
            ['sql' => 'SELECT entity_id FROM sales_order LIMIT 100', 'row_count' => 2],
            $summary
        );
    }

    public function testAuditSummarySqlIsCappedAtOneThousandCharacters(): void
    {
        $long = 'SELECT ' . str_repeat('a', 2000);
        $this->guard->method('assertReadOnly')->willReturn($long);
        $this->recordStatements();

        $summary = $this->tool()->execute(['query' => 'SELECT a FROM sales_order'])->getAuditSummary();

        self::assertIsString($summary['sql']);
        self::assertSame(1000, strlen($summary['sql']));
    }

    public function testMissingQueryArgumentIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('query');

        $this->tool()->execute([]);
    }

    public function testOverlongQueryIsRejectedBeforeTheGuardLexesIt(): void
    {
        $this->guard->expects(self::never())->method('assertReadOnly');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('8000');

        $this->tool()->execute(['query' => 'SELECT a FROM t WHERE b = ' . str_repeat('1', 8000)]);
    }

    public function testInputSchemaCeilingComesFromConfig(): void
    {
        $config = $this->createMock(DbToolsConfig::class);
        $config->method('getRowLimitCeiling')->willReturn(250);
        $tool = new Query(
            $config,
            $this->guard,
            $this->resourceConnection,
            $this->logger,
            new Lexer(),
            new TableReferenceScanner(),
            $this->aclMap()
        );

        $schema = $tool->getInputSchema();

        self::assertIsArray($schema['properties']);
        self::assertSame(250, $schema['properties']['limit']['maximum']);
        self::assertSame(1, $schema['properties']['limit']['minimum']);
        self::assertSame(6, $schema['properties']['query']['minLength']);
        self::assertSame(8000, $schema['properties']['query']['maxLength']);
        self::assertSame(['query'], $schema['required']);
    }

    public function testToolMetadata(): void
    {
        $tool = $this->tool();

        self::assertSame('db.query', $tool->getName());
        self::assertSame('Magebit_McpDbTools::tool_db_query', $tool->getAclResource());
        self::assertSame(WriteMode::READ, $tool->getWriteMode());
        self::assertFalse($tool->getConfirmationRequired());
        // The static gate is gone: what a raw SELECT needs depends on the tables it names.
        self::assertInstanceOf(ContextualAclAwareInterface::class, $tool);
        self::assertNotInstanceOf(UnderlyingAclAwareInterface::class, $tool);
    }

    public function testContextualResourcesDerivedFromTheTablesNamed(): void
    {
        $tool = $this->tool(['sales_order:Magento_Sales::sales_order']);

        self::assertSame(
            ['Magento_Sales::sales_order'],
            $tool->getContextualAclResources(['query' => 'SELECT entity_id FROM sales_order'])
        );
    }

    /**
     * The contract forbids throwing here and is called before schema validation.
     *
     * @return void
     */
    public function testMalformedArgumentsYieldAnEmptyListWithoutThrowing(): void
    {
        $tool = $this->tool(['sales_order:Magento_Sales::sales_order']);

        self::assertSame([], $tool->getContextualAclResources([]));
        self::assertSame([], $tool->getContextualAclResources(['query' => 12345]));
        self::assertSame([], $tool->getContextualAclResources(['query' => 'NOT SQL AT ALL ((((']));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unresolvableArgumentProvider(): array
    {
        return [
            'null query' => [null],
            'array query' => [[]],
            'blank query' => ['   '],
            'unterminated literal' => ["SELECT a FROM sales_order WHERE b = 'x"],
            'unterminated identifier' => ['SELECT a FROM `sales_order'],
            'control character' => ["SELECT a FROM sales_order\x00"],
            'double quotes' => ['SELECT "a" FROM sales_order'],
            'unresolved placeholder' => ['SELECT a FROM {{ sales_order }}'],
        ];
    }

    /**
     * @dataProvider unresolvableArgumentProvider
     * @param mixed $query
     * @return void
     */
    public function testNothingResolvableYieldsAnEmptyListNeverABlankResource(mixed $query): void
    {
        $resources = $this->tool()->getContextualAclResources(['query' => $query]);

        self::assertSame([], $resources);
    }

    public function testContextualResourcesSeeThroughTablePlaceholders(): void
    {
        $this->resourceConnection->method('getTableName')->willReturnCallback(
            static fn (string $name): string => 'mage_' . $name
        );
        $tool = $this->tool(['mage_sales_order:Magento_Sales::sales_order']);

        self::assertSame(
            ['Magento_Sales::sales_order'],
            $tool->getContextualAclResources(['query' => 'SELECT entity_id FROM {{sales_order}}'])
        );
    }

    public function testEveryNamedTableContributesItsOwnResource(): void
    {
        $tool = $this->tool([
            'sales_order:Magento_Sales::sales_order',
            'customer_entity:Magento_Customer::manage',
        ]);

        self::assertSame(
            ['Magento_Sales::sales_order', 'Magento_Customer::manage'],
            $tool->getContextualAclResources([
                'query' => 'SELECT o.entity_id FROM sales_order o JOIN customer_entity c ON c.entity_id = 1',
            ])
        );
    }

    /**
     * An unmapped table resolves to nothing, and the dispatcher reads nothing as "no opinion" —
     * so the refusal has to come from the tool itself or the gate is skipped.
     *
     * @return void
     */
    public function testExecuteRefusesATableWithNoAclMappingSoEmptyIsNeverASilentPass(): void
    {
        $tool = $this->tool(['sales_order:Magento_Sales::sales_order']);
        $this->guard->method('assertReadOnly')->willReturnArgument(0);
        $this->recordStatements([['title' => 'Home']]);

        self::assertSame([], $tool->getContextualAclResources(['query' => 'SELECT title FROM cms_page']));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/cms_page/');

        $tool->execute(['query' => 'SELECT title FROM cms_page']);
    }

    public function testExecuteRefusesTheUnmappedTableBeforeTouchingTheDatabase(): void
    {
        $tool = $this->tool(['sales_order:Magento_Sales::sales_order']);
        $this->guard->method('assertReadOnly')->willReturnArgument(0);
        $this->recordStatements([['entity_id' => '1']]);

        try {
            $tool->execute(['query' => 'SELECT o.entity_id FROM sales_order o JOIN cms_page p ON 1 = 1']);
            self::fail('The unmapped table was not refused.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('cms_page', $exception->getMessage());
            self::assertStringNotContainsString('sales_order', $exception->getMessage());
            self::assertStringContainsString(DbToolsConfig::XML_PATH_TABLE_ACL_MAP, $exception->getMessage());
        }

        self::assertSame([], $this->statements);
    }

    public function testExecuteRunsWhenEveryNamedTableIsMapped(): void
    {
        $tool = $this->tool(['sales_order:Magento_Sales::sales_order']);
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');
        $this->recordStatements([['entity_id' => '1']]);

        $content = $tool->execute(['query' => 'SELECT entity_id FROM sales_order'])->getContent();

        self::assertContains('SELECT entity_id FROM sales_order LIMIT 100', $this->statements);
        self::assertArrayHasKey(0, $content);
    }

    public function testDiXmlRegistersTheToolUnderItsOwnName(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../../etc/di.xml');
        self::assertNotFalse($xml, 'etc/di.xml is not readable.');

        $items = $xml->xpath(
            '//type[@name="Magebit\Mcp\Model\Tool\ToolRegistry"]/arguments/argument[@name="tools"]/item'
        );
        self::assertNotEmpty($items, 'di.xml registers no tools.');

        $registered = [];
        foreach ($items as $item) {
            $registered[(string) $item['name']] = (string) $item;
        }

        self::assertArrayHasKey('db.query', $registered);
        self::assertSame(Query::class, ltrim($registered['db.query'], '\\'));
    }

    /**
     * @dataProvider descriptionRuleProvider
     * @param string $fragment
     * @return void
     */
    public function testDescriptionStatesTheRulesTheLlmCannotDiscover(string $fragment): void
    {
        self::assertStringContainsStringIgnoringCase($fragment, $this->tool()->getDescription());
    }

    /**
     * @return array<string, array{string}>
     */
    public function descriptionRuleProvider(): array
    {
        return [
            'read only' => ['read-only'],
            'single statement' => ['single'],
            'allowed tables' => ['Allowed Tables'],
            'protected tables' => ['protected'],
            'no select star' => ['SELECT *'],
            'limit refused not clamped' => ['refused'],
            'table placeholder' => ['{{table_name}}'],
            'no backslashes' => ['backslash'],
            'doubled quote' => ["''"],
            'escape clause' => ['ESCAPE'],
            'builtin functions only' => ['stored function'],
            'group concat denied' => ['GROUP_CONCAT'],
            'json arrayagg denied' => ['JSON_ARRAYAGG'],
            'arithmetic multiplication refused' => ['SUM(qty * price)'],
            'substitution is textual' => ['string literals included'],
        ];
    }
}
