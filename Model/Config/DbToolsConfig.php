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
 * Reader for every `magebit_mcp/db_tools/*` store-config value. Each getter falls back to a
 * safe built-in default so a blank or malformed field can never widen the caps.
 */
class DbToolsConfig
{
    public const XML_PATH_ENABLED = 'magebit_mcp/db_tools/enabled';
    public const XML_PATH_DEFAULT_ROWS = 'magebit_mcp/db_tools/default_rows';
    public const XML_PATH_ROW_LIMIT_CEILING = 'magebit_mcp/db_tools/row_limit_ceiling';
    public const XML_PATH_STATEMENT_TIMEOUT_SECONDS = 'magebit_mcp/db_tools/statement_timeout_seconds';
    public const XML_PATH_EXTRA_DENIED_TABLES = 'magebit_mcp/db_tools/extra_denied_tables';

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
     * Operator-supplied additions to the built-in table deny-list. One name per line;
     * blank lines and `#` comment lines are ignored.
     *
     * @return list<string>
     */
    public function getExtraDeniedTables(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_EXTRA_DENIED_TABLES);
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
