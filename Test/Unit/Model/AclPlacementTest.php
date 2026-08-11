<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Test\Unit\Model;

use Magebit\McpDbTools\Tool\Db\Query;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class AclPlacementTest extends TestCase
{
    private const TOOLS_NODE = 'Magebit_Mcp::tools';

    /**
     * @return SimpleXMLElement
     */
    private function acl(): SimpleXMLElement
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/acl.xml');
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        return $xml;
    }

    /**
     * The scope pickers in Magebit_Mcp only iterate children of `Magebit_Mcp::tools`, so a tool
     * resource declared anywhere else can never be granted to a scoped token.
     *
     * @return void
     */
    public function testToolResourceIsDeclaredUnderTheSharedToolsNode(): void
    {
        $matches = $this->acl()->xpath(
            sprintf('//resource[@id="%s"]/resource[@id="%s"]', self::TOOLS_NODE, Query::ACL_RESOURCE)
        );

        self::assertNotEmpty(
            $matches,
            sprintf('%s must be a child of %s.', Query::ACL_RESOURCE, self::TOOLS_NODE)
        );
    }

    /**
     * @return void
     */
    public function testNoBespokeTopLevelModuleNodeRemains(): void
    {
        self::assertEmpty(
            $this->acl()->xpath('//resource[@id="Magebit_McpDbTools::mcp"]'),
            'The module must not invent its own top-level node; nest under Magebit_Mcp::mcp.'
        );
    }
}
