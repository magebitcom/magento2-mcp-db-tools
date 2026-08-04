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
 * Which tables the SQL tool may read. Two layers, and the order between them is the point:
 *
 * 1. **Protected set** — never readable, whatever else says otherwise. Injected as the
 *    `protectedTables` / `protectedIdentifiers` DI array arguments from this module's own
 *    `etc/di.xml`, deliberately neither a constant nor admin config. Weakening the set therefore
 *    requires filesystem write access plus `setup:di:compile`, putting it out of reach of an
 *    admin-panel user, a stolen admin session, and anything the MCP surface itself can reach —
 *    while a project or satellite module can still *extend* it (its own credential tables) with a
 *    di.xml merge instead of patching this module. A constant would block that legitimate
 *    extension; admin config would make the protected set weakenable by exactly the actor it
 *    protects against.
 * 2. **Allowlist** — `magebit_mcp/db_tools/allowed_tables`, empty by default, so a freshly enabled
 *    tool refuses every query until an operator names the tables it may read. A denylist defaults
 *    to *readable* for every table a future extension adds and rots as upstream renames things,
 *    which is why the primary control is this way round.
 *
 * The caller runs the protected check *and* the allow check — never one instead of the other, so
 * an allowlist entry can never re-open a protected name. Two matchers exist for a reason; see
 * {@see IdentifierMatcher}. Allow decisions use the narrow one only.
 *
 * Config-time sanity is enforced in {@see assertUsableAllowlist()}: a protected name spelled
 * exactly (or with this install's table prefix) in the allowlist is a hard refusal that names the
 * offending line, never a silently dropped entry. Entries that would be caught by the *wider*
 * protected matching (`custom_admin_user_map` for `admin_user`) stay legal at config time and are
 * refused per query by the veto instead, so this class never guesses at intent.
 */
class TableAccessPolicy
{
    /**
     * A bare, unqualified identifier — the only shape an allowlist entry may take.
     */
    private const ENTRY_PATTERN = '/^[a-z0-9_$]+$/';

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
     * @param array<array-key, string> $protectedTables Word-run matched: multi-word names whose
     *        wrappers (`custom_admin_user_map`) must be caught too.
     * @param array<array-key, string> $protectedIdentifiers Matched by strict equality only:
     *        server-owned single words (`mysql`, `sys`) that word-run matching would over-deny.
     */
    public function __construct(
        private readonly DbToolsConfig $config,
        private readonly IdentifierMatcher $matcher,
        private readonly array $protectedTables = [],
        private readonly array $protectedIdentifiers = []
    ) {
    }

    /**
     * @param string $identifier
     * @return string|null The protected name that matched, for the rejection message.
     */
    public function findProtectedIdentifier(string $identifier): ?string
    {
        $lower = strtolower($identifier);

        foreach ($this->protectedIdentifiers as $term) {
            if ($lower === strtolower($term)) {
                return $lower;
            }
        }

        return $this->matcher->findWordRunMatch($lower, $this->getProtectedTerms());
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
                    . 'may read, one per line, under Stores > Configuration > Magebit > MCP Server > '
                    . 'MCP Database Reader > Allowed Tables (%1).',
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
                // `!== 1` rather than `=== 0`: a PCRE failure must refuse, not wave the entry through.
                if (preg_match(self::ENTRY_PATTERN, $entry) !== 1) {
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
            $declared = array_merge(array_values($this->protectedTables), $this->config->getExtraDeniedTables());
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
     * Exact / prefixed match of an allowlist entry against the protected set, for the config-time
     * sanity check only. Deliberately narrower than {@see findProtectedIdentifier()}.
     *
     * @param string $entry
     * @return string|null
     */
    private function findProtectedEntry(string $entry): ?string
    {
        foreach ($this->protectedIdentifiers as $term) {
            if (strtolower($entry) === strtolower($term)) {
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
