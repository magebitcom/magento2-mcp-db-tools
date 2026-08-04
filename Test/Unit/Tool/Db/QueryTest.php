<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Test\Unit\Tool\Db;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magebit\McpDbTools\Model\QueryGuard;
use Magebit\McpDbTools\Tool\Db\Query;
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
    /** @var list<string> */
    private array $statements = [];

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

        $this->config = $this->createMock(DbToolsConfig::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getStatementTimeoutSeconds')->willReturn(10);
        $this->config->method('getRowLimitCeiling')->willReturn(1000);

        $this->guard = $this->createMock(QueryGuard::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @return Query
     */
    private function tool(): Query
    {
        return new Query($this->config, $this->guard, $this->resourceConnection, $this->logger);
    }

    /**
     * Records every statement the tool sends, in order, and answers `fetchAll` with $rows.
     *
     * @param list<array<string, mixed>> $rows
     * @return void
     */
    private function recordStatements(array $rows = []): void
    {
        $this->connection->method('query')->willReturnCallback(
            function (string $sql) {
                $this->statements[] = $sql;
                return $this->createMock(\Zend_Db_Statement_Interface::class);
            }
        );
        $this->connection->method('fetchAll')->willReturnCallback(
            function (string $sql) use ($rows): array {
                $this->statements[] = $sql;
                return $rows;
            }
        );
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

        $tool = new Query($config, $this->guard, $this->resourceConnection, $this->logger);

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
        self::assertSame($validated, $this->statements[count($this->statements) - 1]);
        self::assertSame($validated, $response['executed_sql']);
    }

    public function testGuardRejectionPropagatesWithoutExecuting(): void
    {
        $this->guard->method('assertReadOnly')
            ->willThrowException(new LocalizedException(__('`*` is not permitted.')));

        $this->resourceConnection->expects(self::never())->method('getConnection');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('`*` is not permitted.');

        $this->tool()->execute(['query' => 'SELECT * FROM sales_order']);
    }

    public function testGuardConfigurationMessageSurfacesVerbatim(): void
    {
        $message = 'No tables are listed in the Allowed Tables list '
            . '(magebit_mcp/db_tools/allowed_tables), so every query is refused.';
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
        $this->guard->method('assertReadOnly')->willReturnCallback(
            static function (string $sql) use (&$seen): string {
                $seen = $sql;
                return $sql;
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

        $this->runTool(['query' => 'SELECT entity_id FROM sales_order']);

        self::assertCount(3, $this->statements);
        self::assertSame('SET SESSION max_execution_time = 10000', $this->statements[0]);
        self::assertStringContainsString('sql_mode', $this->statements[1]);
        self::assertStringContainsString('NO_BACKSLASH_ESCAPES', $this->statements[1]);
        self::assertSame('SELECT entity_id FROM sales_order LIMIT 100', $this->statements[2]);
    }

    public function testMariaDbEquivalentIsIssuedWhenMaxExecutionTimeIsUnknown(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');

        $this->connection->method('query')->willReturnCallback(
            function (string $sql) {
                $this->statements[] = $sql;
                if (str_contains($sql, 'max_execution_time')) {
                    throw new \RuntimeException('Unknown system variable');
                }
                return $this->createMock(\Zend_Db_Statement_Interface::class);
            }
        );
        $this->connection->method('fetchAll')->willReturnCallback(
            function (string $sql): array {
                $this->statements[] = $sql;
                return [['entity_id' => '1']];
            }
        );

        $response = $this->runTool(['query' => 'SELECT entity_id FROM sales_order']);

        self::assertSame('SET SESSION max_statement_time = 10', $this->statements[1]);
        self::assertSame(1, $response['row_count']);
    }

    public function testSessionGuardFailureIsNonFatal(): void
    {
        $this->guard->method('assertReadOnly')->willReturn('SELECT entity_id FROM sales_order LIMIT 100');

        $this->connection->method('query')->willReturnCallback(
            function (string $sql): void {
                $this->statements[] = $sql;
                throw new \RuntimeException('SET refused');
            }
        );
        $this->connection->method('fetchAll')->willReturnCallback(
            function (string $sql): array {
                $this->statements[] = $sql;
                return [['entity_id' => '1']];
            }
        );
        $this->logger->expects(self::atLeastOnce())->method('warning');

        $response = $this->runTool(['query' => 'SELECT entity_id FROM sales_order']);

        self::assertSame(1, $response['row_count']);
        self::assertSame('SELECT entity_id FROM sales_order LIMIT 100', $this->statements[count($this->statements) - 1]);
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
        $tool = new Query($config, $this->guard, $this->resourceConnection, $this->logger);

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
        self::assertNull($tool->getUnderlyingAclResource());
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
        ];
    }
}
