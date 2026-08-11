<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Reader for every `magebit_mcp_db_tools/general/*` store-config value. Each getter falls back to
 * a safe built-in default so a blank or malformed field can never widen the caps.
 */
class DbToolsConfig
{
    public const XML_PATH_ENABLED = 'magebit_mcp_db_tools/general/enabled';
    public const XML_PATH_DEFAULT_ROWS = 'magebit_mcp_db_tools/general/default_rows';
    public const XML_PATH_ROW_LIMIT_CEILING = 'magebit_mcp_db_tools/general/row_limit_ceiling';
    public const XML_PATH_STATEMENT_TIMEOUT_SECONDS = 'magebit_mcp_db_tools/general/statement_timeout_seconds';
    public const XML_PATH_ALLOWED_TABLES = 'magebit_mcp_db_tools/general/allowed_tables';
    public const XML_PATH_EXTRA_DENIED_TABLES = 'magebit_mcp_db_tools/general/extra_denied_tables';
    public const XML_PATH_TABLE_ACL_MAP = 'magebit_mcp_db_tools/general/table_acl_map';

    /**
     * The only shape an ACL resource id can have. Anything else could never resolve, so a line
     * carrying it is dropped and its table stays unmapped — and therefore refused.
     */
    private const ACL_RESOURCE_PATTERN = '/^[A-Za-z0-9_]+::[A-Za-z0-9_]+$/';

    public const DEFAULT_ROWS = 100;
    public const DEFAULT_ROW_LIMIT_CEILING = 1000;
    public const DEFAULT_STATEMENT_TIMEOUT_SECONDS = 10;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Row cap appended when the query carries no LIMIT of its own.
     * @return int
     */
    public function getDefaultRows(): int
    {
        return $this->readPositiveInt(self::XML_PATH_DEFAULT_ROWS, self::DEFAULT_ROWS);
    }

    /**
     * Hard ceiling on any caller-requested LIMIT.
     * @return int
     */
    public function getRowLimitCeiling(): int
    {
        return $this->readPositiveInt(self::XML_PATH_ROW_LIMIT_CEILING, self::DEFAULT_ROW_LIMIT_CEILING);
    }

    /**
     * @return int
     */
    public function getStatementTimeoutSeconds(): int
    {
        return $this->readPositiveInt(
            self::XML_PATH_STATEMENT_TIMEOUT_SECONDS,
            self::DEFAULT_STATEMENT_TIMEOUT_SECONDS
        );
    }

    /**
     * The only tables the SQL tool may read. Empty — the shipped default — means every query is
     * refused, so enabling the tool and choosing what it can see stay two deliberate acts.
     *
     * @return list<string>
     */
    public function getAllowedTables(): array
    {
        return $this->readTableList(self::XML_PATH_ALLOWED_TABLES);
    }

    /**
     * Operator-supplied additions to the protected set, on top of the allowlist. Entries here
     * only ever remove access; they cannot grant it.
     *
     * @return list<string>
     */
    public function getExtraDeniedTables(): array
    {
        return $this->readTableList(self::XML_PATH_EXTRA_DENIED_TABLES);
    }

    /**
     * Table name to the admin ACL resource a caller must hold to read it, one `table:resource` per
     * line. Shipped empty, so no table is readable until an operator says which permission it takes.
     *
     * @return array<string, string>
     */
    public function getTableAclMap(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_TABLE_ACL_MAP);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $map = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            $separator = strpos($line, ':');
            if ($line === '' || str_starts_with($line, '#') || $separator === false) {
                continue;
            }

            $table = strtolower(trim(substr($line, 0, $separator), " \t`\"'"));
            $resource = trim(substr($line, $separator + 1));
            if ($table === '' || preg_match(self::ACL_RESOURCE_PATTERN, $resource) !== 1) {
                continue;
            }

            $map[$table] = $resource;
        }

        return $map;
    }

    /**
     * One name per line; blank lines and `#` comment lines are ignored.
     *
     * @param string $path
     * @return list<string>
     */
    private function readTableList(string $path): array
    {
        $raw = $this->scopeConfig->getValue($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $tables = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line, " \t`\"'");
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $tables[] = strtolower($line);
        }

        return array_values(array_unique($tables));
    }

    /**
     * @param string $path
     * @param int $fallback
     * @return int
     */
    private function readPositiveInt(string $path, int $fallback): int
    {
        $value = $this->scopeConfig->getValue($path);
        $value = is_scalar($value) ? (int) $value : 0;
        return $value > 0 ? $value : $fallback;
    }
}
