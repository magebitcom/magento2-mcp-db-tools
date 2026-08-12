# Magento2 MCP — Database Tools

A sub-module for the [Magento2 MCP module](https://github.com/magebitcom/magento2-mcp-module).

Ships one tool, `db.query`, which runs a single guarded read-only `SELECT` against this store's
database and returns the rows as JSON.

---

## ⚠️ Read this before you enable it

**This module hands an MCP client bulk read access to your production database. Use it at your own
risk.**

- A read-only query is still **data extraction**. The guard bounds the *shape* of a query and the
  *tables* it may name. It cannot make the data behind those tables less sensitive.
- **Any table an administrator allowlists is readable in bulk**, up to the row cap, in any shape the
  guard accepts. Allowlist `sales_order` and the token holder can read every customer name, e-mail
  and telephone number in the store.
- Credentials, sessions and config secrets are permanently out of reach (see
  [Protected set](#1-protected-set--vetoes-everything)). **Customer personal data is not** — it lives
  in ordinary columns on the tables people most want to query. Under GDPR-style regimes, allowlisting
  those tables is a **processing decision**, not a convenience one.
- Every query this tool runs is recorded in `magebit_mcp_audit_log`, so whoever can read that table
  can see what was asked of the database. See [Audit trail](#audit-trail).
- The guard is a hand-written SQL parser. It is heavily tested, but it is not the database's own
  parser. Treat `db.query` as a privileged capability, not a safe default.

**Grant `Magebit_McpDbTools::tool_db_query` only to roles you would trust with direct database read
access**, and grant `Magebit_McpDbTools::config` — which is what lets someone turn this on and
choose the tables — with the same care.

If you do not want raw SQL exposed over MCP, **do not install this module**. Prefer the domain tools
(`Magebit_McpOrderTools`, `Magebit_McpCatalogTools`, `Magebit_McpCustomerTools`, …): same data,
narrow ACL per tool, fixed response fields, and named arguments instead of an opaque SQL string.
Reach for `db.query` only when a question cannot be answered any other way.

This tool is **not a way around them**. It requires the same admin resources the domain tools
require: every table a statement names must be mapped to the admin ACL resource that reads it (see
[The gates](#the-gates)), and the caller's role must hold all of them. Raw SQL therefore reads
exactly what its holder could already reach in the admin UI — no more — and an unmapped table is
refused rather than waved through.

---

## Contents

- [What ships, and what is off](#what-ships-and-what-is-off)
- [Install](#install)
- [The gates](#the-gates)
- [The access model](#the-access-model)
- [Choosing an allowlist](#choosing-an-allowlist)
- [What the guard refuses](#what-the-guard-refuses)
- [Caps and bounds](#caps-and-bounds)
- [Deployment caveats](#deployment-caveats)
- [Audit trail](#audit-trail)
- [Tool reference](#tool-reference)
- [Configuration reference](#configuration-reference)
- [Troubleshooting](#troubleshooting)

## What ships, and what is off

| | |
|---|---|
| Tool | `db.query` — "Run Read-Only SQL Query" |
| ACL resource | `Magebit_McpDbTools::tool_db_query` (its own, separate from every other MCP tool) |
| Config group | `magebit_mcp_db_tools/general/*` (Stores → Configuration → Magebit → **MCP Server - Database Reader**) |
| Write mode | read — so the MCP **Allow Write Tools** switch and a token's `allow_writes` flag do **not** gate it |
| Shipped state | **disabled**, with an **empty** table allowlist and an **empty** permission map |

Installing the module changes nothing on its own. `enabled` defaults to `0`, and even once switched
on the tool refuses every query — `SELECT 1` included — until an administrator lists the tables it
may read. Enabling the tool and deciding what it may see are two separate, deliberate acts.

Because this is a *read* tool, the two write gates that protect the rest of the MCP surface do not
apply. Its own `enabled` flag, its own ACL resource, and the admin resource each named table maps to
are the whole gate.

## Install

```bash
composer require magebitcom/magento2-mcp-db-tools
bin/magento module:enable Magebit_McpDbTools
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

`Magebit_Mcp` is the only Magebit dependency. Then confirm the tool registered and its ACL resolves:

```bash
bin/magento magebit:mcp:tools:list
bin/magento magebit:mcp:tools:validate-acl
```

## The gates

Four things must line up before a query runs.

1. **`magebit_mcp_db_tools/general/enabled` = 1.** While off, every call is refused with a message
   naming the config path and no database connection is opened.
2. **The `Magebit_McpDbTools::tool_db_query` ACL resource**, held by the admin role behind the
   calling token. Without it: `-32004 FORBIDDEN`. This resource appears in System → Permissions →
   User Roles under **MCP → MCP Tools**, where it can be granted to specific admin roles and scoped
   bearer tokens. It is its own separate resource, distinct from every other MCP tool.
3. **The admin ACL resource each named table takes to read**, from the **Table Permission Map**
   (`table_acl_map`). The tool reads the tables out of the statement and demands every resource they
   map to, so a role that may not open orders in the admin cannot read `sales_order` over SQL
   either — the MCP surface stays inside what the admin UI would allow. A table with **no** line in
   the map is refused outright, naming the table and the setting: the tool will not run a read it
   cannot attach a permission to, so a missing line fails closed rather than skipping the check.
   The map ships empty, which means an allowlisted table is not readable until it is mapped too.
4. **Optionally, per-token tool scoping** — the cheapest way to keep SQL access on a dedicated token
   rather than on the one an agent uses for everything else:

```bash
bin/magento magebit:mcp:token:create --admin-user=<user> --name='<label>' --scope=db.query
```

A token created with `--scope` may call only the tools it names; one created without may call
anything its admin role allows.

**Who may enable it.** The config lives in its own section, gated by its own ACL resource,
`Magebit_McpDbTools::config` — separate from `Magebit_Mcp::config`, which covers the rest of the
MCP server. Reaching general MCP configuration does **not** let someone switch raw SQL on or edit
the allowlist; that requires `Magebit_McpDbTools::config` specifically, grantable to a different,
and usually narrower, set of roles than those who may *use* the tool.

The protected set below is the one part of the model that admin access cannot weaken.

## The access model

Three layers, every one run on every query. None can short-circuit another.

### 1. Protected set — vetoes everything

Class constants on `Magebit\McpDbTools\Model\TableAccessPolicy`. These names are refused wherever
they appear, **including when an administrator lists them as allowed** — listing one is a
configuration error the tool reports rather than ignores.

The shipped set is a floor, not a default. It is in code rather than admin config (which the actor
it defends against could edit) and rather than a `di.xml` argument (which Magento merges *by item
name*, so a module re-declaring `core_config_data` would replace that entry and quietly drop it).
The same three `di.xml` arguments still exist and are unioned on top, so a module can add its own
credential tables but can never remove one of these. See [`docs/EXTENDING.md`](docs/EXTENDING.md).

Matched **widely** — exactly, behind this install's table prefix, and as a run of underscore-
delimited words inside a longer identifier, so wrappers like `custom_admin_user_map` are caught:

| Name | Holds |
|---|---|
| `admin_user`, `admin_passwords` | Admin accounts and password hashes, password history |
| `admin_user_session`, `admin_user_expiration` | Live admin sessions, account expiry |
| `oauth_token`, `oauth_consumer`, `oauth_token_request_log` | Magento web-API OAuth credentials |
| `magebit_mcp_token` | MCP bearer-token hashes |
| `magebit_mcp_oauth_client`, `magebit_mcp_oauth_auth_code`, `magebit_mcp_oauth_refresh_token` | MCP OAuth credentials |
| `magebit_mcp_oauth_authorize_handoff` | In-flight authorizations (redirect URI, state, PKCE challenge) |
| `magebit_mcp_audit_log` | This tool's own trail |
| `persistent_session` | "Remember me" session keys |
| `admin_adobe_ims_webapi` | Adobe IMS API credentials |
| `password_reset_request_event` | Password-reset throttling records |
| `tfa_user_config` | Admin two-factor secrets (TOTP shared secret) |
| `core_config_data` | Encrypted payment-gateway credentials and API keys |

Matched **exactly, or behind the table prefix**, and nothing wider — word-run matching on single
common words would refuse ordinary columns such as `customer_entity.session_cutoff`:

| Name | Holds |
|---|---|
| `session` | Database-backed sessions — everything needed to hijack one |
| `cache`, `cache_tag` | Database cache backend (config cache with decrypted values, rendered HTML) |

Matched by **strict equality only** — server-owned names that wider matching would over-deny:
`mysql` (server password hashes), `information_schema`, `performance_schema`, `sys`, `processlist`
and `innodb_trx` (other sessions' SQL text), `user_privileges`.

An administrator can **add** to the set via **Additional Denied Tables**, matched widely like the
first group. Entries there only ever remove access.

### 2. Table allowlist — empty by default

`magebit_mcp_db_tools/general/allowed_tables`. One bare table name per line; blank and `#` lines ignored.
With a table prefix, list names **without** it.

- **Empty refuses every query**, `SELECT 1` included. That is the shipped default.
- Only identifiers in **table positions** need an entry — after `FROM`, after any `JOIN`, after a
  comma continuing a table list, as a `DESCRIBE`/`EXPLAIN` target, or after `IN` in a `SHOW`. Alias,
  derived-table, column and CTE names need none.
- Matching is **exact, or exact behind the table prefix** — never the wide word-run match. An
  allowed `customer_entity` does not admit `customer_entity_backup`; an allowed `user` does not
  admit `admin_user`.
- Schema-qualified references (`mydb.cms_page`) are accepted only when the qualifier is this store's
  own database name from `env.php`. If that cannot be read, every qualified reference is refused.
- A malformed entry — dotted, quoted, spaced, wildcarded, non-ASCII — refuses **every** query with a
  message naming the offending line, so a typo reads as "fix line X", not "the tool is broken".

An allowlist rather than a denylist, because a denylist defaults to *readable* for every table a
future module adds.

### 3. Column deny-list — runs on every statement

Independent of table resolution: no table allowlist can protect `customer_entity.password_hash` or
`sales_order_payment.cc_*`, which sit on tables a useful allowlist contains. A query naming any of
these anywhere — select list, `WHERE`, `JOIN`, alias — is refused:

| Term | Catches |
|---|---|
| `password_hash`, `password` | Customer and admin password material |
| `rp_token`, `rp_token_created_at` | Password-reset tokens |
| `token`, `secret`, `api_key`, `authentication_string` | Credential columns generally, third-party ones included |
| `session_id`, `session_data` | Session hijacking material on any table |
| `cc` | **Every `cc_*` column** on `sales_order_payment` / `quote_payment` — ~30, not just the encrypted ones. Last four + brand + expiry joined to a name from `sales_order` is PCI cardholder data. |
| `cc_number_enc`, `cc_cid_enc`, `cc_owner` | Subsets of the above, kept so the refusal names the most sensitive columns precisely |
| `confirmation`, `subscriber_confirm_code` | Account-activation keys. `customer_entity.confirmation` is what `/customer/account/confirm` accepts — possession of it *is* a login. |
| `link_hash` | The capability token in a shareable download URL |
| `additional_information` | Serialized gateway payloads; `raw_details_info` alone can carry a masked PAN, expiry, BIN and AVS/CVV results |
| `x_forwarded_for` | Customer IP addresses on `sales_order` |

Matching is wide on purpose, so there is known fail-closed collateral damage: `admin_user_id` (you
cannot join on the audit log's FK), a project *table* whose name contains a denied word run
(`token_registry`, `secret_ledger`), `session_id` on any table, and `confirmation` — so "how many
customers have not confirmed yet?" is unanswerable here. A bearer credential is worth more than that
query.

## Choosing an allowlist

A starting point to paste and **trim**. It is not a claim that exposing these is safe — review every
line against your own data-protection obligations and delete what you do not actively need:

```
catalog_product_entity
catalog_product_entity_varchar
catalog_product_entity_int
catalog_product_entity_decimal
catalog_category_entity
catalog_category_product
cataloginventory_stock_item
eav_attribute
eav_entity_type
store
store_website
store_group
cms_page
cms_block
sales_order
sales_order_item
sales_order_status_history
```

**Be clear-eyed about what the useful tables contain.** The last three lines, and the tables
operators most often add next, are personal data:

| Table | Personal data it carries |
|---|---|
| `sales_order` | Customer name, e-mail, telephone, totals |
| `sales_order_address` | Full billing and shipping addresses |
| `sales_order_item`, `sales_order_status_history` | Purchase history, operator comments |
| `customer_entity` | E-mail, name, date of birth, tax/VAT number |
| `customer_address_entity` | Home addresses and telephone numbers |
| `quote`, `quote_address`, `quote_item` | The same for abandoned carts — often people who never ordered |
| `newsletter_subscriber` | E-mail addresses and consent state |

`sales_order_payment` / `quote_payment` are a special case: you can list them and get `method`,
`amount_*` and FK columns, but every `cc_*` column and `additional_information` are refused, so most
payment-analytics questions stay unanswerable. That is deliberate.

Start with the narrowest list that answers the questions you actually have, and add to it when a
refusal proves you need more.

## What the guard refuses

Refusals arrive as `-32011 TOOL_EXECUTION_FAILED` with a message naming what was refused and, for
configuration problems, the setting to change. An MCP client normally self-corrects in one retry.

**Statement shape**

- **One statement only.** A `;` anywhere but the very end is refused. `SELECT 1; DROP TABLE x` never
  reaches the database.
- **Leading keyword allowlist:** `SELECT`, `WITH`, `SHOW`, `DESCRIBE`, `DESC`, `EXPLAIN`. Checked as
  a whole token, so `SEL/**/ECT` is refused rather than reassembled. A leading `(` is refused.
- **Mutating and side-effecting keywords** anywhere: the DML/DDL set, `SET`, `LOCK`, `CALL`, `LOAD`,
  `PREPARE`, `FLUSH`, `KILL`, `INTO`/`OUTFILE`/`DUMPFILE`, `LOAD_FILE`, `SLEEP`, `BENCHMARK`, the
  `*_LOCK` and replication-wait families, `SYS_EXEC`/`SYS_EVAL`.
- **Table-value constructors** — `TABLE t` and `VALUES ROW(…)` — anywhere, including as a `UNION`
  branch. `TABLE t` is `SELECT * FROM t` with no `*` and no column names, so it would return every
  column while giving the wildcard rule and column deny-list nothing to reject.
- **Sequence forms** — `NEXT VALUE FOR s` (a write) and `PREVIOUS VALUE FOR s`. Only the three-word
  phrase is matched; the bare `value` is a column on every EAV value table.
- **Session and system variables** (`@name`, `@@name`, `@x := …`) — a filter-evasion and
  state-mutation primitive. `@` inside a literal is fine, so `email LIKE '%@example.com'` works.
- **Parenless server-info keywords** — `CURRENT_USER`, `CURRENT_ROLE`, `SESSION_USER`,
  `SYSTEM_USER` — refused with or without a following `(`, since they report the database account
  the connection runs as.
- **Executable comments** (`/*! … */`, `/*+ … */`, and MariaDB's `/*M! … */`): the server executes
  them while a naive scanner reads them as comments.
- **Control characters**, unterminated comments, string literals and quoted identifiers.

**Columns, literals, functions**

- **No `*` anywhere except `COUNT(*)`.** A single auditable rule instead of a grammar, with a real
  cost: it also refuses **arithmetic multiplication**, so `SUM(qty * price)` will not run — return
  the columns and multiply client-side.
- **Duplicate column names collapse** in the response; alias them.
- **Single quotes only.** `"…"` is refused because its meaning depends on `sql_mode`.
- **No backslashes inside a literal.** The validated string is executed verbatim, so its literal
  boundaries must match the server's under *every* configuration. Double a quote (`'O''Brien'`) and
  use `ESCAPE` for a literal `_`/`%` (`LIKE 'a!_b' ESCAPE '!'`).
- **Quoting is reproduced byte-for-byte.** A doubled `` ` `` inside a backtick-quoted identifier
  (`` `we``ird` ``) and a doubled `'` inside a literal stay doubled in the executed statement, so a
  quoted name always runs as the single identifier it was validated as.
- **A schema-qualified call is always refused.** `magento.count(1)` looks allowlisted if you read
  only the word touching the `(`, but a built-in is never schema-qualified.
- **Built-in read-only functions only** — roughly 200: aggregates and window, string/hash, numeric,
  date-time, cast, control-flow, JSON. This is the only rule that can stop a **stored function or
  UDF**, whose name the operator picks. Keyword argument forms work: `EXTRACT(YEAR FROM col)`,
  `TRIM(LEADING '0' FROM col)`, `SUBSTRING(col FROM 2 FOR 3)`, `POSITION('x' IN col)`.
- **`GROUP_CONCAT`, `JSON_ARRAYAGG`, `JSON_OBJECTAGG` are refused** though read-only: they collapse
  a column into one value bounded only by `max_allowed_packet`, defeating the row cap.
- The allowlist is deliberately conservative, so a legitimate built-in is occasionally missing.
  Extend `ALLOWED_FUNCTIONS` in `Model/QueryGuard.php` — and **never** `STRUCTURAL_KEYWORDS`
  (`Model/Sql/TableReferenceScanner.php`, read by this layer and the table scan alike), whose
  safety rests on every entry being reserved in *every* supported server, MariaDB included. Cost of
  that rule: fulltext `MATCH … AGAINST (…)` and `= ANY (subquery)` are unsupported; `IN (subquery)`
  covers the realistic case.
- Side effect: `WITH cte (a, b) AS (…)` reads as a function call. Use `WITH cte AS (SELECT … AS a)`.

**`SHOW` forms**

Accepted: `TABLES`, `COLUMNS`, `FIELDS`, `INDEX`, `INDEXES`, `KEYS`, `DATABASES`, `SCHEMAS`,
`ENGINES`, `CHARACTER`, `CHARSET`, `COLLATION`, `VARIABLES`, `STATUS`, `WARNINGS`, `ERRORS`. Forms
taking a table target go through the allowlist. Everything else is refused, including `TRIGGERS` and
`EVENTS` (they embed statement bodies), `TABLE STATUS`, `OPEN TABLES`, `FUNCTION`/`PROCEDURE STATUS`,
`ENGINE INNODB STATUS`, `PLUGINS`, `PRIVILEGES`, `PROFILES` and the replication-status forms.

Two honest caveats: `SHOW TABLES` / `SHOW DATABASES` still **enumerate names outside the allowlist**
— the one place the allowlist is not the whole story — and `SHOW VARIABLES` / `SHOW STATUS` expose
server paths and tuning. Neither returns table data.

**`{{table_name}}` placeholders**

Write `{{sales_order}}` and the real prefixed name is substituted before validation, so a client
never guesses the prefix. The substitution is **textual and unconditional** — it rewrites `{{…}}`
inside string literals too. Any brace that is not part of a resolved placeholder is a hard refusal.

**Row limit**

- A `SELECT`/`WITH` with no `LIMIT` gets one appended: the call's `limit` argument, else **Default
  Row Limit**, clamped to **Maximum Row Limit**.
- A `LIMIT` above the ceiling is **refused with the ceiling in the message, never silently clamped**
  — clamping would let a client believe it had seen every matching row.
- `LIMIT` may appear **once**, as the final clause. `LIMIT n`, `LIMIT offset, n` and
  `LIMIT n OFFSET offset` are accepted; one only inside a subquery, or non-literal (`LIMIT 1+1`), is
  refused.
- `SHOW`/`DESCRIBE`/`EXPLAIN` get no `LIMIT` — the byte cap is their only bound.

## Caps and bounds

| Bound | Value | What it bounds |
|---|---|---|
| Query length | max 8000 characters (a 6-character minimum is advertised in the input schema) | The work the validator can be made to do |
| Row cap | **Default Row Limit**, ceiling **Maximum Row Limit** | Rows returned |
| Response payload | ~1 MiB of serialised JSON | Bytes returned, and PHP memory during the read |
| Statement timeout | **Statement Timeout (seconds)** | Server-side work per query |

**The row cap does not bound bytes.** One row of concatenated `TEXT` columns can be megabytes, which
is what the payload cap is for. Rows are read one at a time with result buffering off and the read
stops the moment the budget would be exceeded, so an oversized result set is trimmed rather than
materialised. If the connection cannot be switched to unbuffered reads the tool still runs, but the
whole result set is held in memory; that is logged as a warning in `var/log/magebit_mcp.log`. If the
*first* row alone exceeds the budget the call fails explicitly rather than returning zero rows.

**The statement timeout is the only bound on query *work*.** The guard bounds shape and tables, not
cost — an unindexed join or a wide hashing scan over an allowlisted table is limited by the timeout
and nothing else. It is applied per call with `SET SESSION max_execution_time` (MySQL 5.7.8+) or
`max_statement_time` (MariaDB 10.1+), whichever the server accepts. **If neither can be set the
query is refused rather than run untimed**, with a `critical` log line. There is no override.

`sql_mode` is also pinned per call by *appending* `NO_BACKSLASH_ESCAPES` to the session's existing
modes (never assigning a set, which would drop `STRICT_TRANS_TABLES` for the rest of the request).
This is belt-and-braces behind the backslash refusal; if it fails the call continues with a warning.

## Deployment caveats

Read these before enabling the tool on a shared or proxied database connection.

- **Persistent connections.** The session timeout and the appended `sql_mode` are read back before
  they are applied and written back on every exit path, the failure paths included, so a connection
  that outlives the request (`db/connection/default/persistent` in `env.php`) is handed on with the
  values it arrived with. Two windows remain: anything else running on the same connection *during*
  the call sees the tool's timeout and `sql_mode`, and the reconnect-retry below restores nothing
  because it bypasses both guards to begin with. If the server will not report its own session state
  the tool leaves the guards in place and logs a warning rather than guessing a value to write back.
- **Magento's reconnect-retry bypasses both session guards.** On MySQL errors 2006/2013 Magento
  re-runs the statement on a fresh connection carrying neither the timeout nor the `sql_mode` pin,
  and that attempt runs **buffered**, so the memory bound is lost for it. The shared connection is
  never left unbuffered.
- **A connection proxy that swallows `SET SESSION` breaks the tool, by design.** ProxySQL,
  MaxScale-style multiplexers and some managed-database front ends drop session `SET` statements. If
  every query is refused with a message about `max_execution_time` / `max_statement_time`, that is
  why. There is no escape hatch — give the tool a connection where session variables can be set, or
  leave it disabled.
- **`SELECT VERSION()` runs outside the work bound.** The server-flavour probe (once per process,
  memoised) is issued before the timeout, because the timeout's variable name depends on its answer.
  Constant-time, no user input.

## Audit trail

Every call writes one row to `magebit_mcp_audit_log`. For this tool `result_summary_json` carries a
summary of the executed SQL, truncated to 1000 characters, plus the row count. Two consequences to
plan for:

1. **The audit table inherits the sensitivity of the queries run.** Whoever holds
   `Magebit_Mcp::mcp_audit` can read what was asked of the database. Include it in your retention
   policy; `magebit_mcp/general/retention_days` governs the purge cron.
2. **The tool cannot read its own trail.** `magebit_mcp_audit_log` is protected, so allowlisting it
   is a configuration error. Use the admin audit-log grid.

`executed_sql` in the response is the same normalised string that ran — comments stripped,
whitespace collapsed, `LIMIT` appended. What was validated is what executed.

**Left readable on purpose.** `magebit_mcp_prompt` (operator-authored workflow text, legitimate to
audit) and `queue_message` (a real debugging target) are *not* protected. Be careful with the
latter: `queue_message.body` is a serialised consumer payload, and some consumers embed customer
data or credentials. Check what your consumers put there before allowlisting it, and use
**Additional Denied Tables** to block it again if you would rather not think about it.

## Tool reference

**Arguments**

| Argument | Type | Required | Notes |
|---|---|---|---|
| `query` | string | yes | Up to 8000 characters, one read-only statement. `{{table_name}}` is substituted with the real prefixed name. |
| `limit` | integer | no | 1 … **Maximum Row Limit**. Used **only** when the statement carries no `LIMIT`; the statement's own always wins. |

`limit`'s maximum is baked into the schema at `tools/list` time, so lowering **Maximum Row Limit**
after a client cached the tool list may have it offer a maximum the guard now refuses. The refusal
names the real ceiling and self-corrects in one call.

**Response**

```json
{
  "columns": ["entity_id", "status"],
  "rows": [{"entity_id": "1", "status": "complete"}],
  "row_count": 1,
  "limit_applied": 100,
  "truncated": false,
  "executed_sql": "SELECT entity_id, status FROM sales_order LIMIT 100"
}
```

- `columns` — keys of the first row; `[]` on an empty result set. Use `DESCRIBE` if you need names.
- `limit_applied` — the cap in force; `null` for `SHOW`/`DESCRIBE`/`EXPLAIN`.
- `truncated` — the row cap was reached or the payload was trimmed. Narrow the query rather than
  paging blindly.

**Order of operations per call:** `enabled` gate (no connection if off) → `{{table_name}}`
substitution → query guard → session timeout and `sql_mode` pin → streamed execution → payload
shaping → audit row.

Errors about the statement itself — unknown column, missing table, interrupted by the time limit —
are surfaced with the driver's message, since those are actionable. Connection-level failures are
**not** echoed (they name the server and its credentials); the caller gets a generic message and the
real one goes to `var/log/magebit_mcp.log`.

## Configuration reference

Stores → Configuration → Magebit → **MCP Server - Database Reader** (`magebit_mcp_db_tools/general/*`),
gated by its own ACL resource, `Magebit_McpDbTools::config`.

| Field | Path | Default | Purpose |
|---|---|---|---|
| Enable Read-Only SQL Tool | `enabled` | `No` | Master switch. Off means `db.query` never runs and no connection is opened. |
| Default Row Limit | `default_rows` | `100` | Row cap when a call requests none. Clamped to the ceiling. Accepts `1`–`100000`. |
| Maximum Row Limit | `row_limit_ceiling` | `1000` | Hard ceiling — the bulk-extraction brake. Raising it raises how much one call can pull out. Accepts `1`–`100000`. |
| Statement Timeout (seconds) | `statement_timeout_seconds` | `10` | Server-side time budget per query, and the only bound on query work. Accepts `1`–`3600`. |
| Allowed Tables | `allowed_tables` | *(empty)* | The only tables the tool may read, one bare name per line. Empty refuses every query. Entries are validated on save: a malformed or permanently protected name is refused in the admin rather than refusing every query later. |
| Additional Denied Tables | `extra_denied_tables` | *(empty)* | Names protected on top of the allowlist, matched widely. Can only remove access. Validated for shape on save; naming a protected table here is allowed. |
| Table Permission Map | `table_acl_map` | *(empty)* | The admin ACL resource each table takes to read, one `table:Vendor_Module::resource` per line. Every named table must have one — a query naming an unmapped table is refused, so the map is a second list to fill in alongside the allowlist. A line whose resource is not a `Vendor_Module::resource` id is ignored, and therefore refuses too. |

Blank or non-positive numeric values fall back to the defaults, so a cleared field can never widen a
cap.

## Troubleshooting

| Symptom | Cause |
|---|---|
| Every query refused, message names `allowed_tables` | The allowlist is empty (the default) or one line is malformed. Fix the named line. |
| "…is permanently protected and can never be read" | The allowlist names something in the protected set. Remove that line. |
| Every query refused, message names `max_execution_time` / `max_statement_time` | The connection cannot accept `SET SESSION` — usually a DB proxy. See [Deployment caveats](#deployment-caveats). |
| `-32004 FORBIDDEN` | The token's admin role lacks `Magebit_McpDbTools::tool_db_query`, one of the resources the named tables map to, or the token was scoped without `db.query`. |
| "These tables have no admin ACL mapping" | A named table has no line in `table_acl_map`. Add `table:Vendor_Module::resource` for it, or drop it from the allowlist. |
| A refusal naming a table the query never mentioned | Wide protected/column matching fired on an identifier containing a protected word run — `admin_user_id`, `session_id`, a table called `token_registry`. Fail-closed by design. |
| `SUM(qty * price)` refused | The no-`*` rule. Return `qty` and `price` and multiply client-side. |
| A column exists but is refused | The column deny-list. Select only what you need. |

`var/log/magebit_mcp.log` carries anything the tool could not do quietly: a lost `sql_mode` pin, a
failed buffering switch, a dropped cursor, a withheld driver message, and the `critical` line when no
statement timeout could be applied.

## Extending

The protected set is designed to be extended by other modules — see
[`docs/EXTENDING.md`](docs/EXTENDING.md).

---

![Magebit](https://github.com/user-attachments/assets/cdc904ce-e839-40a0-a86f-792f7ab7961f)

Magebit - Full-service e-commerce agency

[magebit.com](https://magebit.com)
