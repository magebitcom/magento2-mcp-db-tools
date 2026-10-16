<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Test\Api\Security;

use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Test\Api\McpTestCase;
use Magebit\Mcp\Test\Api\Security\AuditLogReader;
use Magebit\Mcp\Test\Api\Security\ConfigState;
use Magebit\Mcp\Test\Api\Security\ToolPayload;

/**
 * `db.query` only runs a single read of allowlisted, ACL-mapped tables. Protected tables,
 * writes, file access and unmapped tables are refused.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class DbQueryEscalationTest extends McpTestCase
{
    private const PATH_ENABLED = 'magebit_mcp_db_tools/general/enabled';
    private const PATH_ALLOWED_TABLES = 'magebit_mcp_db_tools/general/allowed_tables';
    private const PATH_TABLE_ACL_MAP = 'magebit_mcp_db_tools/general/table_acl_map';

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        ConfigState::restoreAll();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testEmptyMapRefusesEveryQueryThatReadsATable(): void
    {
        ConfigState::set(self::PATH_ENABLED, '1');
        ConfigState::set(self::PATH_ALLOWED_TABLES, 'sales_order');
        ConfigState::set(self::PATH_TABLE_ACL_MAP, '');

        $response = $this->toolsCall('db.query', ['query' => 'SELECT entity_id FROM sales_order LIMIT 1']);

        $this->assertJsonRpcError($response, ErrorCode::TOOL_EXECUTION_FAILED->value, 200);
        self::assertStringContainsString('no admin ACL mapping', $this->errorMessage($response));

        // A query that reads no table has nothing to map, so it runs.
        $this->assertJsonRpcSuccess($this->toolsCall('db.query', ['query' => 'SELECT 1']));
    }

    /**
     * @dataProvider blockedQueries
     * @param string $sql
     * @param string $expectedFragment
     * @return void
     */
    public function testBlockedQueryIsRefusedWithMap(string $sql, string $expectedFragment): void
    {
        $this->useMappedConfig();

        $response = $this->toolsCall('db.query', ['query' => $sql]);

        $this->assertJsonRpcError($response, ErrorCode::TOOL_EXECUTION_FAILED->value, 200);
        self::assertStringContainsString($expectedFragment, $this->errorMessage($response));
        $audit = AuditLogReader::latest('db.query', $this->tokenRowId);
        self::assertNotNull($audit, 'Expected an audit row for db.query.');
        self::assertSame('-32011', $audit['error_code'] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockedQueries(): array
    {
        $protected = 'is protected and cannot be queried';
        $mustBegin = 'The query must begin with one of';

        return [
            'admin_user' => ['SELECT user_id FROM admin_user', $protected],
            'core_config_data' => ['SELECT path FROM core_config_data', $protected],
            'oauth_token' => ['SELECT entity_id FROM oauth_token', $protected],
            'magebit_mcp_token' => ['SELECT id FROM magebit_mcp_token', $protected],
            'information_schema' => ['SELECT table_name FROM information_schema.tables', $protected],
            'allowlisted but unmapped' => ['SELECT entity_id FROM customer_entity LIMIT 1', 'no admin ACL mapping'],
            'not allowlisted' => [
                'SELECT entity_id FROM catalog_product_entity LIMIT 1',
                'is not in the Allowed Tables list',
            ],
            'two statements' => [
                'SELECT entity_id FROM sales_order; SELECT user_id FROM admin_user',
                'Only a single statement is accepted',
            ],
            'into outfile' => [
                "SELECT entity_id FROM sales_order INTO OUTFILE '/tmp/mcpsec'",
                'writes to the server filesystem',
            ],
            'load_file' => ["SELECT LOAD_FILE('/etc/passwd')", 'LOAD_FILE'],
            'wildcard' => ['SELECT * FROM sales_order', 'is not permitted'],
            'update' => ["UPDATE sales_order SET status = 'canceled'", $mustBegin],
            'delete' => ['DELETE FROM sales_order', $mustBegin],
        ];
    }

    /**
     * @return void
     */
    public function testAllowlistedMappedQuerySucceeds(): void
    {
        $this->useMappedConfig();

        $response = $this->toolsCall('db.query', ['query' => 'SELECT entity_id FROM sales_order LIMIT 1']);

        $this->assertJsonRpcSuccess($response);
        self::assertArrayHasKey('rows', ToolPayload::decode($response));
    }

    /**
     * Turn the tool on with two allowlisted tables, only `sales_order` mapped to an ACL.
     *
     * @return void
     */
    private function useMappedConfig(): void
    {
        ConfigState::set(self::PATH_ENABLED, '1');
        ConfigState::set(self::PATH_ALLOWED_TABLES, "sales_order\ncustomer_entity");
        ConfigState::set(self::PATH_TABLE_ACL_MAP, 'sales_order:Magento_Sales::sales_order');
    }

    /**
     * @phpstan-param array{status: int, headers: array<string, string>, body: array<string, mixed>|null} $response
     * @param array $response
     * @return string
     */
    private function errorMessage(array $response): string
    {
        $error = $response['body']['error'] ?? null;
        self::assertIsArray($error);

        return (string) ($error['message'] ?? '');
    }
}
