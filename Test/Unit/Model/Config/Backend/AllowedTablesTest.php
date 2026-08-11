<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Test\Unit\Model\Config\Backend;

use Magebit\McpDbTools\Model\Config\Backend\AllowedTables;
use Magebit\McpDbTools\Model\Config\Backend\DeniedTables;
use Magebit\McpDbTools\Model\TableAccessPolicy;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class AllowedTablesTest extends TestCase
{
    /**
     * @param class-string<AllowedTables> $class
     * @param string $value
     * @return AllowedTables
     */
    private function fieldOf(string $class, string $value): AllowedTables
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $policy = $this->createMock(TableAccessPolicy::class);
        $policy->method('isValidEntry')
            ->willReturnCallback(static fn (string $e): bool => preg_match('/^[a-z0-9_$]+$/', $e) === 1);
        $policy->method('findProtectedIdentifier')
            ->willReturnCallback(static fn (string $n): ?string => $n === 'admin_user' ? 'admin_user' : null);

        $field = new $class(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            $policy
        );
        $field->setValue($value);

        return $field;
    }

    /**
     * @param string $value
     * @return AllowedTables
     */
    private function field(string $value): AllowedTables
    {
        return $this->fieldOf(AllowedTables::class, $value);
    }

    /**
     * @return void
     */
    public function testAcceptsBareNamesBlankLinesAndComments(): void
    {
        $field = $this->field("# a comment\n\nsales_order\ncms_page\n");

        self::assertSame($field, $field->beforeSave());
    }

    /**
     * @return void
     */
    public function testAcceptsQuotedAndCrDelimitedEntries(): void
    {
        $field = $this->field("`sales_order`\rcms_page");

        self::assertSame($field, $field->beforeSave());
    }

    /**
     * @return void
     */
    public function testRefusesAMalformedEntryAndNamesTheLine(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/sales_order\*/');

        $this->field("cms_page\nsales_order*")->beforeSave();
    }

    /**
     * @return void
     */
    public function testRefusesADottedEntry(): void
    {
        $this->expectException(LocalizedException::class);

        $this->field('magento.sales_order')->beforeSave();
    }

    /**
     * @return void
     */
    public function testRefusesAProtectedTableListedAsAllowed(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/admin_user/');

        $this->field("cms_page\nadmin_user")->beforeSave();
    }

    /**
     * @return void
     */
    public function testAnEmptyValueIsAllowedBecauseItIsTheShippedDefault(): void
    {
        $field = $this->field('');

        self::assertSame($field, $field->beforeSave());
    }

    /**
     * Naming a protected table is the entire point of the deny field, so the check that refuses it
     * on the allow field must not fire here.
     *
     * @return void
     */
    public function testTheDeniedListMayNameAProtectedTable(): void
    {
        $field = $this->fieldOf(DeniedTables::class, "admin_user\nmy_vault");

        self::assertSame($field, $field->beforeSave());
    }

    /**
     * @return void
     */
    public function testTheDeniedListStillRefusesAMalformedEntry(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/my_vault\*/');

        $this->fieldOf(DeniedTables::class, 'my_vault*')->beforeSave();
    }

    /**
     * @return void
     */
    public function testBothFieldsAreWiredInSystemXml(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        foreach (['allowed_tables' => AllowedTables::class, 'extra_denied_tables' => DeniedTables::class] as $id => $c) {
            $models = $xml->xpath(sprintf('//field[@id="%s"]/backend_model', $id));
            self::assertNotEmpty($models, sprintf('`%s` declares no backend model.', $id));
            self::assertSame($c, ltrim((string) $models[0], '\\'));
        }
    }
}
