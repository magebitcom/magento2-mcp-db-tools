<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Test\Unit\Model;

use Magebit\McpDbTools\Model\ColumnDenyList;
use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magebit\McpDbTools\Model\IdentifierMatcher;
use Magebit\McpDbTools\Model\QueryGuard;
use Magebit\McpDbTools\Model\TableAccessPolicy;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * Bypass-focused coverage of the guard layers. Every rejection asserts on the message so a
 * refactor cannot quietly swap one layer's verdict for another's.
 *
 * The protected set is read out of the module's own `etc/di.xml`, not restated here: a test
 * copy would keep passing after someone shortened the shipped array.
 */
class QueryGuardTest extends TestCase
{
    /**
     * Tables the bulk of the suite reads from. Tests that exercise the allowlist itself pass
     * their own list instead.
     */
    private const ALLOWED_TABLES = [
        'customer_entity',
        'catalog_product_entity',
        'catalog_product_index_price',
        'sales_order',
        'sales_order_grid',
        'sales_order_payment',
        'quote_payment',
        'some_table',
        't',
        't2',
    ];

    /**
     * @param int $defaultRows
     * @param int $ceiling
     * @param list<string>|null $allowedTables Null uses {@see self::ALLOWED_TABLES}.
     * @param list<string> $extraDeniedTables
     * @param string $tablePrefix
     * @param string $databaseName
     * @return QueryGuard
     */
    private function guard(
        int $defaultRows = 100,
        int $ceiling = 1000,
        ?array $allowedTables = null,
        array $extraDeniedTables = [],
        string $tablePrefix = '',
        string $databaseName = 'magento'
    ): QueryGuard {
        $config = $this->createMock(DbToolsConfig::class);
        $config->method('getDefaultRows')->willReturn($defaultRows);
        $config->method('getRowLimitCeiling')->willReturn($ceiling);
        $config->method('getAllowedTables')->willReturn($allowedTables ?? self::ALLOWED_TABLES);
        $config->method('getExtraDeniedTables')->willReturn($extraDeniedTables);

        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path): ?string => match ($path) {
                ConfigOptionsListConstants::CONFIG_PATH_DB_PREFIX => $tablePrefix,
                IdentifierMatcher::CONFIG_PATH_DB_NAME => $databaseName,
                default => null,
            }
        );

        $matcher = new IdentifierMatcher($deploymentConfig);
        $policy = new TableAccessPolicy(
            $config,
            $matcher,
            self::diArgument('protectedTables'),
            self::diArgument('protectedIdentifiers')
        );

        return new QueryGuard($config, $policy, new ColumnDenyList($matcher));
    }

    /**
     * Reads one DI array argument of {@see TableAccessPolicy} out of the shipped `etc/di.xml`.
     *
     * @param string $name
     * @return list<string>
     */
    private static function diArgument(string $name): array
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/di.xml');
        self::assertNotFalse($xml, 'etc/di.xml is not readable.');

        $items = $xml->xpath(
            sprintf(
                '//type[@name="Magebit\McpDbTools\Model\TableAccessPolicy"]/arguments/argument[@name="%s"]/item',
                $name
            )
        );
        self::assertNotEmpty($items, sprintf('di.xml declares no `%s` items.', $name));

        return array_values(array_map(static fn (\SimpleXMLElement $item): string => (string) $item, $items));
    }

    /**
     * @param string $sql
     * @param string $expectedMessageFragment
     * @return void
     */
    private function assertRejected(string $sql, string $expectedMessageFragment): void
    {
        $this->assertRejectedBy($this->guard(), $sql, $expectedMessageFragment);
    }

    /**
     * @param QueryGuard $guard
     * @param string $sql
     * @param string $expectedMessageFragment
     * @return void
     */
    private function assertRejectedBy(QueryGuard $guard, string $sql, string $expectedMessageFragment): void
    {
        try {
            $guard->assertReadOnly($sql);
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
            'MERGE' => ['SELECT MERGE FROM t', 'MERGE'],
            'DO' => ['SELECT entity_id FROM t WHERE DO = 1', 'DO'],
            'UNINSTALL' => ['SELECT UNINSTALL FROM t', 'UNINSTALL'],
            'SHARE' => ['SELECT entity_id FROM t FOR SHARE', 'SHARE'],
            'SET' => ['SELECT entity_id FROM t WHERE SET = 1', 'SET'],
            'RELEASE_LOCK' => ["SELECT RELEASE_LOCK('a')", 'RELEASE_LOCK'],
            'IS_USED_LOCK' => ["SELECT IS_USED_LOCK('a')", 'IS_USED_LOCK'],
            'IS_FREE_LOCK' => ["SELECT IS_FREE_LOCK('a')", 'IS_FREE_LOCK'],
            'MASTER_POS_WAIT' => ["SELECT MASTER_POS_WAIT('log', 1)", 'MASTER_POS_WAIT'],
            'SOURCE_POS_WAIT' => ["SELECT SOURCE_POS_WAIT('log', 1)", 'SOURCE_POS_WAIT'],
            'WAIT_FOR_EXECUTED_GTID_SET' => ["SELECT WAIT_FOR_EXECUTED_GTID_SET('x')", 'WAIT_FOR_EXECUTED_GTID_SET'],
            'WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS' => [
                "SELECT WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS('x')",
                'WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS',
            ],
            'SYS_EVAL' => ["SELECT SYS_EVAL('id')", 'SYS_EVAL'],
            'GROUP_CONCAT' => ['SELECT GROUP_CONCAT(email) FROM customer_entity', 'GROUP_CONCAT'],
            'JSON_ARRAYAGG' => ['SELECT JSON_ARRAYAGG(email) FROM customer_entity LIMIT 100', 'JSON_ARRAYAGG'],
            'JSON_OBJECTAGG' => [
                'SELECT JSON_OBJECTAGG(entity_id, email) FROM customer_entity',
                'JSON_OBJECTAGG',
            ],
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

    /**
     * The `NO_BACKSLASH_ESCAPES` divergence. With even backslash parity the payload's literal
     * closes cleanly for us while MySQL sees the literal end at the backslash — everything
     * after it would execute unscanned. Refusing `\` is what makes our lexer mode-independent.
     *
     * @return void
     */
    public function testRejectsBackslashSmuggledUnionWithEvenQuoteParity(): void
    {
        $this->assertRejected(
            "SELECT firstname FROM customer_entity WHERE firstname='a\\' UNION SELECT password "
            . "FROM admin_user-- '",
            'backslash'
        );
    }

    public function testRejectsBackslashSmuggledStatementSeparator(): void
    {
        $this->assertRejected(
            "SELECT firstname FROM customer_entity WHERE firstname='a\\';UPDATE admin_user "
            . "SET reload_acl_flag=1-- '",
            'backslash'
        );
    }

    public function testRejectsBackslashInsideLiteralWithOddParity(): void
    {
        $this->assertRejected("SELECT entity_id FROM customer_entity WHERE firstname = 'it\\'s'", 'backslash');
    }

    public function testRejectsPlainBackslashInsideLiteral(): void
    {
        // Control case: the rule is unconditional, not parity-dependent.
        $this->assertRejected("SELECT entity_id FROM customer_entity WHERE firstname = 'a\\\\b'", 'backslash');
    }

    public function testAllowsDoubledQuoteToEmbedAQuote(): void
    {
        // The mode-independent replacement for `\'`.
        $sql = "SELECT entity_id FROM customer_entity WHERE lastname = 'O''Brien'";
        $this->assertSame($sql . ' LIMIT 100', $this->guard()->assertReadOnly($sql));
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

    // ---------------------------------------------------------------- layer 4b: function allowlist

    public function testRejectsStoredFunctionCall(): void
    {
        $this->assertRejected('SELECT my_project_func(1) FROM customer_entity', 'not a permitted function');
    }

    public function testRejectsStoredFunctionCallWithSpaceBeforeParenthesis(): void
    {
        // Whitespace is allowed before `(` for stored functions, so the check is token-based.
        $this->assertRejected('SELECT my_project_func (1) FROM customer_entity', 'not a permitted function');
    }

    public function testRejectsQualifiedStoredFunctionCall(): void
    {
        $this->assertRejected('SELECT other_db.helper_totals(1) FROM customer_entity', 'not a permitted function');
    }

    public function testRejectsBacktickQuotedFunctionName(): void
    {
        $this->assertRejected('SELECT `my_project_func`(1) FROM customer_entity', 'not a permitted function');
    }

    public function testRejectsFunctionNamedAfterAStructuralKeyword(): void
    {
        // A quoted word before `(` never earns the structural exemption.
        $this->assertRejected('SELECT `values`(1) FROM customer_entity', 'not a permitted function');
    }

    public function testRejectsFunctionNamedAfterANonReservedKeyword(): void
    {
        // `ANY` and `AGAINST` are non-reserved, so they could name a stored function invoked
        // unquoted; they are deliberately absent from the structural exemption.
        $this->assertRejected('SELECT any(1) FROM customer_entity', 'not a permitted function');
        $this->assertRejected('SELECT against(1) FROM customer_entity', 'not a permitted function');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedFunctionQueryProvider(): array
    {
        return [
            'aggregates' => ['SELECT COUNT(*), MAX(created_at), MIN(created_at) FROM customer_entity'],
            'strings' => ["SELECT CONCAT(firstname, ' ', lastname), LENGTH(email) FROM customer_entity"],
            'dates' => ["SELECT DATE_FORMAT(created_at, '%Y-%m') FROM sales_order"],
            'cast with precision' => ['SELECT CAST(grand_total AS DECIMAL(12,4)) FROM sales_order'],
            'control flow' => ["SELECT IFNULL(coupon_code, '-'), IF(state = 'new', 1, 0) FROM sales_order"],
            'json accessor' => ["SELECT JSON_EXTRACT(additional_information, '$.x') FROM sales_order_payment"],
            'window function' => [
                'SELECT ROW_NUMBER() OVER (PARTITION BY store_id ORDER BY entity_id) FROM sales_order',
            ],
            'in list' => ['SELECT entity_id FROM customer_entity WHERE entity_id IN (1, 2, 3)'],
            'subquery source' => ['SELECT c.entity_id FROM (SELECT entity_id FROM customer_entity) c'],
            'join using' => [
                'SELECT o.entity_id FROM sales_order o JOIN sales_order_grid g USING (entity_id)',
            ],
            'exists subquery' => [
                'SELECT entity_id FROM customer_entity WHERE EXISTS (SELECT 1 FROM sales_order)',
            ],
            'grouped expression' => ['SELECT entity_id FROM sales_order WHERE (store_id = 1 AND state IS NULL)'],
            'case expression' => ["SELECT CASE WHEN (store_id = 1) THEN 'a' ELSE 'b' END FROM sales_order"],
        ];
    }

    /**
     * @dataProvider allowedFunctionQueryProvider
     * @param string $sql
     * @return void
     */
    public function testAcceptsAllowlistedFunctionsAndStructuralParentheses(string $sql): void
    {
        $this->assertSame($sql . ' LIMIT 100', $this->guard()->assertReadOnly($sql));
    }

    // ---------------------------------------------------------------- unsubstituted placeholders

    public function testRejectsUnsubstitutedTablePlaceholder(): void
    {
        $this->assertRejected('SELECT entity_id FROM {{customer_entity}}', 'placeholder');
    }

    public function testRejectsStrayClosingBrace(): void
    {
        $this->assertRejected('SELECT entity_id FROM customer_entity}', 'placeholder');
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
            // `session_id` would be refused first now that bare `session` is protected, and the
            // message would name `session` rather than the table under test.
            'admin_user_session' => ['SELECT user_id FROM admin_user_session', 'admin_user_session'],
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
            'magebit_mcp_oauth_authorize_handoff' => [
                'SELECT params_json FROM magebit_mcp_oauth_authorize_handoff',
                'magebit_mcp_oauth_authorize_handoff',
            ],
            'magebit_mcp_audit_log' => [
                'SELECT tool_name FROM magebit_mcp_audit_log',
                'magebit_mcp_audit_log',
            ],
            'session' => ['SELECT session_data FROM session', 'session'],
            'cache' => ['SELECT data FROM cache', 'cache'],
            'admin_adobe_ims_webapi' => [
                'SELECT id FROM admin_adobe_ims_webapi',
                'admin_adobe_ims_webapi',
            ],
        ];
    }

    /**
     * The protected set must veto the allowlist for these too, not merely be absent from it.
     *
     * @dataProvider deniedTableProvider
     * @param string $sql
     * @param string $table
     * @return void
     */
    public function testProtectedTableIsRefusedWhenTheOperatorAllowlistsIt(string $sql, string $table): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($table, '/') . '/');

        $this->guard(allowedTables: [$table])->assertReadOnly($sql);
    }

    /**
     * @return void
     */
    public function testSessionAndCacheProtectionReachesTheirColumnsToo(): void
    {
        // Consequence of the wide matcher, recorded so it is a decision rather than a surprise:
        // any identifier carrying `session` or `cache` as a word run is refused, on any table.
        $this->assertRejected('SELECT session_id FROM customer_entity', 'session');
        $this->assertRejected('SELECT cache_id FROM customer_entity', 'cache');
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
        $guard = $this->guard(100, 1000, null, [], 'mage_');
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
        // Also the config-level half of deny-after-allow: the table is allowlisted and still refused.
        $guard = $this->guard(100, 1000, ['my_vault'], ['my_vault']);
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

    public function testRejectsInnodbTrx(): void
    {
        // Same threat as PROCESSLIST: other sessions' SQL text, literals included.
        $this->assertRejected('SELECT trx_id, trx_query FROM information_schema.innodb_trx', 'innodb_trx');
    }

    public function testRejectsUserPrivilegesView(): void
    {
        $this->assertRejected('SELECT grantee FROM information_schema.user_privileges', 'user_privileges');
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
            'cc_last4' => ['SELECT cc_last4 FROM sales_order_payment', 'cc_last4'],
            'cc_owner' => ['SELECT cc_owner FROM sales_order_payment', 'cc_owner'],
            'cc_number_enc' => ['SELECT cc_number_enc FROM sales_order_payment', 'cc_number_enc'],
            'cc_cid_enc' => ['SELECT cc_cid_enc FROM quote_payment', 'cc_cid_enc'],
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

    // ------------------------------------------------ the protected set is the shipped one

    public function testDiXmlStillProtectsEverySeededName(): void
    {
        $protected = array_merge(self::diArgument('protectedTables'), self::diArgument('protectedIdentifiers'));

        foreach (
            [
                'admin_user', 'admin_passwords', 'admin_user_session', 'admin_user_expiration', 'oauth_token',
                'oauth_consumer', 'oauth_token_request_log', 'magebit_mcp_token', 'magebit_mcp_oauth_client',
                'magebit_mcp_oauth_auth_code', 'magebit_mcp_oauth_refresh_token', 'persistent_session',
                'password_reset_request_event', 'core_config_data', 'mysql', 'information_schema',
                'performance_schema', 'sys', 'processlist', 'innodb_trx', 'user_privileges',
                // Added in Task 7's review round: module-owned or Magento-owned tables holding
                // security material the MCP surface itself produces or depends on.
                'magebit_mcp_oauth_authorize_handoff', 'magebit_mcp_audit_log', 'session', 'cache',
                'admin_adobe_ims_webapi',
            ] as $name
        ) {
            $this->assertContains($name, $protected, sprintf('`%s` is no longer protected by di.xml.', $name));
        }
    }

    public function testRejectsCoreConfigData(): void
    {
        // Encrypted gateway credentials live here; system.config.get is the redacting path.
        $this->assertRejected('SELECT path, value FROM core_config_data', 'core_config_data');
    }

    // ---------------------------------------------------------------- allowlist: gate

    public function testEmptyAllowlistRefusesEveryQuery(): void
    {
        $guard = $this->guard(100, 1000, []);

        foreach (
            [
                'SELECT 1',
                'SELECT entity_id FROM customer_entity',
                'SHOW TABLES',
                'DESCRIBE customer_entity',
                'EXPLAIN SELECT entity_id FROM customer_entity',
            ] as $sql
        ) {
            $this->assertRejectedBy($guard, $sql, 'allowed_tables');
        }
    }

    public function testCommentOnlyAllowlistCountsAsEmpty(): void
    {
        // What DbToolsConfig hands over for the shipped default field value.
        $this->assertRejectedBy($this->guard(100, 1000, []), 'SELECT 1', 'no table');
    }

    public function testTablelessSelectIsAcceptedOnceTheAllowlistIsConfigured(): void
    {
        $this->assertSame('SELECT 1 LIMIT 100', $this->guard()->assertReadOnly('SELECT 1'));
    }

    public function testRejectsProtectedNameListedInTheAllowlistConfig(): void
    {
        // Naming the offending entry: the operator must not be left guessing which line is wrong.
        $guard = $this->guard(100, 1000, ['customer_entity', 'admin_user']);
        $this->assertRejectedBy($guard, 'SELECT entity_id FROM customer_entity', 'admin_user');
        $this->assertRejectedBy($guard, 'SELECT entity_id FROM customer_entity', 'allowed_tables');
    }

    public function testRejectsPrefixSpelledProtectedNameListedInTheAllowlistConfig(): void
    {
        $guard = $this->guard(100, 1000, ['customer_entity', 'm2_admin_user'], [], 'm2_');
        $this->assertRejectedBy($guard, 'SELECT entity_id FROM m2_customer_entity', 'm2_admin_user');
    }

    public function testRejectsAllowlistEntryThatIsNotAPlainTableName(): void
    {
        foreach (['information_schema.tables', 'customer_%', 'two words'] as $entry) {
            $guard = $this->guard(100, 1000, ['customer_entity', $entry]);
            $this->assertRejectedBy($guard, 'SELECT entity_id FROM customer_entity', $entry);
        }
    }

    // ---------------------------------------------------------------- allowlist: table positions

    public function testRejectsTableThatIsNotAllowlisted(): void
    {
        $this->assertRejected('SELECT a FROM cataloginventory_stock_item', 'cataloginventory_stock_item');
        $this->assertRejected('SELECT a FROM cataloginventory_stock_item', 'allowed_tables');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unlistedTablePositionProvider(): array
    {
        return [
            'from' => ['SELECT a FROM unlisted_ledger', 'unlisted_ledger'],
            'subquery' => [
                'SELECT entity_id FROM customer_entity WHERE entity_id IN (SELECT id FROM unlisted_ledger)',
                'unlisted_ledger',
            ],
            'join' => [
                'SELECT c.entity_id FROM customer_entity c JOIN unlisted_ledger s ON s.id = c.entity_id',
                'unlisted_ledger',
            ],
            'left join' => [
                'SELECT c.entity_id FROM customer_entity c LEFT JOIN unlisted_ledger AS s ON s.id = c.entity_id',
                'unlisted_ledger',
            ],
            'comma join' => ['SELECT a FROM customer_entity, unlisted_ledger', 'unlisted_ledger'],
            'cte body' => [
                'WITH c AS (SELECT id FROM unlisted_ledger) SELECT id FROM c',
                'unlisted_ledger',
            ],
            'second cte body' => [
                'WITH a AS (SELECT 1 AS n), b AS (SELECT id FROM unlisted_ledger) SELECT n FROM a',
                'unlisted_ledger',
            ],
            'derived table' => ['SELECT d.id FROM (SELECT id FROM unlisted_ledger) d', 'unlisted_ledger'],
            // MariaDB accepts a bare parenthesised table reference: `FROM (t)` reads table t.
            'parenthesised table reference' => ['SELECT a FROM (unlisted_ledger)', 'unlisted_ledger'],
            'parenthesised join' => [
                'SELECT a FROM (customer_entity c JOIN unlisted_ledger s ON s.id = c.entity_id)',
                'unlisted_ledger',
            ],
            'union branch' => [
                'SELECT entity_id FROM customer_entity UNION SELECT id FROM unlisted_ledger',
                'unlisted_ledger',
            ],
            'union after window clause' => [
                'SELECT entity_id FROM customer_entity WINDOW w AS () UNION SELECT id FROM unlisted_ledger',
                'unlisted_ledger',
            ],
            'straight_join' => ['SELECT a FROM customer_entity STRAIGHT_JOIN unlisted_ledger', 'unlisted_ledger'],
            'backtick quoted' => ['SELECT a FROM `unlisted_ledger`', 'unlisted_ledger'],
            'quoted keyword name' => ['SELECT a FROM `select`', '`select`'],
            'exists subquery' => [
                'SELECT entity_id FROM customer_entity WHERE EXISTS (SELECT 1 FROM unlisted_ledger)',
                'unlisted_ledger',
            ],
            'nested subquery' => [
                'SELECT a FROM (SELECT b FROM (SELECT id AS b FROM unlisted_ledger) inner_alias) outer_alias',
                'unlisted_ledger',
            ],
            'doubly parenthesised table reference' => ['SELECT a FROM ((unlisted_ledger))', 'unlisted_ledger'],
            'natural join' => ['SELECT a FROM customer_entity NATURAL JOIN unlisted_ledger', 'unlisted_ledger'],
            'left outer join' => [
                'SELECT a FROM customer_entity LEFT OUTER JOIN unlisted_ledger u ON u.id = 1',
                'unlisted_ledger',
            ],
            'comma after a derived table' => [
                'SELECT a FROM (SELECT entity_id, firstname FROM customer_entity) d, unlisted_ledger',
                'unlisted_ledger',
            ],
            'derived table after a comma' => [
                'SELECT a FROM customer_entity, (SELECT id FROM unlisted_ledger) d',
                'unlisted_ledger',
            ],
            'union all branch' => [
                'SELECT entity_id FROM customer_entity UNION ALL SELECT id FROM unlisted_ledger',
                'unlisted_ledger',
            ],
            'aliased to an allowlisted name' => ['SELECT a FROM unlisted_ledger AS t', 'unlisted_ledger'],
            'comment between FROM and the table' => ['SELECT a FROM/**/unlisted_ledger', 'unlisted_ledger'],
            'lower case clause keywords' => ['select a from unlisted_ledger', 'unlisted_ledger'],
            'explain for connection' => ['EXPLAIN FOR CONNECTION 5', 'for'],
            'quoted describe target' => ['DESCRIBE `unlisted_ledger`', 'unlisted_ledger'],
            'cte body inside a subquery' => [
                'SELECT entity_id FROM customer_entity WHERE entity_id IN '
                . '(WITH c AS (SELECT id FROM unlisted_ledger) SELECT id FROM c)',
                'unlisted_ledger',
            ],
            'explained cte body' => [
                'EXPLAIN WITH c AS (SELECT id FROM unlisted_ledger) SELECT id FROM c',
                'unlisted_ledger',
            ],
            'parenthesised comma list' => [
                'SELECT a FROM (customer_entity, unlisted_ledger)',
                'unlisted_ledger',
            ],
            'join onto a parenthesised list' => [
                'SELECT a FROM customer_entity JOIN (sales_order, unlisted_ledger)',
                'unlisted_ledger',
            ],
            'comma after a cte reference' => [
                'WITH c AS (SELECT 1 AS a) SELECT a FROM c, unlisted_ledger',
                'unlisted_ledger',
            ],
            'scalar subquery in the select list' => [
                'SELECT (SELECT MAX(id) FROM unlisted_ledger) AS m FROM customer_entity',
                'unlisted_ledger',
            ],
            'scalar subquery in the where clause' => [
                'SELECT entity_id FROM customer_entity WHERE entity_id = (SELECT MAX(id) FROM unlisted_ledger)',
                'unlisted_ledger',
            ],
            'having subquery' => [
                'SELECT store_id FROM sales_order GROUP BY store_id '
                . 'HAVING COUNT(*) > (SELECT MAX(id) FROM unlisted_ledger)',
                'unlisted_ledger',
            ],
            'order by subquery' => [
                'SELECT entity_id FROM customer_entity ORDER BY (SELECT MAX(id) FROM unlisted_ledger)',
                'unlisted_ledger',
            ],
        ];
    }

    /**
     * @dataProvider unlistedTablePositionProvider
     * @param string $sql
     * @param string $table
     * @return void
     */
    public function testRejectsUnlistedTableInEveryTablePosition(string $sql, string $table): void
    {
        $this->assertRejected($sql, $table);
    }

    public function testRejectsBaseTableUsedOutsideTheScopeOfASameNamedNestedCte(): void
    {
        // Probed on MariaDB 10.6: a WITH inside a derived table is scoped to that derived table,
        // so the outer reference reads the *base* table. A statement-wide CTE exemption would
        // hand over any table an attacker names in a nested WITH.
        $this->assertRejected(
            'SELECT d.a, s.id FROM (WITH unlisted_ledger AS (SELECT 1 AS a) SELECT a FROM unlisted_ledger) d, '
            . 'unlisted_ledger s',
            'unlisted_ledger'
        );
    }

    public function testRejectsUnlistedDescribeTarget(): void
    {
        $this->assertRejected('DESCRIBE unlisted_ledger', 'unlisted_ledger');
        $this->assertRejected('DESC unlisted_ledger', 'unlisted_ledger');
        $this->assertRejected('EXPLAIN unlisted_ledger', 'unlisted_ledger');
    }

    public function testRejectsUnlistedTableInExplainedSelect(): void
    {
        $this->assertRejected('EXPLAIN SELECT id FROM unlisted_ledger', 'unlisted_ledger');
        $this->assertRejected('EXPLAIN FORMAT = JSON SELECT id FROM unlisted_ledger', 'unlisted_ledger');
    }

    public function testAcceptsAllowlistedDescribeTarget(): void
    {
        $this->assertSame('DESCRIBE customer_entity', $this->guard()->assertReadOnly('DESCRIBE customer_entity'));
    }

    // ------------------------------------------------ allowlist: exact + prefix matching only

    public function testAllowlistEntryDoesNotAdmitASuffixedTable(): void
    {
        // The fail-open direction of the old word-run matcher.
        $guard = $this->guard(100, 1000, ['customer_entity']);
        $this->assertRejectedBy($guard, 'SELECT a FROM customer_entity_backup', 'customer_entity_backup');
    }

    public function testAllowlistEntryDoesNotAdmitAnIdentifierThatMerelyContainsIt(): void
    {
        $guard = $this->guard(100, 1000, ['user', 'order', 'entity']);
        $this->assertRejectedBy($guard, 'SELECT a FROM sales_order', 'sales_order');
        $this->assertRejectedBy($guard, 'SELECT a FROM customer_entity', 'customer_entity');
        // The brief's own example: an allowed bare `user` must never admit `admin_user`.
        $this->assertRejectedBy($guard, 'SELECT a FROM admin_user', 'admin_user');
    }

    public function testAllowlistEntryDoesNotAdmitAPrefixedTableWhenNoPrefixIsConfigured(): void
    {
        $guard = $this->guard(100, 1000, ['customer_entity']);
        $this->assertRejectedBy($guard, 'SELECT a FROM m2_customer_entity', 'm2_customer_entity');
    }

    public function testPrefixedInstallMatchesAnUnprefixedAllowlistEntry(): void
    {
        $guard = $this->guard(100, 1000, ['catalog_product_entity'], [], 'm2_');
        $this->assertSame(
            'SELECT entity_id FROM m2_catalog_product_entity LIMIT 100',
            $guard->assertReadOnly('SELECT entity_id FROM m2_catalog_product_entity')
        );
    }

    public function testUnprefixedTableStillMatchesWhenAPrefixIsConfigured(): void
    {
        $guard = $this->guard(100, 1000, ['catalog_product_entity'], [], 'm2_');
        $this->assertSame(
            'SELECT entity_id FROM catalog_product_entity LIMIT 100',
            $guard->assertReadOnly('SELECT entity_id FROM catalog_product_entity')
        );
    }

    public function testAllowlistMatchingIsCaseInsensitive(): void
    {
        $this->assertSame(
            'SELECT entity_id FROM CUSTOMER_ENTITY LIMIT 100',
            $this->guard()->assertReadOnly('SELECT entity_id FROM CUSTOMER_ENTITY')
        );
    }

    public function testBacktickQuotedAllowlistedTableIsAccepted(): void
    {
        $this->assertSame(
            'SELECT entity_id FROM `customer_entity` LIMIT 100',
            $this->guard()->assertReadOnly('SELECT entity_id FROM `customer_entity`')
        );
    }

    // ------------------------------------------------ allowlist: schema-qualified references

    public function testAcceptsCurrentDatabaseQualifiedTable(): void
    {
        $this->assertSame(
            'SELECT entity_id FROM magento.customer_entity LIMIT 100',
            $this->guard()->assertReadOnly('SELECT entity_id FROM magento.customer_entity')
        );
    }

    public function testRejectsTableQualifiedWithAnotherDatabase(): void
    {
        $this->assertRejected('SELECT entity_id FROM other_db.customer_entity', 'other_db');
    }

    public function testRejectsUnlistedTableQualifiedWithTheCurrentDatabase(): void
    {
        $this->assertRejected('SELECT id FROM magento.unlisted_ledger', 'unlisted_ledger');
    }

    // ------------------------------------------------ allowlist: false-positive guards

    /**
     * @return array<string, array{string}>
     */
    public static function nonTableIdentifierProvider(): array
    {
        return [
            'cte name' => ['WITH unlisted_ledger AS (SELECT 1 AS n) SELECT n FROM unlisted_ledger'],
            'two cte names' => [
                'WITH a AS (SELECT 1 AS n), b AS (SELECT 2 AS m) SELECT a.n, b.m FROM a, b',
            ],
            'recursive cte self reference' => [
                'WITH RECURSIVE seq AS (SELECT 1 AS n UNION SELECT n + 1 FROM seq WHERE n < 5) SELECT n FROM seq',
            ],
            'cte used in a join' => [
                'WITH c AS (SELECT entity_id FROM customer_entity) '
                . 'SELECT c.entity_id FROM c JOIN sales_order o ON o.customer_id = c.entity_id',
            ],
            'derived table alias' => ['SELECT d.entity_id FROM (SELECT entity_id FROM customer_entity) d'],
            'derived table alias with AS' => [
                'SELECT d.entity_id FROM (SELECT entity_id FROM customer_entity) AS d',
            ],
            'table alias' => ['SELECT ce.entity_id FROM customer_entity ce'],
            'table alias with AS' => ['SELECT ce.entity_id FROM customer_entity AS ce'],
            'column alias' => ['SELECT entity_id AS unlisted_ledger FROM customer_entity'],
            'select list commas' => ['SELECT entity_id, firstname, lastname FROM customer_entity'],
            'group by and order by commas' => [
                'SELECT store_id, state, COUNT(*) FROM sales_order GROUP BY store_id, state '
                . 'ORDER BY store_id, state',
            ],
            'join using column list' => [
                'SELECT o.entity_id FROM sales_order o JOIN sales_order_grid g USING (entity_id, store_id)',
            ],
            'window definition name' => [
                'SELECT ROW_NUMBER() OVER w FROM sales_order WINDOW w AS (ORDER BY entity_id)',
            ],
            'index hint' => ['SELECT entity_id FROM customer_entity USE INDEX (PRIMARY) WHERE entity_id = 1'],
            'dual' => ['SELECT 1 FROM DUAL'],
            'straight_join select modifier' => ['SELECT STRAIGHT_JOIN entity_id FROM customer_entity'],
            'cast precision list' => ['SELECT CAST(grand_total AS DECIMAL(12,4)) FROM sales_order'],
            'in list of literals' => ['SELECT entity_id FROM customer_entity WHERE entity_id IN (1, 2, 3)'],
            'quoted cte name' => ['WITH `c` AS (SELECT entity_id FROM customer_entity) SELECT entity_id FROM `c`'],
            'cte referenced from a nested subquery' => [
                'WITH c AS (SELECT entity_id FROM customer_entity) '
                . 'SELECT entity_id FROM c WHERE entity_id IN (SELECT entity_id FROM c)',
            ],
            'two derived tables' => [
                'SELECT d.entity_id, e.entity_id FROM (SELECT entity_id FROM customer_entity) d, '
                . '(SELECT entity_id FROM sales_order) e',
            ],
            'partition clause' => ['SELECT entity_id FROM sales_order PARTITION (p0) WHERE store_id = 1'],
            'union branch on an allowlisted table' => [
                'SELECT entity_id FROM customer_entity UNION ALL SELECT entity_id FROM sales_order',
            ],
            'explained cte' => [
                'EXPLAIN WITH c AS (SELECT entity_id FROM customer_entity) SELECT entity_id FROM c',
            ],
            'cte inside a subquery' => [
                'SELECT entity_id FROM customer_entity WHERE entity_id IN '
                . '(WITH c AS (SELECT entity_id FROM sales_order) SELECT entity_id FROM c)',
            ],
        ];
    }

    /**
     * @dataProvider nonTableIdentifierProvider
     * @param string $sql
     * @return void
     */
    public function testAliasesAndCteNamesDoNotNeedAllowlisting(string $sql): void
    {
        $this->assertNotSame('', $this->guard()->assertReadOnly($sql));
    }

    // ------------------------------------------------ deny after allow

    public function testProtectedTableIsRefusedEvenWhenAllowlisted(): void
    {
        $guard = $this->guard(100, 1000, ['customer_entity', 'admin_user']);
        $this->assertRejectedBy($guard, 'SELECT email FROM admin_user', 'admin_user');
        $this->assertRejectedBy($guard, 'SELECT email FROM admin_user', 'protected');
    }

    public function testProtectedNameIsRefusedInsideAnAllowlistedWrapperTable(): void
    {
        // `custom_admin_user_map` is a legal allowlist entry (it is not itself a protected name),
        // so this is the pure ordering case: the allow check passes and the veto still refuses.
        $guard = $this->guard(100, 1000, ['custom_admin_user_map']);
        $this->assertRejectedBy($guard, 'SELECT a FROM custom_admin_user_map', 'admin_user');
    }

    public function testInformationSchemaViewsStayRefusedWhenTheSchemaIsAllowlisted(): void
    {
        $guard = $this->guard(100, 1000, ['customer_entity', 'information_schema']);

        $this->assertRejectedBy($guard, 'SHOW PROCESSLIST', 'processlist');
        $this->assertRejectedBy(
            $guard,
            'SELECT trx_id, trx_query FROM information_schema.innodb_trx',
            'innodb_trx'
        );
        $this->assertRejectedBy(
            $guard,
            'SELECT grantee FROM information_schema.user_privileges',
            'user_privileges'
        );
    }

    public function testColumnDenyListStillFiresOnAnAllowlistedTable(): void
    {
        // A table allowlist cannot protect a secret column on a table any useful list contains.
        $this->assertRejected('SELECT password_hash FROM customer_entity', 'password_hash');
        $this->assertRejected('SELECT rp_token FROM customer_entity', 'rp_token');
        $this->assertRejected('SELECT cc_last4 FROM sales_order_payment', 'cc_last4');
    }

    // ------------------------------------------------ SHOW forms

    public function testAcceptsShowFormsWithAnAllowlistedTarget(): void
    {
        $guard = $this->guard();
        $this->assertSame('SHOW TABLES', $guard->assertReadOnly('SHOW TABLES'));
        $this->assertSame(
            'SHOW FULL COLUMNS FROM customer_entity',
            $guard->assertReadOnly('SHOW FULL COLUMNS FROM customer_entity')
        );
        $this->assertSame(
            'SHOW INDEX FROM customer_entity',
            $guard->assertReadOnly('SHOW INDEX FROM customer_entity')
        );
    }

    public function testRejectsShowFormsTargetingAnUnlistedTable(): void
    {
        $this->assertRejected('SHOW COLUMNS FROM unlisted_ledger', 'unlisted_ledger');
        $this->assertRejected('SHOW FULL COLUMNS FROM unlisted_ledger', 'unlisted_ledger');
        $this->assertRejected('SHOW INDEX FROM unlisted_ledger', 'unlisted_ledger');
        $this->assertRejected('SHOW KEYS IN unlisted_ledger', 'unlisted_ledger');
    }

    public function testRejectsShowFormsThatRevealOtherTables(): void
    {
        // TRIGGERS and EVENTS embed statement bodies; TABLE STATUS reports per-table row counts.
        $this->assertRejected('SHOW TRIGGERS', 'SHOW');
        $this->assertRejected('SHOW EVENTS', 'SHOW');
        $this->assertRejected('SHOW TABLE STATUS', 'SHOW');
        $this->assertRejected('SHOW OPEN TABLES', 'SHOW');
        $this->assertRejected('SHOW FUNCTION STATUS', 'SHOW');
    }

    public function testRejectsShowTablesFromAProtectedSchema(): void
    {
        $this->assertRejected('SHOW TABLES FROM information_schema', 'information_schema');
    }

    // ------------------------------------------------ CTE scoping (fix round 1)

    /**
     * A non-recursive CTE's own name resolves to the *base table* inside its body, so registering
     * the name before scanning the body handed over any non-protected table.
     *
     * @return array<string, array{string}>
     */
    public static function selfReferencingCteProvider(): array
    {
        return [
            'plain' => [
                'WITH cataloginventory_stock_item AS (SELECT item_id, qty FROM cataloginventory_stock_item) '
                . 'SELECT item_id, qty FROM cataloginventory_stock_item',
            ],
            'quoted name' => [
                'WITH `cataloginventory_stock_item` AS (SELECT item_id FROM `cataloginventory_stock_item`) '
                . 'SELECT item_id FROM `cataloginventory_stock_item`',
            ],
            'second in the list' => [
                'WITH a AS (SELECT 1 AS n), cataloginventory_stock_item AS '
                . '(SELECT item_id FROM cataloginventory_stock_item) SELECT n FROM a',
            ],
            'inside a derived table' => [
                'SELECT d.item_id FROM (WITH cataloginventory_stock_item AS '
                . '(SELECT item_id FROM cataloginventory_stock_item) SELECT item_id FROM '
                . 'cataloginventory_stock_item) d',
            ],
        ];
    }

    /**
     * @dataProvider selfReferencingCteProvider
     * @param string $sql
     * @return void
     */
    public function testRejectsSelfReferenceInsideANonRecursiveCteBody(string $sql): void
    {
        $this->assertRejected($sql, 'cataloginventory_stock_item');
    }

    public function testRecursiveCteMayStillReferenceItself(): void
    {
        // `WITH RECURSIVE x AS (… FROM x)` can never fall through to a base table — the server
        // answers ERROR 4005 when there is no anchor — so early registration stays for that form.
        $sql = 'WITH RECURSIVE seq AS (SELECT 1 AS n UNION SELECT n + 1 FROM seq WHERE n < 5) SELECT n FROM seq';
        $this->assertSame($sql . ' LIMIT 100', $this->guard()->assertReadOnly($sql));
    }

    /**
     * Documents the deliberate boundary of the fix, so it is not "tidied up" later: inside a
     * `WITH RECURSIVE` list the name stays visible in its own body, and that is safe only because
     * the server refuses an unanchored recursive element. Probed on MariaDB 10.6 for both the first
     * and a later element of the list — `ERROR 4005: No anchors for recursive WITH element` — so
     * such a statement can never reach the base table, whatever the guard says.
     *
     * @return void
     */
    public function testRecursiveListSelfReferenceIsLeftToTheServersAnchorRule(): void
    {
        $sql = 'WITH RECURSIVE cataloginventory_stock_item AS '
            . '(SELECT item_id FROM cataloginventory_stock_item) SELECT item_id FROM cataloginventory_stock_item';
        $this->assertSame($sql . ' LIMIT 100', $this->guard()->assertReadOnly($sql));
    }

    public function testPlainCteIsUsableOnceItsBodyIsClosed(): void
    {
        $sql = 'WITH c AS (SELECT entity_id FROM customer_entity), d AS (SELECT entity_id FROM c) '
            . 'SELECT entity_id FROM d';
        $this->assertSame($sql . ' LIMIT 100', $this->guard()->assertReadOnly($sql));
    }

    public function testRejectsPhantomCteRegisteredByAWithRollupClause(): void
    {
        // `WITH ROLLUP` must not arm CTE registration, or a WINDOW name becomes an exempt table.
        $this->assertRejected(
            'SELECT store_id FROM sales_order GROUP BY store_id WITH ROLLUP WINDOW '
            . 'cataloginventory_stock_item AS (ORDER BY store_id) UNION SELECT item_id FROM '
            . 'cataloginventory_stock_item',
            'cataloginventory_stock_item'
        );
        $this->assertRejected(
            'SELECT d.store_id FROM (SELECT store_id FROM sales_order GROUP BY store_id WITH ROLLUP WINDOW '
            . 'cataloginventory_stock_item AS (ORDER BY store_id)) d, cataloginventory_stock_item',
            'cataloginventory_stock_item'
        );
    }

    public function testAllowsGroupByWithRollup(): void
    {
        $sql = 'SELECT store_id, COUNT(*) FROM sales_order GROUP BY store_id WITH ROLLUP';
        $this->assertSame($sql . ' LIMIT 100', $this->guard()->assertReadOnly($sql));
    }

    // ------------------------------------------------ FROM inside function arguments

    /**
     * @return array<string, array{string}>
     */
    public static function functionArgumentFromProvider(): array
    {
        return [
            'extract' => ['SELECT EXTRACT(YEAR FROM created_at) FROM sales_order'],
            'trim' => ["SELECT TRIM(LEADING '0' FROM sku) FROM catalog_product_entity"],
            'substring' => ['SELECT SUBSTRING(sku FROM 2 FOR 3) FROM catalog_product_entity'],
            'position' => ["SELECT POSITION('a' IN sku) FROM catalog_product_entity"],
            'extract in a where clause' => [
                'SELECT COUNT(*) FROM sales_order WHERE EXTRACT(YEAR FROM created_at) = 2024',
            ],
        ];
    }

    /**
     * @dataProvider functionArgumentFromProvider
     * @param string $sql
     * @return void
     */
    public function testFromInsideFunctionArgumentsIsNotATablePosition(string $sql): void
    {
        $this->assertSame($sql . ' LIMIT 100', $this->guard()->assertReadOnly($sql));
    }

    public function testSubqueryInsideFunctionArgumentsIsStillChecked(): void
    {
        $this->assertRejected(
            'SELECT IFNULL((SELECT MAX(id) FROM unlisted_ledger), 0) FROM customer_entity',
            'unlisted_ledger'
        );
        $this->assertRejected('SELECT EXTRACT(YEAR FROM created_at) FROM unlisted_ledger', 'unlisted_ledger');
        $this->assertRejected('SELECT EXTRACT(YEAR FROM admin_user) FROM sales_order', 'admin_user');
    }

    // ------------------------------------------------ structural-keyword exemption

    public function testRejectsLateralAsAFunctionName(): void
    {
        // Probed on MariaDB 10.6: `lateral` is non-reserved there, so it can name a stored
        // function and must not carry the structural exemption.
        $this->assertRejected('SELECT lateral(1)', 'not a permitted function');
    }
}
