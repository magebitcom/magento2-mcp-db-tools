<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model\Config\Backend;

use Magebit\McpDbTools\Model\TableAccessPolicy;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Backend model for `magebit_mcp_db_tools/general/allowed_tables`. The policy throws on a malformed
 * entry on every request, so one typo would refuse every query with no feedback in the admin.
 */
class AllowedTables extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param TableAccessPolicy $tablePolicy
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly TableAccessPolicy $tablePolicy,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $raw = $this->getValue();
        if ($raw === null || $raw === '') {
            return parent::beforeSave();
        }

        if (!is_string($raw)) {
            throw new LocalizedException(__('This field must be plain text, one table name per line.'));
        }

        $malformed = [];
        $protected = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line, " \t`\"'");
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $name = strtolower($line);
            if (!$this->tablePolicy->isValidEntry($name)) {
                $malformed[] = $line;
                continue;
            }

            if ($this->refusesProtectedNames() && $this->tablePolicy->findProtectedIdentifier($name) !== null) {
                $protected[] = $name;
            }
        }

        if ($malformed !== []) {
            throw new LocalizedException(
                __(
                    'These entries are not bare table names, so no query could run: %1. Use one '
                    . 'unquoted, unprefixed name per line; prefix a line with # to comment it out.',
                    implode(', ', $malformed)
                )
            );
        }

        if ($protected !== []) {
            throw new LocalizedException(
                __(
                    'These tables are permanently protected and can never be read, so listing them '
                    . 'would refuse every query: %1. Remove those lines.',
                    implode(', ', $protected)
                )
            );
        }

        return parent::beforeSave();
    }

    /**
     * @return bool
     */
    protected function refusesProtectedNames(): bool
    {
        return true;
    }
}
