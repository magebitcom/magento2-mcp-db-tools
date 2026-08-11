<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Test\Unit\Model;

use Magebit\Mcp\Model\Config\ConfigWriteConfig;
use Magebit\Mcp\Model\Config\ConfigWritePolicy;
use Magebit\Mcp\Model\Config\SensitiveFieldGuard;
use Magebit\Mcp\Model\Util\ConfigPathFormat;
use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magento\Config\Model\Config\Structure;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The core's protected-prefix list is generic and knows nothing about this module's section name, so
 * `system.config.set` could enable `db.query` and widen its allowlist unless di.xml contributes it.
 */
class ConfigPrefixContributionTest extends TestCase
{
    /**
     * Every `magebit_mcp_db_tools/general/*` path this module gates itself with.
     *
     * @return list<string>
     */
    private static function ownPaths(): array
    {
        return [
            DbToolsConfig::XML_PATH_ENABLED,
            DbToolsConfig::XML_PATH_ALLOWED_TABLES,
            DbToolsConfig::XML_PATH_EXTRA_DENIED_TABLES,
            DbToolsConfig::XML_PATH_TABLE_ACL_MAP,
            DbToolsConfig::XML_PATH_ROW_LIMIT_CEILING,
            DbToolsConfig::XML_PATH_DEFAULT_ROWS,
            DbToolsConfig::XML_PATH_STATEMENT_TIMEOUT_SECONDS,
        ];
    }

    /**
     * @param string $type
     * @param string $argument
     * @return list<string>
     */
    private function prefixesFromDi(string $type, string $argument): array
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/di.xml');
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $items = $xml->xpath(sprintf(
            '//type[@name="%s"]/arguments/argument[@name="%s"]/item',
            $type,
            $argument
        ));

        return array_values(array_map('strval', $items ?: []));
    }

    /**
     * @return void
     */
    public function testDiContributesTheSectionToTheWritePolicy(): void
    {
        self::assertContains(
            'magebit_mcp_db_tools',
            $this->prefixesFromDi(ConfigWritePolicy::class, 'protectedPrefixes'),
            'etc/di.xml must contribute this module\'s section to ConfigWritePolicy::$protectedPrefixes.'
        );
    }

    /**
     * @return void
     */
    public function testDiContributesTheSectionToTheSensitiveFieldGuard(): void
    {
        self::assertContains(
            'magebit_mcp_db_tools',
            $this->prefixesFromDi(SensitiveFieldGuard::class, 'blockedPrefixes'),
            'etc/di.xml must contribute this module\'s section to SensitiveFieldGuard::$blockedPrefixes.'
        );
    }

    /**
     * Drives the real core class with the prefixes di.xml declares, so the contribution is checked
     * for the effect it is there for rather than for its presence in the XML.
     *
     * @return void
     */
    public function testTheContributedPrefixMakesEveryOwnPathUnwritable(): void
    {
        $config = $this->createMock(ConfigWriteConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getAllowedPaths')->willReturn(self::ownPaths());

        $policy = new ConfigWritePolicy(
            $config,
            new ConfigPathFormat(),
            $this->prefixesFromDi(ConfigWritePolicy::class, 'protectedPrefixes')
        );

        foreach (self::ownPaths() as $path) {
            self::assertSame(
                'magebit_mcp_db_tools',
                $policy->findProtectedPrefix($path),
                sprintf('"%s" must be protected even when allowlisted.', $path)
            );

            try {
                $policy->assertWritable($path);
                self::fail(sprintf('Expected "%s" to be refused as protected.', $path));
            } catch (LocalizedException $exception) {
                self::assertStringContainsString('is protected by', $exception->getMessage());
            }
        }
    }

    /**
     * @return void
     */
    public function testTheContributedPrefixMakesEveryOwnPathUnreadable(): void
    {
        $structure = $this->createMock(Structure::class);
        $structure->method('getElementByConfigPath')->willReturn(null);

        $guard = new SensitiveFieldGuard(
            $structure,
            $this->prefixesFromDi(SensitiveFieldGuard::class, 'blockedPrefixes')
        );

        foreach (self::ownPaths() as $path) {
            self::assertSame(
                'path_prefix_blocked',
                $guard->rejectionFor($path),
                sprintf('"%s" must not be readable back out through the config read tool.', $path)
            );
        }
    }
}
