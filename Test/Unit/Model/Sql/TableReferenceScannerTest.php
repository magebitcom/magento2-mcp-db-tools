<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Test\Unit\Model\Sql;

use Magebit\McpDbTools\Model\Sql\Lexer;
use Magebit\McpDbTools\Model\Sql\TableReferenceScanner;
use PHPUnit\Framework\TestCase;

/**
 * Pins which identifiers the scanner reads as table references. It makes no policy decision, so
 * every case here is about position alone — aliases, CTE names and function arguments are not tables.
 */
class TableReferenceScannerTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function extractionProvider(): array
    {
        return [
            'simple from' => ['SELECT a FROM sales_order', ['sales_order']],
            'join' => ['SELECT a FROM sales_order JOIN sales_order_item ON 1', ['sales_order', 'sales_order_item']],
            'comma list' => ['SELECT a FROM sales_order, cms_page', ['sales_order', 'cms_page']],
            'alias is not a table' => ['SELECT a FROM sales_order o', ['sales_order']],
            'derived table alias is not a table' => ['SELECT a FROM (SELECT 1 AS a) d', []],
            'cte name is not a table' => ['WITH x AS (SELECT 1 AS a) SELECT a FROM x', []],
            'cte body table is' => ['WITH x AS (SELECT a FROM cms_page) SELECT a FROM x', ['cms_page']],
            'function arg from is a column' => ['SELECT EXTRACT(YEAR FROM created_at) FROM sales_order', ['sales_order']],
            'union both branches' => ['SELECT a FROM cms_page UNION SELECT a FROM cms_block', ['cms_page', 'cms_block']],
            'where ends the table list' => ['SELECT a FROM cms_page WHERE a IN (1, 2)', ['cms_page']],
            'parenthesised table reference' => ['SELECT a FROM (cms_page)', ['cms_page']],
            'schema qualifier is consumed with its name' => ['SELECT a FROM magento.cms_page', ['cms_page']],
            'quoted table reference' => ['SELECT a FROM `cms_page`', ['cms_page']],
            'recursive cte self reference is the cte' => [
                'WITH RECURSIVE x AS (SELECT a FROM x) SELECT a FROM x',
                [],
            ],
            'plain cte self reference is a table' => [
                'WITH x AS (SELECT a FROM x) SELECT a FROM x',
                ['x'],
            ],
            'window with rollup does not open a cte list' => [
                'SELECT a FROM cms_page GROUP BY a WITH ROLLUP',
                ['cms_page'],
            ],
            'straight_join as a select modifier takes no table' => [
                'SELECT STRAIGHT_JOIN a FROM cms_page',
                ['cms_page'],
            ],
            'straight_join as a join takes one' => [
                'SELECT a FROM cms_page STRAIGHT_JOIN cms_block ON 1',
                ['cms_page', 'cms_block'],
            ],
            'dual is not a table' => ['SELECT 1 FROM DUAL', []],
        ];
    }

    /**
     * @dataProvider extractionProvider
     * @param string $sql
     * @param list<string> $expected
     * @return void
     */
    public function testExtractsOnlyIdentifiersInTablePositions(string $sql, array $expected): void
    {
        $lexed = (new Lexer())->tokenize($sql);
        $leading = strtoupper($lexed['tokens'][0]['value']);

        $tables = (new TableReferenceScanner())->extractTables($lexed['tokens'], $leading);

        self::assertSame($expected, $tables);
    }

    /**
     * @return void
     */
    public function testDescribeTargetIsATableReference(): void
    {
        $lexed = (new Lexer())->tokenize('DESCRIBE cms_page');

        $tables = (new TableReferenceScanner())->extractTables($lexed['tokens'], 'DESCRIBE');

        self::assertSame(['cms_page'], $tables);
    }

    /**
     * @return void
     */
    public function testShowColumnsInNamesATableButPlainInDoesNot(): void
    {
        $scanner = new TableReferenceScanner();
        $lexer = new Lexer();

        $show = $scanner->extractTables($lexer->tokenize('SHOW COLUMNS IN cms_page')['tokens'], 'SHOW');
        $select = $scanner->extractTables($lexer->tokenize('SELECT a FROM t WHERE a IN (1)')['tokens'], 'SELECT');

        self::assertSame(['cms_page'], $show);
        self::assertSame(['t'], $select);
    }

    /**
     * @return void
     */
    public function testReferencesCarryTheSchemaQualifierSeparately(): void
    {
        $lexed = (new Lexer())->tokenize('SELECT a FROM other.cms_page JOIN cms_block ON 1');

        $references = (new TableReferenceScanner())->extractReferences($lexed['tokens'], 'SELECT');

        self::assertSame(
            [
                ['schema' => 'other', 'name' => 'cms_page'],
                ['schema' => null, 'name' => 'cms_block'],
            ],
            $references
        );
    }
}
