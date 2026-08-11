<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Moves saved values from the old `magebit_mcp/db_tools/*` group to the module's own section, which
 * exists so enabling raw SQL is not gated by the same ACL resource as the core MCP settings.
 */
class MigrateDbToolsConfigPaths implements DataPatchInterface
{
    private const TABLE = 'core_config_data';

    private const OLD_PREFIX = 'magebit_mcp/db_tools/';
    private const NEW_PREFIX = 'magebit_mcp_db_tools/general/';

    /** @var list<string> */
    private const FIELDS = [
        'enabled',
        'default_rows',
        'row_limit_ceiling',
        'statement_timeout_seconds',
        'allowed_tables',
        'extra_denied_tables',
    ];

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * Renames each old row to its new path, scope by scope. A row already present at the new path
     * for the same scope/scope_id wins and the stale old row is dropped instead of renamed, so a
     * re-run — or an install that already has both — never hits the `(scope, scope_id, path)`
     * unique index.
     *
     * @return $this
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(self::TABLE);

        foreach (self::FIELDS as $field) {
            $oldPath = self::OLD_PREFIX . $field;
            $newPath = self::NEW_PREFIX . $field;

            $oldRows = $connection->fetchAll(
                $connection->select()
                    ->from($table, ['config_id', 'scope', 'scope_id'])
                    ->where('path = ?', $oldPath)
            );

            foreach ($oldRows as $oldRow) {
                $configId = (int) $oldRow['config_id'];
                $scope = (string) $oldRow['scope'];
                $scopeId = (int) $oldRow['scope_id'];

                $newRows = $connection->fetchAll(
                    $connection->select()
                        ->from($table, ['config_id'])
                        ->where('path = ?', $newPath)
                        ->where('scope = ?', $scope)
                        ->where('scope_id = ?', $scopeId)
                );

                if ($newRows !== []) {
                    $connection->delete($table, ['config_id = ?' => $configId]);
                    continue;
                }

                $connection->update(
                    $table,
                    ['path' => $newPath],
                    ['config_id = ?' => $configId]
                );
            }
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function getAliases(): array
    {
        return [];
    }
}
