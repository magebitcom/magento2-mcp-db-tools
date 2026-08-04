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
     *
     * Every term here must actually match a real column: a term whose spelling has drifted from
     * the schema reads as protection while protecting nothing. `Test/Unit/Model/QueryGuardTest`
     * pins each one against the column it exists to catch.
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
        // Session identifiers and payloads — hijacking material, and the reason the `session` table
        // itself can be protected by exact name instead of by the wide matcher: this layer catches
        // `customer_visitor.session_id` and `visitor_session_id` without touching `session_cutoff`.
        'session_id',
        'session_data',
        // Payment-card material. A family rule rather than an enumeration: `sales_order_payment`
        // and `quote_payment` carry 30 `cc_*` columns between them, and last four + brand + expiry
        // joined to the cardholder's name is PCI cardholder data even though none of those columns
        // looks like a secret on its own. The enumeration that used to stand here covered three of
        // the 30 and mis-spelled a fourth (`cc_last4` for the real `cc_last_4`). Word-run matching
        // expands the bare term to every `cc_*` column; the three specific terms below survive only
        // so the rejection message can name the most sensitive ones precisely.
        'cc',
        'cc_number_enc',
        'cc_cid_enc',
        'cc_owner',
        // Bearer credentials that do not read like secrets. `confirmation` is the activation key
        // `Magento\Customer\Controller\Account\Confirm` accepts before logging the customer in, so
        // possession of the value is possession of the account; the other two are the same shape.
        'confirmation',
        'subscriber_confirm_code',
        'link_hash',
        // Serialized gateway payloads (`sales_order_payment`, `quote_payment`,
        // `sales_payment_transaction`, `paypal_payment_transaction`). The keys inside are string
        // content, so no identifier scan can ever see them: `raw_details_info` alone can carry a
        // masked PAN, expiry, BIN and AVS/CVV results.
        'additional_information',
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
