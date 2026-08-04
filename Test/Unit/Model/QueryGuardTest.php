<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Test\Unit\Model;

use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magebit\McpDbTools\Model\DenyList;
use Magebit\McpDbTools\Model\QueryGuard;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * Bypass-focused coverage of the eight guard layers. Every rejection asserts on the
 * message so a refactor cannot quietly swap one layer's verdict for another's.
 */
class QueryGuardTest extends TestCase
{
    /**
     * @param int $defaultRows
     * @param int $ceiling
     * @param list<string> $extraDeniedTables
     * @param string $tablePrefix
     * @return QueryGuard
     */
    private function guard(
        int $defaultRows = 100,
        int $ceiling = 1000,
        array $extraDeniedTables = [],
        string $tablePrefix = ''
    ): QueryGuard {
        $config = $this->createMock(DbToolsConfig::class);
        $config->method('getDefaultRows')->willReturn($defaultRows);
        $config->method('getRowLimitCeiling')->willReturn($ceiling);
        $config->method('getExtraDeniedTables')->willReturn($extraDeniedTables);

        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn($tablePrefix);

        return new QueryGuard($config, new DenyList($config, $deploymentConfig));
    }

    /**
     * @param string $sql
     * @param string $expectedMessageFragment
     * @return void
     */
    private function assertRejected(string $sql, string $expectedMessageFragment): void
    {
        try {
            $this->guard()->assertReadOnly($sql);
            $this->fail(sprintf('Expected rejection of: %s', $sql));
        } catch (LocalizedException $e) {
            $this->assertStringContainsStringIgnoringCase(
                $expectedMessageFragment,
                $e->getMessage(),
                sprintf('Wrong rejection reason for: %s', $sql)
            );
        }
    }

    // ---------------------------------------------------------------- layer 1: comments

    public function testStripsLineCommentBeforeAnalysis(): void
    {
        $this->assertSame(
            'SELECT entity_id FROM catalog_product_entity LIMIT 100',
            $this->guard()->assertReadOnly("SELECT entity_id FROM catalog_product_entity -- trailing note")
        );
    }

    public function testStripsHashComment(): void
    {
        $this->assertSame(
            'SELECT entity_id FROM catalog_product_entity LIMIT 100',
            $this->guard()->assertReadOnly("SELECT entity_id # why\nFROM catalog_product_entity")
        );
    }

    public function testStripsBlockComment(): void
    {
        $this->assertSame(
            'SELECT entity_id FROM catalog_product_entity LIMIT 100',
            $this->guard()->assertReadOnly('SELECT /* just the id */ entity_id FROM catalog_product_entity')
        );
    }

    public function testRejectsMysqlExecutableComment(): void
    {
        $this->assertRejected('SELECT/*!32302 1/*/1', 'executable comment');
    }

    public function testRejectsMysqlExecutableCommentHidingKeyword(): void
    {
        $this->assertRejected("SELECT entity_id FROM t /*!50000 INTO OUTFILE '/tmp/x' */", 'executable comment');
    }

    public function testRejectsOptimizerHintComment(): void
    {
        $this->assertRejected('SELECT /*+ MAX_EXECUTION_TIME(1) */ entity_id FROM t', 'executable comment');
    }

    public function testRejectsUnterminatedBlockComment(): void
    {
        $this->assertRejected('SELECT entity_id FROM t /* never closed', 'unterminated');
    }

    public function testRejectsAmbiguousDoubleDash(): void
    {
        // `1--1` is arithmetic in MySQL, not a comment; stripping it would change what runs.
        $this->assertRejected('SELECT 1--1', '--');
    }

    public function testRejectsKeywordSplitByComment(): void
    {
        $this->assertRejected('SEL/**/ECT entity_id FROM t', 'must begin with');
    }

    public function testCommentStrippingDoesNotTouchStringLiterals(): void
    {
        $this->assertSame(
            "SELECT entity_id FROM customer_entity WHERE firstname = '-- # /* not a comment' LIMIT 100",
            $this->guard()->assertReadOnly(
                "SELECT entity_id FROM customer_entity WHERE firstname = '-- # /* not a comment'"
            )
        );
    }

    // ---------------------------------------------------------------- layer 2: single statement

    public function testRejectsStackedStatements(): void
    {
        $this->assertRejected('SELECT 1; DROP TABLE x', 'single statement');
    }

    public function testRejectsStatementSeparatorAnywhereInside(): void
    {
        $this->assertRejected('SELECT 1 ; SELECT 2 ', 'single statement');
    }

    public function testAllowsTrailingSemicolon(): void
    {
        $this->assertSame(
            'SELECT entity_id FROM catalog_product_entity LIMIT 100',
            $this->guard()->assertReadOnly('SELECT entity_id FROM catalog_product_entity ;  ')
        );
    }

    public function testSemicolonInsideLiteralIsNotAStatementSeparator(): void
    {
        $this->assertSame(
            "SELECT entity_id FROM customer_entity WHERE firstname = 'a;b' LIMIT 100",
            $this->guard()->assertReadOnly("SELECT entity_id FROM customer_entity WHERE firstname = 'a;b'")
        );
    }

    public function testRejectsEmptyQuery(): void
    {
        $this->assertRejected('', 'empty');
    }

    public function testRejectsWhitespaceOnlyQuery(): void
    {
        $this->assertRejected("  \n\t ", 'empty');
    }

    public function testRejectsCommentOnlyQuery(): void
    {
        $this->assertRejected('/* nothing here */', 'empty');
    }

    public function testRejectsNulByte(): void
    {
        $this->assertRejected("SELECT entity_id FROM t\0", 'control character');
    }

    // ---------------------------------------------------------------- layer 3: leading keyword

    /**
     * @return array<string, array{string}>
     */
    public static function allowedLeadingKeywordProvider(): array
    {
        return [
            'select' => ['SELECT entity_id FROM catalog_product_entity'],
            'with' => ['WITH c AS (SELECT entity_id FROM catalog_product_entity) SELECT entity_id FROM c'],
            'show' => ['SHOW TABLES'],
            'describe' => ['DESCRIBE customer_entity'],
            'desc' => ['DESC customer_entity'],
            'explain' => ['EXPLAIN SELECT entity_id FROM catalog_product_entity'],
        ];
    }

    /**
     * @dataProvider allowedLeadingKeywordProvider
     * @param string $sql
     * @return void
     */
    public function testAcceptsAllowedLeadingKeywords(string $sql): void
    {
        $this->assertNotSame('', $this->guard()->assertReadOnly($sql));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedLeadingTokenProvider(): array
    {
        return [
            'update' => ['UPDATE customer_entity SET firstname = 1'],
            'insert' => ['INSERT INTO customer_entity (entity_id) VALUES (1)'],
            'call' => ['CALL some_proc()'],
            'leading paren' => ['(SELECT entity_id FROM t) UNION (SELECT entity_id FROM t2)'],
            'leading literal' => ["'SELECT' entity_id"],
        ];
    }

    /**
     * @dataProvider rejectedLeadingTokenProvider
     * @param string $sql
     * @return void
     */
    public function testRejectsDisallowedLeadingToken(string $sql): void
    {
        $this->assertRejected($sql, 'must begin with');
    }

    public function testAcceptsMixedCaseLeadingKeyword(): void
    {
        $this->assertSame(
            'SeLeCt entity_id FROM catalog_product_entity LIMIT 100',
            $this->guard()->assertReadOnly('SeLeCt entity_id FROM catalog_product_entity')
        );
    }

    public function testCollapsesTabsAndNewlinesBetweenTokens(): void
    {
        $this->assertSame(
            'SELECT entity_id, sku FROM catalog_product_entity LIMIT 100',
            $this->guard()->assertReadOnly("SELECT\n\tentity_id,\r\n sku\tFROM\n\ncatalog_product_entity")
        );
    }

    // ---------------------------------------------------------------- layer 4: keyword denylist

    /**
     * @return array<string, array{string, string}>
     */
    public static function deniedKeywordProvider(): array
    {
        return [
            'INSERT' => ['SELECT entity_id FROM t UNION INSERT', 'INSERT'],
            'UPDATE' => ['SELECT entity_id FROM t FOR UPDATE', 'UPDATE'],
            'DELETE' => ['SELECT entity_id FROM t WHERE delete = 1', 'DELETE'],
            'REPLACE' => ["SELECT REPLACE(sku, 'a', 'b') FROM t", 'REPLACE'],
            'DROP' => ['SELECT entity_id FROM t WHERE 1 = 1 AND DROP', 'DROP'],
            'ALTER' => ['SELECT ALTER FROM t', 'ALTER'],
            'CREATE' => ['SHOW CREATE TABLE customer_entity', 'CREATE'],
            'TRUNCATE' => ['SELECT TRUNCATE(1, 1) FROM t', 'TRUNCATE'],
            'RENAME' => ['SELECT RENAME FROM t', 'RENAME'],
            'GRANT' => ['SELECT GRANT FROM t', 'GRANT'],
            'REVOKE' => ['SELECT REVOKE FROM t', 'REVOKE'],
            'COMMIT' => ['SELECT COMMIT FROM t', 'COMMIT'],
            'ROLLBACK' => ['SELECT ROLLBACK FROM t', 'ROLLBACK'],
            'SAVEPOINT' => ['SELECT SAVEPOINT FROM t', 'SAVEPOINT'],
            'LOCK' => ['SELECT entity_id FROM t LOCK IN SHARE MODE', 'LOCK'],
            'UNLOCK' => ['SELECT UNLOCK FROM t', 'UNLOCK'],
            'CALL' => ['SELECT entity_id FROM t WHERE 1 = 1 AND CALL', 'CALL'],
            'HANDLER' => ['SELECT HANDLER FROM t', 'HANDLER'],
            'LOAD' => ['SELECT LOAD FROM t', 'LOAD'],
            'PREPARE' => ['SELECT PREPARE FROM t', 'PREPARE'],
            'EXECUTE' => ['SELECT EXECUTE FROM t', 'EXECUTE'],
            'DEALLOCATE' => ['SELECT DEALLOCATE FROM t', 'DEALLOCATE'],
            'SET' => ['SELECT entity_id FROM t WHERE SET = 1', 'SET'],
            'FLUSH' => ['SELECT FLUSH FROM t', 'FLUSH'],
            'RESET' => ['SELECT RESET FROM t', 'RESET'],
            'SHUTDOWN' => ['SELECT SHUTDOWN FROM t', 'SHUTDOWN'],
            'KILL' => ['SELECT KILL FROM t', 'KILL'],
            'INSTALL' => ['SELECT INSTALL FROM t', 'INSTALL'],
            'BINLOG' => ['SELECT BINLOG FROM t', 'BINLOG'],
            'PURGE' => ['SELECT PURGE FROM t', 'PURGE'],
            'OPTIMIZE' => ['SELECT OPTIMIZE FROM t', 'OPTIMIZE'],
            'REPAIR' => ['SELECT REPAIR FROM t', 'REPAIR'],
            'ANALYZE' => ['EXPLAIN ANALYZE SELECT entity_id FROM t', 'ANALYZE'],
            'ANALYSE' => ['SELECT entity_id FROM t PROCEDURE ANALYSE()', 'PROCEDURE'],
            'CHECKSUM' => ['SELECT CHECKSUM FROM t', 'CHECKSUM'],
            'LOAD_FILE' => ["SELECT LOAD_FILE('/etc/passwd')", 'LOAD_FILE'],
            'SLEEP' => ['SELECT sleep(30)', 'SLEEP'],
            'BENCHMARK' => ["SELECT BENCHMARK(10000000, MD5('a'))", 'BENCHMARK'],
            'GET_LOCK' => ["SELECT GET_LOCK('a', 10)", 'GET_LOCK'],
            'SYS_EXEC' => ["SELECT SYS_EXEC('id')", 'SYS_EXEC'],
        ];
    }

    /**
     * @dataProvider deniedKeywordProvider
     * @param string $sql
     * @param string $keyword
     * @return void
     */
    public function testRejectsDeniedKeyword(string $sql, string $keyword): void
    {
        $this->assertRejected($sql, $keyword);
    }

    public function testRejectsIntoOutfile(): void
    {
        $this->assertRejected("SELECT 1 INTO OUTFILE '/tmp/x'", 'OUTFILE');
    }

    public function testRejectsIntoDumpfile(): void
    {
        $this->assertRejected("SELECT 1 INTO DUMPFILE '/tmp/x'", 'DUMPFILE');
    }

    public function testRejectsIntoUserVariable(): void
    {
        $this->assertRejected('SELECT entity_id INTO @x FROM customer_entity', 'variable');
    }

    public function testRejectsSystemVariableRead(): void
    {
        $this->assertRejected('SELECT @@version', 'variable');
    }

    public function testRejectsUserVariableAssignment(): void
    {
        $this->assertRejected('SELECT @x := entity_id FROM customer_entity', 'variable');
    }

    public function testAllowsAtSignInsideStringLiteral(): void
    {
        // The `@` rule must not break the most common read filter there is.
        $sql = "SELECT entity_id FROM customer_entity WHERE email LIKE '%@example.com'";
        $this->assertSame($sql . ' LIMIT 100', $this->guard()->assertReadOnly($sql));
    }

    public function testRejectsShowGrants(): void
    {
        $this->assertRejected('SHOW GRANTS', 'GRANTS');
    }

    public function testSemicolonInsideQuotedIdentifierIsNotAStatementSeparator(): void
    {
        $sql = 'SELECT `weird;name` FROM catalog_product_entity';
        $this->assertSame($sql . ' LIMIT 100', $this->guard()->assertReadOnly($sql));
    }

    public function testRejectsDeniedKeywordAcrossWhitespaceAndCommentNoise(): void
    {
        $this->assertRejected(
            "SeLeCt\n entity_id\tFROM customer_entity WHERE 1 = 1 /* x */ AnD BeNcHmArK(1, MD5('a')) > 0",
            'BENCHMARK'
        );
    }

    public function testAllowsDeniedKeywordInsideStringLiteral(): void
    {
        // False-positive guard: a literal that happens to read like DDL must not be rejected.
        $this->assertSame(
            "SELECT entity_id FROM customer_entity WHERE firstname = 'Update' LIMIT 100",
            $this->guard()->assertReadOnly("SELECT entity_id FROM customer_entity WHERE firstname = 'Update'")
        );
    }

    public function testAllowsSentenceLiteralContainingDeniedKeywords(): void
    {
        $sql = "SELECT entity_id FROM customer_entity WHERE firstname = 'update my address; drop it'";
        $this->assertSame($sql . ' LIMIT 100', $this->guard()->assertReadOnly($sql));
    }

    // ------------------------------------------------ literal-boundary bypass attempts

    public function testRejectsDangerousCodeHiddenBehindDoubleDashLiteral(): void
    {
        // A naive comment stripper truncates at the `--` inside the literal and never sees
        // the rest of the statement.
        $this->assertRejected(
            "SELECT entity_id FROM customer_entity WHERE firstname = '--' AND password_hash = 'x'",
            'password_hash'
        );
    }

    public function testRejectsDangerousCodeAfterEscapedQuoteLiteral(): void
    {
        $this->assertRejected(
            "SELECT entity_id FROM customer_entity WHERE firstname = 'it\\'s' AND password_hash = 'x'",
            'password_hash'
        );
    }

    public function testRejectsDangerousCodeAfterDoubledQuoteLiteral(): void
    {
        $this->assertRejected(
            "SELECT entity_id FROM customer_entity WHERE firstname = 'a''b' AND rp_token = 'x'",
            'rp_token'
        );
    }

    public function testRejectsDeniedTableInUnionAfterLiteral(): void
    {
        $this->assertRejected(
            "SELECT email FROM customer_entity WHERE firstname = 'x' UNION SELECT email FROM admin_user",
            'admin_user'
        );
    }

    public function testAllowsMultipleLiteralsThatEachContainDeniedWords(): void
    {
        $sql = "SELECT entity_id FROM customer_entity WHERE firstname = 'admin_user' AND lastname = 'password'";
        $this->assertSame($sql . ' LIMIT 100', $this->guard()->assertReadOnly($sql));
    }

    public function testRejectsDoubleQuotedLiteral(): void
    {
        $this->assertRejected('SELECT entity_id FROM customer_entity WHERE firstname = "Update"', 'single quotes');
    }

    public function testRejectsUnterminatedStringLiteral(): void
    {
        $this->assertRejected("SELECT entity_id FROM customer_entity WHERE firstname = 'oops", 'unterminated');
    }

    // ---------------------------------------------------------------- layer 5: table denylist

    /**
     * @return array<string, array{string, string}>
     */
    public static function deniedTableProvider(): array
    {
        return [
            'admin_user' => ['SELECT c.email FROM admin_user c', 'admin_user'],
            'admin_passwords' => ['SELECT password_expires FROM admin_passwords', 'admin_passwords'],
            'admin_user_session' => ['SELECT session_id FROM admin_user_session', 'admin_user_session'],
            'admin_user_expiration' => ['SELECT expires_at FROM admin_user_expiration', 'admin_user_expiration'],
            'oauth_token' => ['SELECT entity_id FROM oauth_token', 'oauth_token'],
            'oauth_consumer' => ['SELECT entity_id FROM oauth_consumer', 'oauth_consumer'],
            'oauth_token_request_log' => ['SELECT log_id FROM oauth_token_request_log', 'oauth_token_request_log'],
            'magebit_mcp_token' => ['SELECT token_id FROM magebit_mcp_token', 'magebit_mcp_token'],
            'magebit_mcp_oauth_client' => ['SELECT client_id FROM magebit_mcp_oauth_client', 'magebit_mcp_oauth_client'],
            'magebit_mcp_oauth_auth_code' => [
                'SELECT code_id FROM magebit_mcp_oauth_auth_code',
                'magebit_mcp_oauth_auth_code',
            ],
            'magebit_mcp_oauth_refresh_token' => [
                'SELECT id FROM magebit_mcp_oauth_refresh_token',
                'magebit_mcp_oauth_refresh_token',
            ],
            'persistent_session' => ['SELECT customer_id FROM persistent_session', 'persistent_session'],
            'password_reset_request_event' => [
                'SELECT event_id FROM password_reset_request_event',
                'password_reset_request_event',
            ],
        ];
    }

    /**
     * @dataProvider deniedTableProvider
     * @param string $sql
     * @param string $table
     * @return void
     */
    public function testRejectsDeniedTable(string $sql, string $table): void
    {
        $this->assertRejected($sql, $table);
    }

    public function testRejectsPrefixedDeniedTable(): void
    {
        $guard = $this->guard(100, 1000, [], 'mage_');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/admin_user/');
        $guard->assertReadOnly('SELECT email FROM mage_admin_user');
    }

    public function testRejectsBacktickQuotedDeniedTable(): void
    {
        $this->assertRejected('SELECT email FROM `admin_user` AS a', 'admin_user');
    }

    public function testRejectsDeniedTableWithNewlinesAndTabs(): void
    {
        $this->assertRejected("SELECT\temail\nFROM\n\t`admin_user`", 'admin_user');
    }

    public function testRejectsDeniedTableInSubquery(): void
    {
        $this->assertRejected(
            'SELECT entity_id FROM customer_entity WHERE entity_id IN (SELECT user_id FROM admin_user)',
            'admin_user'
        );
    }

    public function testRejectsDeniedTableInJoin(): void
    {
        $this->assertRejected(
            'SELECT c.email FROM customer_entity c JOIN admin_user u ON u.user_id = c.entity_id',
            'admin_user'
        );
    }

    public function testRejectsExtraDeniedTableFromConfig(): void
    {
        $guard = $this->guard(100, 1000, ['my_vault']);
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/my_vault/');
        $guard->assertReadOnly('SELECT a FROM my_vault');
    }

    public function testRejectsIdentifierContainingDeniedTableComponents(): void
    {
        // Fail-closed: an unknown wrapper table around a protected name is still refused.
        $this->assertRejected('SELECT a FROM custom_admin_user_map', 'admin_user');
    }

    public function testRejectsMysqlSystemSchema(): void
    {
        $this->assertRejected('SELECT host FROM mysql.user', 'mysql');
    }

    public function testRejectsProcesslist(): void
    {
        $this->assertRejected('SHOW PROCESSLIST', 'processlist');
    }

    public function testAllowsPermittedTable(): void
    {
        $this->assertSame(
            'SELECT entity_id, email FROM customer_entity LIMIT 100',
            $this->guard()->assertReadOnly('SELECT entity_id, email FROM customer_entity')
        );
    }

    // ---------------------------------------------------------------- layer 6: column denylist

    /**
     * @return array<string, array{string, string}>
     */
    public static function deniedColumnProvider(): array
    {
        return [
            'password_hash' => ['select password_hash from customer_entity where 1=1 -- x', 'password_hash'],
            'password' => ['SELECT password FROM customer_entity', 'password'],
            'rp_token' => ['SELECT rp_token FROM customer_entity', 'rp_token'],
            'rp_token_created_at' => ['SELECT rp_token_created_at FROM customer_entity', 'rp_token'],
            'x_forwarded_for' => ['SELECT x_forwarded_for FROM sales_order', 'x_forwarded_for'],
            'token' => ['SELECT token FROM some_table', 'token'],
            'secret' => ['SELECT secret FROM some_table', 'secret'],
            'api_key' => ['SELECT api_key FROM some_table', 'api_key'],
            'compound token' => ['SELECT access_token FROM some_table', 'token'],
            'aliased to denied name' => ['SELECT email AS password FROM customer_entity', 'password'],
            'qualified' => ['SELECT c.password_hash FROM customer_entity c', 'password_hash'],
            'backticked' => ['SELECT `password_hash` FROM customer_entity', 'password_hash'],
            'in where clause only' => [
                "SELECT entity_id FROM customer_entity WHERE password_hash LIKE 'a%'",
                'password_hash',
            ],
        ];
    }

    /**
     * @dataProvider deniedColumnProvider
     * @param string $sql
     * @param string $column
     * @return void
     */
    public function testRejectsDeniedColumn(string $sql, string $column): void
    {
        $this->assertRejected($sql, $column);
    }

    public function testAllowsPermittedColumns(): void
    {
        $this->assertSame(
            'SELECT entity_id, firstname, lastname, created_at FROM customer_entity LIMIT 100',
            $this->guard()->assertReadOnly('SELECT entity_id, firstname, lastname, created_at FROM customer_entity')
        );
    }

    // ---------------------------------------------------------------- layer 7: wildcards

    public function testRejectsSelectStar(): void
    {
        $this->assertRejected('SELECT * FROM customer_entity', 'explicit');
    }

    public function testRejectsQualifiedStar(): void
    {
        $this->assertRejected('SELECT c.* FROM customer_entity c', 'explicit');
    }

    public function testRejectsStarInSubquery(): void
    {
        $this->assertRejected('SELECT entity_id FROM (SELECT * FROM customer_entity) c', 'explicit');
    }

    public function testAllowsCountStar(): void
    {
        $this->assertSame(
            'SELECT COUNT(*) FROM customer_entity LIMIT 100',
            $this->guard()->assertReadOnly('SELECT COUNT(*) FROM customer_entity')
        );
    }

    public function testRejectsArithmeticMultiplication(): void
    {
        // Documented trade-off: `*` is permitted only in the `COUNT(*)` shape.
        $this->assertRejected('SELECT price * 2 FROM catalog_product_index_price', 'explicit');
    }

    public function testMetadataStatementsAreExemptFromWildcardRule(): void
    {
        $this->assertSame(
            'EXPLAIN SELECT * FROM customer_entity',
            $this->guard()->assertReadOnly('EXPLAIN SELECT * FROM customer_entity')
        );
    }

    // ---------------------------------------------------------------- layer 8: row limit

    public function testAppendsDefaultLimitToSelect(): void
    {
        $this->assertSame(
            "SELECT entity_id, sku FROM catalog_product_entity WHERE sku LIKE 'MB%' LIMIT 100",
            $this->guard()->assertReadOnly("SELECT entity_id, sku FROM catalog_product_entity WHERE sku LIKE 'MB%'")
        );
    }

    public function testAppendsDefaultLimitToWith(): void
    {
        $sql = 'WITH c AS (SELECT entity_id FROM catalog_product_entity) SELECT entity_id FROM c';
        $this->assertSame($sql . ' LIMIT 100', $this->guard()->assertReadOnly($sql));
    }

    public function testKeepsExistingLimitWithinCeiling(): void
    {
        $sql = 'SELECT entity_id FROM catalog_product_entity LIMIT 50';
        $this->assertSame($sql, $this->guard()->assertReadOnly($sql));
    }

    public function testKeepsLimitOffsetPairWithinCeiling(): void
    {
        $sql = 'SELECT entity_id FROM catalog_product_entity LIMIT 20, 50';
        $this->assertSame($sql, $this->guard()->assertReadOnly($sql));
    }

    public function testKeepsLimitWithOffsetKeyword(): void
    {
        $sql = 'SELECT entity_id FROM catalog_product_entity LIMIT 50 OFFSET 20';
        $this->assertSame($sql, $this->guard()->assertReadOnly($sql));
    }

    public function testRejectsLimitAboveCeiling(): void
    {
        $this->assertRejected('SELECT entity_id FROM catalog_product_entity LIMIT 5000', '1000');
    }

    public function testRejectsLimitOffsetPairAboveCeiling(): void
    {
        $this->assertRejected('SELECT entity_id FROM catalog_product_entity LIMIT 10, 5000', '1000');
    }

    public function testRejectsNonIntegerLimit(): void
    {
        $this->assertRejected('SELECT entity_id FROM catalog_product_entity LIMIT 1+1', 'LIMIT');
    }

    public function testRejectsLimitThatIsNotTheFinalClause(): void
    {
        $this->assertRejected('SELECT a FROM (SELECT entity_id FROM t LIMIT 5) x', 'final clause');
    }

    public function testRejectsMoreThanOneLimit(): void
    {
        $this->assertRejected('SELECT a FROM (SELECT entity_id FROM t LIMIT 5) x LIMIT 10', 'once');
    }

    public function testMetadataStatementsGetNoLimit(): void
    {
        $this->assertSame('SHOW TABLES', $this->guard()->assertReadOnly('SHOW TABLES'));
        $this->assertSame('DESCRIBE customer_entity', $this->guard()->assertReadOnly('DESCRIBE customer_entity'));
        $this->assertSame('DESC customer_entity', $this->guard()->assertReadOnly('DESC customer_entity'));
    }

    public function testCallerSuppliedLimitIsAppended(): void
    {
        $this->assertSame(
            'SELECT entity_id FROM catalog_product_entity LIMIT 25',
            $this->guard()->assertReadOnly('SELECT entity_id FROM catalog_product_entity', 25)
        );
    }

    public function testCallerSuppliedLimitAboveCeilingIsRejected(): void
    {
        $guard = $this->guard();
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/1000/');
        $guard->assertReadOnly('SELECT entity_id FROM catalog_product_entity', 5000);
    }

    public function testCallerSuppliedLimitBelowOneIsRejected(): void
    {
        $guard = $this->guard();
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/at least 1/');
        $guard->assertReadOnly('SELECT entity_id FROM catalog_product_entity', 0);
    }

    public function testQueryOwnLimitWinsOverCallerSuppliedLimit(): void
    {
        $sql = 'SELECT entity_id FROM catalog_product_entity LIMIT 5';
        $this->assertSame($sql, $this->guard()->assertReadOnly($sql, 500));
    }

    public function testMisconfiguredDefaultRowsFallsBackToSafeValue(): void
    {
        $guard = $this->guard(0, 1000);
        $this->assertSame(
            'SELECT entity_id FROM catalog_product_entity LIMIT ' . QueryGuard::FALLBACK_ROWS,
            $guard->assertReadOnly('SELECT entity_id FROM catalog_product_entity')
        );
    }

    public function testDefaultRowsIsClampedToCeiling(): void
    {
        $guard = $this->guard(500, 200);
        $this->assertSame(
            'SELECT entity_id FROM catalog_product_entity LIMIT 200',
            $guard->assertReadOnly('SELECT entity_id FROM catalog_product_entity')
        );
    }

    // ---------------------------------------------------------------- validated form == executed form

    public function testReturnedSqlIsTheNormalisedFormThatWasValidated(): void
    {
        $this->assertSame(
            "SELECT entity_id, sku FROM catalog_product_entity WHERE sku = 'A B' LIMIT 100",
            $this->guard()->assertReadOnly(
                "SELECT /* c */ entity_id,\n\tsku\nFROM catalog_product_entity -- note\nWHERE sku = 'A B'  ;  "
            )
        );
    }

    public function testFindRowLimitReadsTheAppliedLimit(): void
    {
        $guard = $this->guard();
        $sql = $guard->assertReadOnly('SELECT entity_id FROM catalog_product_entity');
        $this->assertSame(100, $guard->findRowLimit($sql));
    }

    public function testFindRowLimitReturnsNullForMetadataStatement(): void
    {
        $guard = $this->guard();
        $this->assertNull($guard->findRowLimit($guard->assertReadOnly('SHOW TABLES')));
    }

    public function testFindRowLimitReadsOffsetPairRowCount(): void
    {
        $this->assertSame(50, $this->guard()->findRowLimit('SELECT entity_id FROM t LIMIT 20, 50'));
    }
}
