<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model\Config\Backend;

/**
 * Backend model for `magebit_mcp_db_tools/general/extra_denied_tables`. Same shape check as the
 * allow field, without the protected-name refusal: naming a protected table here is the point.
 */
class DeniedTables extends AllowedTables
{
    /**
     * @return bool
     */
    protected function refusesProtectedNames(): bool
    {
        return false;
    }
}
