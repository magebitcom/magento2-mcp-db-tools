# Extending Magebit_McpDbTools

There is one extension seam in this module, and it is a security seam: the **protected table set**.
Everything else — the accepted grammar, the function allowlist, the caps — is fixed in code on
purpose.

## Protecting your own tables

If your project or a third-party module stores credentials, tokens, personal data or anything else
that must never be readable through `db.query`, add it to the protected set from your own module's
`etc/di.xml`. Magento merges array arguments across modules, so no patch to this module is needed.

These arguments are **additive only**. The set this module ships lives in class constants on
`TableAccessPolicy` and is unioned with whatever DI supplies, so anything you declare here extends
the protected set and nothing you declare here can shrink it.

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magebit\McpDbTools\Model\TableAccessPolicy">
        <arguments>
            <argument name="protectedTables" xsi:type="array">
                <item name="acme_payment_credential" xsi:type="string">acme_payment_credential</item>
                <item name="acme_partner_api" xsi:type="string">acme_partner_api</item>
            </argument>
        </arguments>
    </type>
</config>
```

Run `bin/magento setup:di:compile` and `bin/magento cache:flush` afterwards. Verify with a query
naming the table: it must come back refused as *protected*, not as *not in the Allowed Tables list*.

### Which of the three arguments to use

| Argument | Matching | Use it for |
|---|---|---|
| `protectedTables` | Exact, `<prefix><name>`, **and** as a contiguous run of underscore-delimited words inside a longer identifier | Multi-word table names. The wide match also catches wrapper tables (`acme_partner_api_archive`) and is the right default. |
| `protectedPrefixedIdentifiers` | Exact, or exact behind this install's table prefix | Table names that are single ordinary words (`session`, `cache`). Word-run matching on those would refuse ordinary columns and report a table the query never named. |
| `protectedIdentifiers` | Strict equality only | Server-owned names and schemas (`mysql`, `sys`). Nothing wider, because a single common word would over-deny. |

If you are unsure, use `protectedTables`: over-denial is a usability cost, under-denial is a data
leak.

A protected name wins over everything. It is refused wherever it appears in a statement, and listing
it in **Allowed Tables** is reported as a configuration error rather than being silently ignored.

### You cannot soften the shipped set

Magento keys DI array items by name, so declaring an item with a key this module already ships
would normally **override** it. That is exactly why the shipped set is not declared in `di.xml`: it
is a class constant that is unioned in on every read path, so re-declaring a shipped name is a
no-op rather than a way to unprotect a table. If a shipped entry is genuinely wrong for everyone,
open an issue upstream.

## Removing access without touching code

To block a table for this install only, use **Additional Denied Tables**
(`magebit_mcp_db_tools/general/extra_denied_tables`) in the admin. Entries there are merged into the
wide-matched protected terms and can only ever *remove* access. It is rarely needed now that access
is allowlist-only — its use is belt and braces for a name you want blocked even if someone later
adds it to **Allowed Tables**.

## Not extension seams

- **The function allowlist** (`QueryGuard::ALLOWED_FUNCTIONS`) is a private constant, not a DI
  argument, and the layer that consults it is private too — there is no override point. Adding a
  missing built-in means editing this module or sending a patch upstream. That is deliberate: the
  list is a security control, and a per-project override would be the obvious place to smuggle a
  stored-function call through. If you edit it, never extend `STRUCTURAL_KEYWORDS` instead — see the
  invariant documented on that constant and in the README.
- **The column deny-list** (`ColumnDenyList::DENIED_COLUMNS`) is likewise a private constant. If a
  broad term such as `token` is refusing something you legitimately need, the fix is a narrower
  entry in this module, discussed upstream — not a local widening.
- **The accepted grammar.** Adding a statement form, a `SHOW` sub-form or a new `LIMIT` shape means
  changing `QueryGuard`, whose whole design is that the decision path is auditable in one read.
  Every layer there is pinned by tests that assert *which* layer refused a statement, so a change
  that swaps one verdict for another fails the suite rather than drifting.

  Two parsing machines sit behind it as collaborators, and neither is a seam either:
  `Model\Sql\Lexer` turns the statement into tokens and the normalised SQL rebuilt from them, and
  `Model\Sql\TableReferenceScanner` reads which of those tokens sit in a table position. Both are
  concrete classes constructor-injected into `QueryGuard`, so they are technically preferenceable —
  but every refusal decision stays in `QueryGuard`. The scanner *reports* table names and applies
  no policy; substituting it would silently change what the allowlist is applied to.

## Adding a different database tool

`db.query` is registered into `Magebit\Mcp\Model\Tool\ToolRegistry` in this module's `etc/di.xml`
like any other MCP tool. A new tool belongs in its own module, implementing
`Magebit\Mcp\Api\ToolInterface` with its own ACL resource — see `docs/EXTENDING.md` in
`Magebit_Mcp`. If it accepts operator-supplied SQL, three pieces are required, not one:

1. Compose `Magebit\McpDbTools\Model\QueryGuard` and execute the string it returns **verbatim**. The
   guarantee that what was validated is what runs is the property the whole design rests on — do not
   re-quote, re-wrap or reformat it.
2. Implement `Magebit\Mcp\Api\ContextualAclAwareInterface`, returning
   `Magebit\McpDbTools\Model\TableAclMap::resolve()` over the tables the statement names. `QueryGuard`
   enforces the table allowlist but carries no admin-ACL opinion, so a tool that stops at step 1
   silently drops the family invariant that MCP cannot read what the admin UI would deny.
3. Refuse, in `execute()`, every table `TableAclMap::unmapped()` reports. The contextual contract
   treats an empty list as "no opinion" and skips the gate, so without this an unmapped table is
   readable with no admin resource demanded at all.
