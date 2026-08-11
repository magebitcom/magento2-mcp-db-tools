<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Test\Unit\Model\Sql;

use Magebit\McpDbTools\Model\Sql\Lexer;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the byte-level layer the rest of the guard is built on: what the reconstructed SQL looks
 * like, what each token carries, and the shapes that never reach a later layer at all.
 */
class LexerTest extends TestCase
{
    /**
     * @return void
     */
    public function testCollapsesWhitespaceAndStripsComments(): void
    {
        $out = (new Lexer())->tokenize("SELECT   a /* note */\n FROM t -- trailing\n");

        self::assertSame('SELECT a FROM t', $out['sql']);
    }

    /**
     * @return void
     */
    public function testTokenTypesAndQuotedFlag(): void
    {
        $out = (new Lexer())->tokenize("SELECT `a`, 'x', 1 FROM t");
        $types = array_column($out['tokens'], 'type');

        self::assertContains(Lexer::TYPE_WORD, $types);
        self::assertContains(Lexer::TYPE_STRING, $types);
        self::assertContains(Lexer::TYPE_NUMBER, $types);
        self::assertContains(Lexer::TYPE_PUNCT, $types);
        self::assertTrue($out['tokens'][1]['quoted']);
    }

    /**
     * @return void
     */
    public function testRefusesTheShapesTheGuardDependsOn(): void
    {
        $lexer = new Lexer();
        $shapes = ['SELECT "x"', "SELECT 'a\\\\b'", 'SELECT /*! 1 */', "SELECT a\x00b", 'SELECT /* open'];
        $refused = [];

        foreach ($shapes as $sql) {
            try {
                $lexer->tokenize($sql);
            } catch (LocalizedException) {
                $refused[] = $sql;
            }
        }

        self::assertSame($shapes, $refused);
    }

    /**
     * Pins the security invariant: the token carries the undoubled value, but the reconstruction is
     * the source slice, so an embedded backtick stays doubled and can never become a live delimiter.
     *
     * @return void
     */
    public function testQuotedIdentifierUndoublesEmbeddedBackticks(): void
    {
        $out = (new Lexer())->tokenize('SELECT `we``ird` FROM t');

        self::assertSame('SELECT `we``ird` FROM t', $out['sql']);
        self::assertSame('we`ird', $out['tokens'][1]['value']);
        self::assertTrue($out['tokens'][1]['quoted']);
    }

    /**
     * The whole smuggled `FROM` must stay inside one identifier token, and the reconstruction must
     * keep the doubling that makes it one.
     *
     * @return void
     */
    public function testDoubledBacktickCannotReopenAsADelimiter(): void
    {
        $out = (new Lexer())->tokenize('SELECT `username`` FROM ``admin_user`');

        self::assertSame('SELECT `username`` FROM ``admin_user`', $out['sql']);
        self::assertCount(2, $out['tokens']);
        self::assertSame('username` FROM `admin_user', $out['tokens'][1]['value']);
        self::assertStringNotContainsString('FROM `admin_user`', $out['sql']);
    }

    /**
     * @return void
     */
    public function testOrdinaryQuotedIdentifiersAreUnchanged(): void
    {
        $out = (new Lexer())->tokenize('SELECT `entity_id` FROM `sales_order`');

        self::assertSame('SELECT `entity_id` FROM `sales_order`', $out['sql']);
        self::assertSame('entity_id', $out['tokens'][1]['value']);
        self::assertSame('sales_order', $out['tokens'][3]['value']);
    }

    /**
     * The `''` companion of the backtick case: `raw` is accumulated from the source, so a doubled
     * quote never reopens the literal.
     *
     * @return void
     */
    public function testDoubledSingleQuoteCannotEscapeTheLiteral(): void
    {
        $out = (new Lexer())->tokenize("SELECT a FROM t WHERE a = 'x'' FROM ''admin_user'");

        self::assertSame("SELECT a FROM t WHERE a = 'x'' FROM ''admin_user'", $out['sql']);
        self::assertSame(Lexer::TYPE_STRING, $out['tokens'][7]['type']);
        self::assertSame("'x'' FROM ''admin_user'", $out['tokens'][7]['value']);
        self::assertCount(8, $out['tokens']);
    }

    /**
     * @return void
     */
    public function testStringLiteralKeepsItsQuotesAndDoubledEscapes(): void
    {
        $out = (new Lexer())->tokenize("SELECT 'it''s' FROM t");

        self::assertSame("SELECT 'it''s' FROM t", $out['sql']);
        self::assertSame(Lexer::TYPE_STRING, $out['tokens'][1]['type']);
        self::assertSame("'it''s'", $out['tokens'][1]['value']);
        self::assertFalse($out['tokens'][1]['quoted']);
    }

    /**
     * @return void
     */
    public function testRefusesAnUnspacedDashDashComment(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('A `--` comment must be followed by whitespace. `--x` is ambiguous');

        (new Lexer())->tokenize("SELECT 1 --x\n");
    }

    /**
     * @return void
     */
    public function testRefusesTheMariaDbExecutableCommentForm(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The query contains a MySQL executable comment (`/*M!`).');

        (new Lexer())->tokenize('SELECT /*M! 1 */ 1');
    }

    /**
     * @return void
     */
    public function testRefusesAnUnresolvedTablePlaceholder(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The query contains `{`.');

        (new Lexer())->tokenize('SELECT a FROM {{table}}');
    }

    /**
     * @return void
     */
    public function testHashCommentIsStrippedAndLeavesASeparator(): void
    {
        $out = (new Lexer())->tokenize("SELECT a # note\nFROM t");

        self::assertSame('SELECT a FROM t', $out['sql']);
    }

    /**
     * @return void
     */
    public function testDigitsOnlyWordsAreNumbersAndMixedOnesAreWords(): void
    {
        $out = (new Lexer())->tokenize('SELECT 12, a1 FROM t');

        self::assertSame(Lexer::TYPE_NUMBER, $out['tokens'][1]['type']);
        self::assertSame(Lexer::TYPE_WORD, $out['tokens'][3]['type']);
    }
}
