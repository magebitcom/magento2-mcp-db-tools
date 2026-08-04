<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model;

/**
 * Column names that may never appear in a query, however they are spelled.
 *
 * Deliberately an independent layer, not something the table allowlist can absorb: a table
 * allowlist cannot protect `customer_entity.password_hash` / `rp_token` or
 * `sales_order_payment.cc_*`, because those live on tables any useful allowlist contains. The
 * column scan therefore runs on every statement regardless of which tables it names.
 *
 * Matching is the wide, fail-closed one ({@see IdentifierMatcher::findWordRunMatch()}), so
 * compound secrets (`access_token` for the term `token`) are refused rather than silently
 * narrowed.
 */
class ColumnDenyList
{
    /**
     * `token`, `secret` and `api_key` are broad on purpose — a query naming them is refused
     * rather than silently narrowed.
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
        // Payment-card material on sales_order_payment / quote_payment.
        'cc_number_enc',
        'cc_cid_enc',
        'cc_owner',
        'cc_last4',
    ];

    /**
     * @param IdentifierMatcher $matcher
     */
    public function __construct(
        private readonly IdentifierMatcher $matcher
    ) {
    }

    /**
     * @param string $identifier
     * @return string|null The denied name that matched, for the rejection message.
     */
    public function findDeniedColumn(string $identifier): ?string
    {
        return $this->matcher->findWordRunMatch($identifier, self::DENIED_COLUMNS);
    }

    /**
     * @return list<string>
     */
    public function getDeniedColumns(): array
    {
        return self::DENIED_COLUMNS;
    }
}
