<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model;

use Magebit\McpDbTools\Model\Config\DbToolsConfig;
use Magento\Framework\Exception\LocalizedException;

/**
 * Which tables the SQL tool may read: a protected set vetoing a default-empty allowlist. The
 * protected floor is a class constant, so a di.xml merge can only ever add to it; callers must run
 * *both* checks, and allow decisions use only the narrow {@see IdentifierMatcher}.
 */
class TableAccessPolicy
{
    /**
     * A bare, unqualified identifier — the only shape an allowlist entry may take.
     */
    private const ENTRY_PATTERN = '/^[a-z0-9_$]+$/';

    /**
     * Word-run matched, so wrapper tables are caught too. In code rather than `di.xml` because
     * Magento merges array arguments by item name: a downstream module re-declaring `core_config_data`
     * would otherwise replace that entry and silently drop it from the protected set.
     *
     * @var list<string>
     */
    private const PROTECTED_TABLES_FLOOR = [
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
        // In-flight authorizations: params_json carries client_id, redirect_uri, state,
        // code_challenge and scope, none of which the column deny-list can see.
        'magebit_mcp_oauth_authorize_handoff',
        // This tool's own audit trail holds unredacted SQL, literals included, so allowlisting it
        // would let one operator read another operator's queries.
        'magebit_mcp_audit_log',
        'persistent_session',
        // Credential-bearing; belongs with oauth_token on principle, even though the column
        // deny-list already catches its token columns.
        'admin_adobe_ims_webapi',
        'password_reset_request_event',
        // Admin two-factor secrets: `encoded_config` holds the TOTP shared secret, which is the
        // second factor for every admin account.
        'tfa_user_config',
        // Encrypted payment-gateway credentials and API keys; system.config.get redacts them.
        'core_config_data',
    ];

    /**
     * Exact or table-prefixed: common single words where word-run matching would refuse ordinary
     * columns.
     *
     * @var list<string>
     */
    private const PROTECTED_PREFIXED_FLOOR = ['session', 'cache', 'cache_tag'];

    /**
     * Strict equality only: server-owned words that word-run matching would over-deny.
     *
     * @var list<string>
     */
    private const PROTECTED_IDENTIFIERS_FLOOR = [
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
        'processlist',
        'innodb_trx',
        'user_privileges',
    ];

    /**
     * @var list<string>|null
     */
    private ?array $protectedTerms = null;

    /**
     * @var list<string>|null
     */
    private ?array $allowedTables = null;

    /**
     * @param DbToolsConfig $config
     * @param IdentifierMatcher $matcher
     * @param array<array-key, string> $protectedTables Added to {@see self::PROTECTED_TABLES_FLOOR}.
     * @param array<array-key, string> $protectedIdentifiers Added to
     *        {@see self::PROTECTED_IDENTIFIERS_FLOOR}.
     * @param array<array-key, string> $protectedPrefixedIdentifiers Added to
     *        {@see self::PROTECTED_PREFIXED_FLOOR}.
     */
    public function __construct(
        private readonly DbToolsConfig $config,
        private readonly IdentifierMatcher $matcher,
        private readonly array $protectedTables = [],
        private readonly array $protectedIdentifiers = [],
        private readonly array $protectedPrefixedIdentifiers = []
    ) {
    }

    /**
     * Three categories, narrowest first. Splitting them is what lets `session` protect the table
     * (bare or prefixed) without refusing `customer_entity.session_cutoff`, which the wide matcher
     * would — the column side of that trade lives in {@see ColumnDenyList}.
     *
     * @param string $identifier
     * @return string|null The protected name that matched, for the rejection message.
     */
    public function findProtectedIdentifier(string $identifier): ?string
    {
        $lower = strtolower($identifier);

        foreach ($this->protectedIdentifiers() as $term) {
            if ($lower === strtolower($term)) {
                return $lower;
            }
        }

        foreach ($this->protectedPrefixedIdentifiers() as $term) {
            if ($this->matcher->matchesExactOrPrefixed($lower, $term)) {
                return strtolower($term);
            }
        }

        return $this->matcher->findWordRunMatch($lower, $this->getProtectedTerms());
    }

    /**
     * Whether a configured entry has the only shape the policy can ever match. Shared with the
     * admin backend models so save-time and run-time agree on what is well formed.
     *
     * @param string $entry
     * @return bool
     */
    public function isValidEntry(string $entry): bool
    {
        // `=== 1` rather than `!== 0`: a PCRE failure must refuse, not wave the entry through.
        return preg_match(self::ENTRY_PATTERN, $entry) === 1;
    }

    /**
     * Refuses the query outright when the allowlist cannot be honoured as written: a malformed
     * entry, a protected name listed as allowed, or nothing listed at all.
     *
     * @return void
     * @throws LocalizedException
     */
    public function assertUsableAllowlist(): void
    {
        if ($this->getAllowedTables() === []) {
            throw new LocalizedException(
                __(
                    'No tables are allowed for reading, so no query can run. List the tables this tool '
                    . 'may read, one per line, under Stores > Configuration > Magebit > '
                    . 'MCP Server - Database Reader > MCP Database Reader > Allowed Tables (%1).',
                    DbToolsConfig::XML_PATH_ALLOWED_TABLES
                )
            );
        }
    }

    /**
     * @param string $identifier
     * @return bool
     * @throws LocalizedException When the configured allowlist itself is unusable.
     */
    public function isAllowed(string $identifier): bool
    {
        foreach ($this->getAllowedTables() as $entry) {
            if ($this->matcher->matchesExactOrPrefixed($identifier, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $schema
     * @return bool
     */
    public function isCurrentDatabase(string $schema): bool
    {
        $database = $this->matcher->getDatabaseName();

        return $database !== '' && strtolower($schema) === $database;
    }

    /**
     * @return list<string>
     * @throws LocalizedException When an entry is malformed or names a protected table.
     */
    public function getAllowedTables(): array
    {
        if ($this->allowedTables === null) {
            $entries = $this->config->getAllowedTables();

            foreach ($entries as $entry) {
                if (!$this->isValidEntry($entry)) {
                    throw new LocalizedException(
                        __(
                            'The Allowed Tables entry `%1` is not a plain table name. List one bare table '
                            . 'name per line, with no schema qualifier, quoting or wildcards (%2).',
                            $entry,
                            DbToolsConfig::XML_PATH_ALLOWED_TABLES
                        )
                    );
                }

                $protected = $this->findProtectedEntry($entry);
                if ($protected !== null) {
                    throw new LocalizedException(
                        __(
                            'The Allowed Tables list contains `%1`, which is permanently protected and can '
                            . 'never be read. Remove that line from %2.',
                            $entry,
                            DbToolsConfig::XML_PATH_ALLOWED_TABLES
                        )
                    );
                }
            }

            $this->allowedTables = $entries;
        }

        return $this->allowedTables;
    }

    /**
     * @return list<string>
     */
    public function getProtectedTerms(): array
    {
        if ($this->protectedTerms === null) {
            $terms = [];
            $declared = array_merge(
                self::PROTECTED_TABLES_FLOOR,
                array_values($this->protectedTables),
                $this->config->getExtraDeniedTables()
            );
            foreach ($declared as $term) {
                $term = strtolower(trim($term));
                if ($term !== '') {
                    $terms[] = $term;
                }
            }

            $this->protectedTerms = array_values(array_unique($terms));
        }

        return $this->protectedTerms;
    }

    /**
     * The shipped floor plus whatever di.xml added. Merged here so no read path can see one without
     * the other.
     *
     * @return list<string>
     */
    private function protectedIdentifiers(): array
    {
        return array_values(array_unique(
            array_merge(self::PROTECTED_IDENTIFIERS_FLOOR, array_values($this->protectedIdentifiers))
        ));
    }

    /**
     * @return list<string>
     */
    private function protectedPrefixedIdentifiers(): array
    {
        return array_values(array_unique(
            array_merge(self::PROTECTED_PREFIXED_FLOOR, array_values($this->protectedPrefixedIdentifiers))
        ));
    }

    /**
     * Exact / prefixed match of an allowlist entry against the protected set, for the config-time
     * sanity check only. Deliberately narrower than {@see findProtectedIdentifier()}.
     *
     * @param string $entry
     * @return string|null
     */
    private function findProtectedEntry(string $entry): ?string
    {
        foreach ($this->protectedIdentifiers() as $term) {
            if (strtolower($entry) === strtolower($term)) {
                return strtolower($term);
            }
        }

        foreach ($this->protectedPrefixedIdentifiers() as $term) {
            if ($this->matcher->matchesExactOrPrefixed($entry, $term)) {
                return strtolower($term);
            }
        }

        foreach ($this->getProtectedTerms() as $term) {
            if ($this->matcher->matchesExactOrPrefixed($entry, $term)) {
                return $term;
            }
        }

        return null;
    }
}
