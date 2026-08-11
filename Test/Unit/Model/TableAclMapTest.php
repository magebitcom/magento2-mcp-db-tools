<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Test\Unit\Model;

use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magebit\McpDbTools\Model\IdentifierMatcher;
use Magebit\McpDbTools\Model\TableAclMap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use PHPUnit\Framework\TestCase;

/**
 * The map is only half the gate: an unresolved table has to be visible to the caller through
 * {@see TableAclMap::unmapped()}, because an empty resolve() is read as "no opinion".
 */
class TableAclMapTest extends TestCase
{
    /**
     * @param string $raw
     * @param string $tablePrefix
     * @return TableAclMap
     */
    private function map(string $raw, string $tablePrefix = ''): TableAclMap
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): mixed => $path === DbToolsConfig::XML_PATH_TABLE_ACL_MAP ? $raw : null
        );

        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path): ?string => $path === ConfigOptionsListConstants::CONFIG_PATH_DB_PREFIX
                ? $tablePrefix
                : null
        );

        return new TableAclMap(new DbToolsConfig($scopeConfig), new IdentifierMatcher($deploymentConfig));
    }

    /**
     * @return void
     */
    public function testResolvesMappedTablesAndDeduplicates(): void
    {
        $map = $this->map("sales_order:Magento_Sales::sales_order\nsales_order_item:Magento_Sales::sales_order");

        self::assertSame(
            ['Magento_Sales::sales_order'],
            $map->resolve(['sales_order', 'sales_order_item'])
        );
    }

    /**
     * @return void
     */
    public function testReportsUnmappedTables(): void
    {
        $map = $this->map('sales_order:Magento_Sales::sales_order');

        self::assertSame(['cms_page'], $map->unmapped(['sales_order', 'cms_page']));
    }

    /**
     * @return void
     */
    public function testMalformedLinesAreIgnoredNotFatal(): void
    {
        $map = $this->map("# note\n\nnonsense\nsales_order:Magento_Sales::sales_order\n:\nfoo:\n");

        self::assertSame(['Magento_Sales::sales_order'], $map->resolve(['sales_order']));
    }

    /**
     * @return void
     */
    public function testNeverReturnsABlankResourceBecauseThatIsAHardDeny(): void
    {
        $map = $this->map("sales_order:\n");

        self::assertSame([], $map->resolve(['sales_order']));
    }

    /**
     * A blank resource must not count as mapped either, or the table would skip both halves of
     * the gate.
     *
     * @return void
     */
    public function testATableWithABlankResourceIsReportedAsUnmapped(): void
    {
        $map = $this->map("sales_order:\n");

        self::assertSame(['sales_order'], $map->unmapped(['sales_order']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedResourceProvider(): array
    {
        return [
            'no module separator' => ['sales_order:sales_order'],
            'single colon' => ['sales_order:Magento_Sales:sales_order'],
            'trailing separator' => ['sales_order:Magento_Sales::'],
            'space inside the resource' => ['sales_order:Magento_Sales:: sales order'],
            'dotted resource id' => ['sales_order:Magento_Sales::sales.order'],
        ];
    }

    /**
     * @dataProvider malformedResourceProvider
     * @param string $line
     * @return void
     */
    public function testAResourceThatCouldNeverResolveLeavesTheTableUnmapped(string $line): void
    {
        $map = $this->map($line);

        self::assertSame([], $map->resolve(['sales_order']));
        self::assertSame(['sales_order'], $map->unmapped(['sales_order']));
    }

    /**
     * Entries are written without the install's table prefix, exactly like the allowlist, so the
     * prefixed name the statement actually carries has to match them.
     *
     * @return void
     */
    public function testMatchesThePrefixedNameTheStatementCarries(): void
    {
        $map = $this->map('sales_order:Magento_Sales::sales_order', 'mg_');

        self::assertSame(['Magento_Sales::sales_order'], $map->resolve(['mg_sales_order']));
        self::assertSame([], $map->unmapped(['mg_sales_order']));
    }

    /**
     * @return void
     */
    public function testMatchingIsCaseInsensitiveAndIgnoresQuoting(): void
    {
        $map = $this->map('sales_order:Magento_Sales::sales_order');

        self::assertSame(['Magento_Sales::sales_order'], $map->resolve(['`Sales_Order`']));
    }

    /**
     * A wider name must not borrow a narrower entry's resource: `sales_order_item` is not
     * `sales_order`.
     *
     * @return void
     */
    public function testDoesNotMatchAWiderNameThanTheEntry(): void
    {
        $map = $this->map('sales_order:Magento_Sales::sales_order');

        self::assertSame([], $map->resolve(['sales_order_item']));
        self::assertSame(['sales_order_item'], $map->unmapped(['sales_order_item']));
    }

    /**
     * @return void
     */
    public function testAnEmptyMapResolvesNothingAndReportsEverything(): void
    {
        $map = $this->map('');

        self::assertSame([], $map->resolve(['sales_order']));
        self::assertSame(['sales_order'], $map->unmapped(['sales_order']));
    }

    /**
     * @return void
     */
    public function testNoTablesMeansNoResourcesAndNothingMissing(): void
    {
        $map = $this->map('sales_order:Magento_Sales::sales_order');

        self::assertSame([], $map->resolve([]));
        self::assertSame([], $map->unmapped([]));
    }

    /**
     * @return void
     */
    public function testUnmappedTablesAreReportedOnceEachInTheOrderWritten(): void
    {
        $map = $this->map('sales_order:Magento_Sales::sales_order');

        self::assertSame(
            ['cms_page', 'cms_block'],
            $map->unmapped(['cms_page', 'sales_order', 'cms_block', 'cms_page'])
        );
    }
}
