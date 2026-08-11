<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model;

use Magebit\McpDbTools\Model\Config\DbToolsConfig;

/**
 * Translates the tables a statement names into the admin ACL resources the caller must hold, so raw
 * SQL cannot read what the domain tools would deny. {@see unmapped()} is the other half: an empty
 * {@see resolve()} is read as "no opinion", so the tool has to refuse those tables itself.
 */
class TableAclMap
{
    /**
     * @param DbToolsConfig $config
     * @param IdentifierMatcher $matcher
     */
    public function __construct(
        private readonly DbToolsConfig $config,
        private readonly IdentifierMatcher $matcher
    ) {
    }

    /**
     * ACL resources covering $tables, first-seen order, each listed once.
     *
     * @param array $tables
     * @phpstan-param list<string> $tables
     * @return array
     * @phpstan-return list<string>
     */
    public function resolve(array $tables): array
    {
        $resources = [];

        foreach ($tables as $table) {
            $resource = $this->find($table);
            if ($resource !== null && !in_array($resource, $resources, true)) {
                $resources[] = $resource;
            }
        }

        return $resources;
    }

    /**
     * The tables with no usable mapping, first-seen order, each listed once.
     *
     * @param array $tables
     * @phpstan-param list<string> $tables
     * @return array
     * @phpstan-return list<string>
     */
    public function unmapped(array $tables): array
    {
        $missing = [];

        foreach ($tables as $table) {
            if ($this->find($table) === null && !in_array($table, $missing, true)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * Entries are written without the install's table prefix, as in the allowlist. Narrow matching
     * only: a wide match here would let one entry grant access to every table sharing its words.
     *
     * @param string $table
     * @return string|null
     */
    private function find(string $table): ?string
    {
        $table = trim($table, " \t`\"'");
        if ($table === '') {
            return null;
        }

        foreach ($this->config->getTableAclMap() as $name => $resource) {
            if ($this->matcher->matchesExactOrPrefixed($table, $name)) {
                return $resource;
            }
        }

        return null;
    }
}
