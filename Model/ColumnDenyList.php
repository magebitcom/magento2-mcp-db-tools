<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpDbTools\Model;

/**
 * Column names that may never appear in a query, however they are spelled. An independent layer
 * run on every statement: a table allowlist cannot protect `customer_entity.password_hash` or
 * `sales_order_payment.cc_*`. Matching is wide and fail-closed ({@see IdentifierMatcher::findWordRunMatch()}).
 */
class ColumnDenyList
{
    /**
     * Broad on purpose: a query naming one of these is refused, never narrowed. Every term must
     * match a real column — {@see QueryGuardTest::testEveryDeniedColumnTermMatchesItsRealColumn}.
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
        // Hijacking material, and why `session` can be exact-matched as a table: this layer catches
        // `customer_visitor.session_id` without refusing `session_cutoff`.
        'session_id',
        'session_data',
        // Family rule, not an enumeration: last four + brand + expiry joined to a cardholder name is
        // PCI data. The bare `cc` expands to all ~30 `cc_*`; the three below only sharpen the message.
        'cc',
        'cc_number_enc',
        'cc_cid_enc',
        'cc_owner',
        // Bearer credentials that do not read like secrets: `Account\Confirm` accepts `confirmation`
        // as a login, so possession of the value is possession of the account.
        'confirmation',
        'subscriber_confirm_code',
        'link_hash',
        // Serialized gateway payloads. The keys are string content no identifier scan can see:
        // `raw_details_info` alone can carry a masked PAN, expiry, BIN and AVS/CVV results.
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
