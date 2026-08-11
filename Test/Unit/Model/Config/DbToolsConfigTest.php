<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Test\Unit\Model\Config;

use Magebit\McpDbTools\Model\ColumnDenyList;
use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magebit\McpDbTools\Model\IdentifierMatcher;
use Magebit\McpDbTools\Model\QueryGuard;
use Magebit\McpDbTools\Model\Sql\Lexer;
use Magebit\McpDbTools\Model\Sql\TableReferenceScanner;
use Magebit\McpDbTools\Model\TableAccessPolicy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class DbToolsConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     * @return DbToolsConfig
     */
    private function config(array $values): DbToolsConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(static fn (string $path): mixed => $values[$path] ?? null);
        $scopeConfig->method('isSetFlag')
            ->willReturnCallback(static fn (string $path): bool => (bool) ($values[$path] ?? false));

        return new DbToolsConfig($scopeConfig);
    }

    /**
     * @return array<string, array{mixed, list<string>}>
     */
    public static function tableListProvider(): array
    {
        return [
            'null' => [null, []],
            'empty' => ['', []],
            'whitespace only' => ["  \n\t ", []],
            'non string' => [42, []],
            'single name' => ['sales_order', ['sales_order']],
            'comments and blanks skipped' => ["# note\n\nsales_order\n# another\n", ['sales_order']],
            'backticks stripped' => ['`sales_order`', ['sales_order']],
            'double quotes stripped' => ['"sales_order"', ['sales_order']],
            'single quotes stripped' => ["'sales_order'", ['sales_order']],
            'lower cased' => ['Sales_Order', ['sales_order']],
            'duplicates collapsed' => ["sales_order\nsales_order", ['sales_order']],
            'crlf endings' => ["sales_order\r\ncms_page", ['sales_order', 'cms_page']],
            'cr endings' => ["sales_order\rcms_page", ['sales_order', 'cms_page']],
        ];
    }

    /**
     * @dataProvider tableListProvider
     * @param mixed $raw
     * @param list<string> $expected
     * @return void
     */
    public function testAllowedTablesParsing(mixed $raw, array $expected): void
    {
        $config = $this->config([DbToolsConfig::XML_PATH_ALLOWED_TABLES => $raw]);

        self::assertSame($expected, $config->getAllowedTables());
    }

    /**
     * @return array<string, array{mixed, array<string, string>}>
     */
    public static function tableAclMapProvider(): array
    {
        return [
            'null' => [null, []],
            'empty' => ['', []],
            'whitespace only' => ["  \n\t ", []],
            'non string' => [42, []],
            'single mapping' => [
                'sales_order:Magento_Sales::sales_order',
                ['sales_order' => 'Magento_Sales::sales_order'],
            ],
            'comments and blanks skipped' => [
                "# note\n\nsales_order:Magento_Sales::sales_order\n",
                ['sales_order' => 'Magento_Sales::sales_order'],
            ],
            'lower cased table, resource left alone' => [
                'Sales_Order:Magento_Sales::sales_order',
                ['sales_order' => 'Magento_Sales::sales_order'],
            ],
            'quoting stripped from the table' => [
                '`sales_order`:Magento_Sales::sales_order',
                ['sales_order' => 'Magento_Sales::sales_order'],
            ],
            'surrounding whitespace trimmed' => [
                "  sales_order : Magento_Sales::sales_order  \n",
                ['sales_order' => 'Magento_Sales::sales_order'],
            ],
            'crlf endings' => [
                "sales_order:Magento_Sales::sales_order\r\ncms_page:Magento_Cms::page",
                ['sales_order' => 'Magento_Sales::sales_order', 'cms_page' => 'Magento_Cms::page'],
            ],
            'later line wins' => [
                "sales_order:Magento_Sales::sales_order\nsales_order:Magento_Sales::actions_view",
                ['sales_order' => 'Magento_Sales::actions_view'],
            ],
            'no separator dropped' => ['nonsense', []],
            'blank table dropped' => [':Magento_Sales::sales_order', []],
            'blank resource dropped' => ["sales_order:\n", []],
            'resource without a module dropped' => ['sales_order:sales_order', []],
            'resource with one colon dropped' => ['sales_order:Magento_Sales:sales_order', []],
            'resource with a dot dropped' => ['sales_order:Magento_Sales::sales.order', []],
        ];
    }

    /**
     * @dataProvider tableAclMapProvider
     * @param mixed $raw
     * @param array<string, string> $expected
     * @return void
     */
    public function testTableAclMapParsing(mixed $raw, array $expected): void
    {
        $config = $this->config([DbToolsConfig::XML_PATH_TABLE_ACL_MAP => $raw]);

        self::assertSame($expected, $config->getTableAclMap());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function positiveIntProvider(): array
    {
        return [
            'unset falls back' => [null, DbToolsConfig::DEFAULT_ROWS],
            'blank falls back' => ['', DbToolsConfig::DEFAULT_ROWS],
            'zero falls back' => ['0', DbToolsConfig::DEFAULT_ROWS],
            'negative falls back' => ['-5', DbToolsConfig::DEFAULT_ROWS],
            'non numeric falls back' => ['abc', DbToolsConfig::DEFAULT_ROWS],
            'valid is used' => ['250', 250],
        ];
    }

    /**
     * A cleared or malformed field must never widen a cap, and must never become 0 — applyRowLimit
     * would then cap every query at one row.
     *
     * @dataProvider positiveIntProvider
     * @param mixed $raw
     * @param int $expected
     * @return void
     */
    public function testDefaultRowsFallsBackSafely(mixed $raw, int $expected): void
    {
        $config = $this->config([DbToolsConfig::XML_PATH_DEFAULT_ROWS => $raw]);

        self::assertSame($expected, $config->getDefaultRows());
    }

    /**
     * @return void
     */
    public function testDisabledByDefault(): void
    {
        self::assertFalse($this->config([])->isEnabled());
    }

    /**
     * @return void
     */
    public function testExtraDeniedTablesUsesTheSameParser(): void
    {
        $config = $this->config([DbToolsConfig::XML_PATH_EXTRA_DENIED_TABLES => "# x\nmy_vault\n"]);

        self::assertSame(['my_vault'], $config->getExtraDeniedTables());
    }

    /**
     * @return void
     */
    public function testTheOtherCapsFallBackToTheirOwnDefaults(): void
    {
        $config = $this->config([]);

        self::assertSame(DbToolsConfig::DEFAULT_ROW_LIMIT_CEILING, $config->getRowLimitCeiling());
        self::assertSame(
            DbToolsConfig::DEFAULT_STATEMENT_TIMEOUT_SECONDS,
            $config->getStatementTimeoutSeconds()
        );
    }

    /**
     * The ceiling wins over a larger default, so a default nobody clamped cannot widen the cap.
     *
     * @return void
     */
    public function testTheCeilingClampsALargerDefaultThroughTheGuard(): void
    {
        $config = $this->config([
            DbToolsConfig::XML_PATH_DEFAULT_ROWS => '5000',
            DbToolsConfig::XML_PATH_ROW_LIMIT_CEILING => '1000',
            DbToolsConfig::XML_PATH_ALLOWED_TABLES => 'sales_order',
        ]);
        self::assertSame(5000, $config->getDefaultRows());

        $matcher = new IdentifierMatcher($this->createMock(DeploymentConfig::class));
        $guard = new QueryGuard(
            $config,
            new TableAccessPolicy($config, $matcher, [], [], []),
            new ColumnDenyList($matcher),
            new Lexer(),
            new TableReferenceScanner()
        );

        self::assertSame(
            'SELECT entity_id FROM sales_order LIMIT 1000',
            $guard->assertReadOnly('SELECT entity_id FROM sales_order')
        );
    }

    /**
     * @return void
     */
    public function testConfigPathsLiveUnderTheModuleOwnedSection(): void
    {
        self::assertSame('magebit_mcp_db_tools/general/enabled', DbToolsConfig::XML_PATH_ENABLED);
        self::assertSame('magebit_mcp_db_tools/general/allowed_tables', DbToolsConfig::XML_PATH_ALLOWED_TABLES);
        self::assertSame('magebit_mcp_db_tools/general/table_acl_map', DbToolsConfig::XML_PATH_TABLE_ACL_MAP);
    }

    /**
     * The default must not map anything: an unmapped table is refused, so a shipped mapping would
     * be the one thing that could open the tool without an operator deciding to.
     *
     * @return void
     */
    public function testTheShippedDefaultMapsNoTable(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../../etc/config.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        $values = $xml->xpath('/config/default/magebit_mcp_db_tools/general/table_acl_map');
        self::assertNotEmpty($values, 'etc/config.xml declares no table_acl_map default.');

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn((string) $values[0]);

        self::assertSame([], (new DbToolsConfig($scopeConfig))->getTableAclMap());
    }

    /**
     * @return void
     */
    public function testSectionDeclaresItsOwnAclResource(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../../etc/adminhtml/system.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        $resources = $xml->xpath('//section[@id="magebit_mcp_db_tools"]/resource');
        self::assertNotEmpty($resources);
        self::assertSame('Magebit_McpDbTools::config', (string) $resources[0]);
    }
}
