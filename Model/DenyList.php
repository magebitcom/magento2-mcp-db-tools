<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model;

use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;

/**
 * The protected-identifier data behind {@see QueryGuard} layers 5 and 6: which table and
 * column names may never appear in a query, however they are spelled.
 *
 * Matching is deliberately wider than string equality, because a query reaches the guard
 * after `{{table}}` substitution and may name a table by its physical, prefixed name:
 *
 * 1. exact, case-insensitive match on the identifier;
 * 2. match on `<db table prefix><denied name>`, so `mage_admin_user` is caught;
 * 3. match when the denied name's underscore-delimited words appear as a contiguous run
 *    inside the identifier — this catches unknown wrappers (`custom_admin_user_map`) and
 *    compound secrets (`access_token` for the denied word `token`) and fails closed.
 *
 * A small set of server-owned identifiers (`mysql`, `sys`, `performance_schema`,
 * `processlist`) is matched exactly only: they are single common words, so component
 * matching would deny far too much.
 */
class DenyList
{
    /**
     * Tables whose contents are credentials, session material, or reset tokens.
     */
    private const DENIED_TABLES = [
        'admin_user',
        'admin_passwords',
        'admin_user_session',
        'admin_user_expiration',
        'oauth_token',
        'oauth_consumer',
        'oauth_token_request_log',
        'magebit_mcp_token',
        'magebit_mcp_oauth_client',
        'magebit_mcp_oauth_auth_code',
        'magebit_mcp_oauth_refresh_token',
        'persistent_session',
        'password_reset_request_event',
    ];

    /**
     * Server-owned identifiers. `mysql.user` holds password hashes and `processlist`
     * leaks the SQL text other sessions are running.
     */
    private const DENIED_EXACT_IDENTIFIERS = [
        'mysql',
        'performance_schema',
        'sys',
        'processlist',
    ];

    /**
     * Column names that carry secrets. `token`, `secret` and `api_key` are broad on
     * purpose — a query naming them is refused rather than silently narrowed.
     */
    private const DENIED_COLUMNS = [
        'password_hash',
        'password',
        'rp_token',
        'rp_token_created_at',
        'x_forwarded_for',
        'token',
        'secret',
        'api_key',
        'authentication_string',
    ];

    /**
     * @var list<string>|null
     */
    private ?array $tableTerms = null;

    /**
     * @param DbToolsConfig $config
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly DbToolsConfig $config,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * @param string $identifier
     * @return string|null The denied name that matched, for the rejection message.
     */
    public function findDeniedTable(string $identifier): ?string
    {
        $lower = strtolower($identifier);
        if (in_array($lower, self::DENIED_EXACT_IDENTIFIERS, true)) {
            return $lower;
        }

        return $this->match($lower, $this->getTableTerms());
    }

    /**
     * @param string $identifier
     * @return string|null The denied name that matched, for the rejection message.
     */
    public function findDeniedColumn(string $identifier): ?string
    {
        return $this->match(strtolower($identifier), self::DENIED_COLUMNS);
    }

    /**
     * @return list<string>
     */
    public function getDeniedTables(): array
    {
        return $this->getTableTerms();
    }

    /**
     * @return list<string>
     */
    public function getDeniedColumns(): array
    {
        return self::DENIED_COLUMNS;
    }

    /**
     * Returns the longest matching term, so the rejection message names the most specific
     * protected identifier rather than a shorter one nested inside it.
     *
     * @param string $lowerIdentifier
     * @param list<string> $terms
     * @return string|null
     */
    private function match(string $lowerIdentifier, array $terms): ?string
    {
        $prefix = $this->getTablePrefix();
        $words = explode('_', $lowerIdentifier);
        $best = null;

        foreach ($terms as $term) {
            $matched = $lowerIdentifier === $term
                || ($prefix !== '' && $lowerIdentifier === $prefix . $term)
                || $this->containsWordRun($words, explode('_', $term));

            if ($matched && ($best === null || strlen($term) > strlen($best))) {
                $best = $term;
            }
        }

        return $best;
    }

    /**
     * Whether $needle appears as a contiguous run of whole words inside $haystack.
     *
     * @param list<string> $haystack
     * @param list<string> $needle
     * @return bool
     */
    private function containsWordRun(array $haystack, array $needle): bool
    {
        $span = count($needle);
        $limit = count($haystack) - $span;
        for ($offset = 0; $offset <= $limit; $offset++) {
            if (array_slice($haystack, $offset, $span) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function getTableTerms(): array
    {
        if ($this->tableTerms === null) {
            $this->tableTerms = array_values(
                array_unique(array_merge(self::DENIED_TABLES, $this->config->getExtraDeniedTables()))
            );
        }

        return $this->tableTerms;
    }

    /**
     * @return string
     */
    private function getTablePrefix(): string
    {
        $prefix = $this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_DB_PREFIX);

        return is_string($prefix) ? strtolower(trim($prefix)) : '';
    }
}
