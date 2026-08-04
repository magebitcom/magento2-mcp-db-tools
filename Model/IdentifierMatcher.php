<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;

/**
 * The two identifier-matching primitives the guard's table and column layers are built from,
 * kept side by side so the difference between them is impossible to miss.
 *
 * {@see findWordRunMatch()} is **wide**: exact, `<prefix><term>`, and "the term's words appear
 * as a contiguous run". Widening a match is fail-**closed** when the answer is used to *refuse*
 * a query, which is the only direction it may ever be used in.
 *
 * {@see matchesExactOrPrefixed()} is **narrow**: exact or `<prefix><term>` and nothing else. It
 * is the only matcher an *allow* decision may use. Word-run matching in the allow direction
 * fails open catastrophically — an allowed `customer_entity` would admit
 * `customer_entity_backup`, and an allowed bare `user` would admit `admin_user`.
 */
class IdentifierMatcher
{
    /**
     * Deployment-config path of the store's own database name.
     */
    public const CONFIG_PATH_DB_NAME = ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTION_DEFAULT
        . '/' . ConfigOptionsListConstants::KEY_NAME;

    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Widest match, for refusals only: exact, `<prefix><term>`, or the term's underscore-delimited
     * words appearing as a contiguous run inside the identifier.
     *
     * @param string $identifier
     * @param list<string> $terms
     * @return string|null The longest matching term, so a message names the most specific one.
     */
    public function findWordRunMatch(string $identifier, array $terms): ?string
    {
        $lower = strtolower($identifier);
        $prefix = $this->getTablePrefix();
        $words = explode('_', $lower);
        $best = null;

        foreach ($terms as $term) {
            $matched = $lower === $term
                || ($prefix !== '' && $lower === $prefix . $term)
                || $this->containsWordRun($words, explode('_', $term));

            if ($matched && ($best === null || strlen($term) > strlen($best))) {
                $best = $term;
            }
        }

        return $best;
    }

    /**
     * Narrowest match, and the only one an allow decision may use: the identifier is exactly the
     * term, or exactly the term behind this install's table prefix.
     *
     * @param string $identifier
     * @param string $term
     * @return bool
     */
    public function matchesExactOrPrefixed(string $identifier, string $term): bool
    {
        $lower = strtolower($identifier);
        $term = strtolower($term);
        $prefix = $this->getTablePrefix();

        return $lower === $term || ($prefix !== '' && $lower === $prefix . $term);
    }

    /**
     * @return string
     */
    public function getTablePrefix(): string
    {
        $prefix = $this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_DB_PREFIX);

        return is_string($prefix) ? strtolower(trim($prefix)) : '';
    }

    /**
     * Name of the store's own database. Empty when it cannot be read, which makes every
     * schema-qualified reference fail closed.
     *
     * @return string
     */
    public function getDatabaseName(): string
    {
        $name = $this->deploymentConfig->get(self::CONFIG_PATH_DB_NAME);

        return is_string($name) ? strtolower(trim($name)) : '';
    }

    /**
     * Whether $needle appears as a contiguous run of whole words inside $haystack. Words are
     * underscore-delimited, so a camelCase spelling of a protected name (`adminUser`) is not
     * recognised — irrelevant for MySQL identifiers, which are snake_case throughout Magento.
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
}
