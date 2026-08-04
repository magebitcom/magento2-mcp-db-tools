# Magento2 MCP — Database Tools

A sub-module for the [Magento2 MCP module](https://github.com/magebitcom/magento2-mcp-module).

Read-only SQL access over MCP. Ships one tool, `db.query`, which runs a single guarded `SELECT`
against this store's database and returns the rows as JSON.

This module is published separately from the other MCP satellites for one reason: it is the
highest-risk tool in the family. Everything below is written for the person deciding whether to
turn it on, and what to let it see.

**Read this before enabling it.** A read-only query is still a data-extraction capability. The
guards in this module bound the *shape* of a query and the *tables* it may name; they cannot make
the data behind those tables less sensitive.

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
- [The audit trail](#the-audit-trail)
- [Left readable on purpose](#left-readable-on-purpose)
- [What this tool is not](#what-this-tool-is-not)
- [Tool reference](#tool-reference)
- [Configuration reference](#configuration-reference)
- [Troubleshooting](#troubleshooting)

---

## What ships, and what is off

| | |
|---|---|
| Tool | `db.query` — "Run Read-Only SQL Query" |
| ACL resource | `Magebit_McpDbTools::tool_db_query` |
| Config group | `magebit_mcp/db_tools/*` (Stores → Configuration → Magebit → MCP Server → **MCP Database Reader**) |
| Write mode | read — so the MCP **Allow Write Tools** switch and a token's `allow_writes` flag do **not** gate it |
| Shipped state | **disabled**, with an **empty** table allowlist |

Installing and enabling the module changes nothing on its own. `magebit_mcp/db_tools/enabled`
defaults to `0`, and even after it is switched on the tool refuses every query — including
`SELECT 1` — until an administrator lists the tables it may read. Enabling the tool and deciding
what it may see are two separate, deliberate acts.

Because the tool is a *read* tool, the two write gates that protect the rest of the MCP surface do
not apply to it. Its own `enabled` flag and its own ACL resource are the whole gate.

## Install

```bash
composer require magebitcom/magento2-mcp-db-tools
bin/magento module:enable Magebit_McpDbTools
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

`Magebit_Mcp` is the only Magebit dependency. If you do not want raw SQL exposed over MCP at all,
do not install this module — there is nothing to configure away.

After installing, confirm the tool is registered and its ACL resolves:

```bash
bin/magento magebit:mcp:tools:list
bin/magento magebit:mcp:tools:validate-acl
```

## The gates

Three things must line up before a query runs. All three are separate on purpose.

**1. `magebit_mcp/db_tools/enabled` = 1.** Stores → Configuration → Magebit → MCP Server → MCP
Database Reader → **Enable Read-Only SQL Tool**. While this is off the tool refuses every call with
a message naming the config path, and no database connection is opened.

**2. The `Magebit_McpDbTools::tool_db_query` ACL resource**, held by the admin role behind the
calling token. It sits under System → **MCP Database Reader** in the role-resource tree, separate
from every other MCP tool ACL, so a role can hold the rest of the MCP surface without holding raw
SQL. A token whose role lacks it gets `-32004 FORBIDDEN`.

**3. Optionally, per-token tool scoping.** A token can be restricted to a named set of tools:

```bash
bin/magento magebit:mcp:token:create --admin-user=<user> --name='<label>' --scope=db.query
```

A token created with `--scope` may call only the tools it names; a token created without one may
call anything its admin role allows. This is the cheapest way to keep SQL access on a dedicated
token instead of on the token an agent uses for everything else.

### Who may *enable* it

The **MCP Database Reader** configuration group lives in the `magebit_mcp` section, which is gated
by the `Magebit_Mcp::config` ACL resource. Anyone who can reach MCP configuration can therefore
switch raw SQL on and edit the table allowlist. Treat `Magebit_Mcp::config` as the "may enable raw
database reads" permission and grant it accordingly — it is a different, and generally narrower,
set of people than those who may *use* the tool.

The protected set described below is the one part of the model that admin access cannot weaken.

## The access model

Three layers, evaluated in this order. Every layer runs on every query; none of them can
short-circuit an earlier one.

### 1. Protected set — vetoes everything

Declared in this module's `etc/di.xml` as constructor arrays on
`Magebit\McpDbTools\Model\TableAccessPolicy`. These names are refused wherever they appear in a
statement, **including when an administrator lists them as allowed** — listing one is itself a
configuration error the tool reports rather than silently ignores.

Why `di.xml` and not a config field or a class constant:

- **Not admin config.** A protected set that the admin panel can edit is weakenable by exactly the
  actor it defends against — an admin-panel user, or anyone holding a stolen admin session. Nothing
  reachable over MCP or through the admin UI can touch this list.
- **Not a class constant.** Weakening the set requires filesystem write access *plus* a
  `setup:di:compile` run, which puts it out of reach of both of the above; but a constant would also
  block the legitimate case — a project or satellite module adding *its own* credential tables
  through a `di.xml` merge, with no patch to this module. See `docs/EXTENDING.md`.

Matched **widely** — exactly, behind this install's table prefix (`m2_admin_user`), and as a
contiguous run of underscore-delimited words inside a longer identifier, so wrapper tables such as
`custom_admin_user_map` are caught too:

| Name | Holds |
|---|---|
| `admin_user` | Admin accounts and password hashes |
| `admin_passwords` | Admin password history |
| `admin_user_session` | Live admin sessions |
| `admin_user_expiration` | Admin account expiry |
| `oauth_token`, `oauth_consumer`, `oauth_token_request_log` | Magento web-API OAuth credentials |
| `magebit_mcp_token` | MCP bearer-token hashes |
| `magebit_mcp_oauth_client`, `magebit_mcp_oauth_auth_code`, `magebit_mcp_oauth_refresh_token` | MCP OAuth credentials |
| `magebit_mcp_oauth_authorize_handoff` | In-flight authorizations (client id, redirect URI, state, PKCE challenge) |
| `magebit_mcp_audit_log` | This tool's own trail — see [The audit trail](#the-audit-trail) |
| `persistent_session` | "Remember me" session keys |
| `admin_adobe_ims_webapi` | Adobe IMS API credentials |
| `password_reset_request_event` | Password-reset throttling records |
| `core_config_data` | Encrypted payment-gateway credentials and API keys |

Matched **exactly, or behind the table prefix** (`session`, `m2_session`) and nothing wider,
because word-run matching on single common words would refuse ordinary columns such as
`customer_entity.session_cutoff`:

| Name | Holds |
|---|---|
| `session` | Database-backed sessions (`session/save = db`) — everything needed to hijack one |
| `cache` | Database cache backend — the config cache (decrypted values included) and rendered HTML |
| `cache_tag` | Cache tag index |

Matched by **strict equality only** — server-owned names that wider matching would over-deny:

| Name | Why |
|---|---|
| `mysql` | `mysql.user` holds server password hashes |
| `information_schema` | Schema-wide introspection, and the views below |
| `performance_schema`, `sys` | Server internals |
| `processlist`, `innodb_trx` | Other sessions' SQL text, literals included |
| `user_privileges` | Server grant tables |

An administrator can **add** to the protected set through **Additional Denied Tables**
(`magebit_mcp/db_tools/extra_denied_tables`), which is matched widely like the first group. Entries
there only ever remove access; they can never grant it.

### 2. Table allowlist — empty by default

`magebit_mcp/db_tools/allowed_tables`. One bare table name per line; blank lines and `#` lines are
ignored. If your install uses a table prefix, list names **without** it.

- **Empty means every query is refused**, `SELECT 1` included. That is the shipped default.
- Only identifiers in **table positions** need an entry — after `FROM`, after any `JOIN`, after a
  comma continuing a table list, as a `DESCRIBE`/`EXPLAIN` target, or after `IN` in a `SHOW`
  statement. Table aliases, derived-table aliases, column aliases and CTE names need no entry.
- Matching is **exact, or exact behind this install's table prefix** — never the wide word-run
  match. An allowed `customer_entity` does not admit `customer_entity_backup`; an allowed `user`
  does not admit `admin_user`.
- Schema-qualified references (`mydb.cms_page`) are accepted only when the qualifier is this
  store's own database name from `env.php`. If that name cannot be read, every qualified reference
  is refused.
- A malformed entry — dotted, quoted, spaced, wildcarded, non-ASCII — refuses **every** query with
  a message naming the offending line, rather than being silently dropped. A typo therefore reads as
  "fix line X", not as "the tool is broken".

An allowlist is used rather than a denylist because a denylist defaults to *readable* for every
table a future module adds, and rots as upstream renames things.

### 3. Column deny-list — runs on every statement

Independent of table resolution, because no table allowlist can protect
`customer_entity.password_hash` or `sales_order_payment.cc_*` — those sit on tables a useful
allowlist contains. A query naming any of these anywhere (select list, `WHERE`, `JOIN`, alias) is
refused:

`password_hash`, `password`, `rp_token`, `rp_token_created_at`, `x_forwarded_for`, `token`,
`secret`, `api_key`, `authentication_string`, `session_id`, `session_data`, `cc_number_enc`,
`cc_cid_enc`, `cc_owner`, `cc_last4`

Matching is wide and deliberately so: `token` also refuses `access_token`, `token_hash` and
`customer_api_key`. Known collateral damage, all fail-closed:

- Any column carrying a protected table's word run — `admin_user_id`, so you cannot join on the
  audit log's FK column.
- Any *table* whose name contains a denied column word — a project table called `token_registry` or
  `secret_ledger` is unreachable.
- `session_id` / `session_data` on any table, so `customer_visitor.session_id` cannot be read.

## Choosing an allowlist

A starting point to paste and trim. It is **not** a recommendation that exposing these tables is
safe — review every line against your own data-protection obligations and delete anything you do
not actively need:

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

**Be clear-eyed about what the useful tables contain.** The last three lines above, and the tables
operators most often want to add next, are personal data:

| Table | Personal data it carries |
|---|---|
| `sales_order` | Customer name, e-mail, telephone, totals |
| `sales_order_address` | Full billing and shipping addresses |
| `sales_order_item`, `sales_order_status_history` | Purchase history, operator comments |
| `customer_entity` | E-mail, name, date of birth, tax/VAT number |
| `customer_address_entity` | Home addresses and telephone numbers |
| `quote`, `quote_address`, `quote_item` | The same, for abandoned carts — often for people who never completed an order |
| `newsletter_subscriber` | E-mail addresses and consent state |

Listing any of them means the token holder can read that data, in bulk, up to the row cap, on any
query shape the guard accepts. Column names carrying secrets are still refused, but a customer's
name, e-mail and address are ordinary columns and will come back. Under GDPR-style regimes this is
a processing decision, not a convenience one.

Start with the narrowest list that answers the questions you actually have, and add to it when a
refusal proves you need more.

## What the guard refuses

Refusals arrive as JSON-RPC `-32011 TOOL_EXECUTION_FAILED` with an operator-facing message naming
what was refused and, for configuration problems, the setting to change. The messages are the
tool's usability surface — an MCP client normally self-corrects from them in one retry.

**Statement shape**

- **One statement only.** A `;` anywhere but at the very end is refused; a trailing one is
  accepted. `SELECT 1; DROP TABLE x` never reaches the database.
- **Leading keyword allowlist:** `SELECT`, `WITH`, `SHOW`, `DESCRIBE`, `DESC`, `EXPLAIN`. Checked
  as a whole token, so a keyword split by a comment (`SEL/**/ECT`) is refused rather than
  reassembled. A statement beginning with `(` is refused too.
- **Mutating and side-effecting keywords** are refused anywhere in the statement: the DML/DDL set,
  `SET`, `LOCK`, `CALL`, `LOAD`, `PREPARE`, `FLUSH`, `KILL`, `INTO`/`OUTFILE`/`DUMPFILE`,
  `LOAD_FILE`, `SLEEP`, `BENCHMARK`, the `*_LOCK` family, the replication-wait family, and
  `SYS_EXEC`/`SYS_EVAL`.
- **Session and system variables** (`@name`, `@@name`, `@x := …`) are refused — a classic
  filter-evasion and state-mutation primitive. `@` inside a string literal is unaffected, so
  `email LIKE '%@example.com'` still works.
- **MySQL executable comments** (`/*! … */`, `/*+ … */`) are refused outright: the server executes
  them while a naive scanner reads them as comments.
- **Control characters** anywhere in the input are refused, as are unterminated comments, string
  literals and quoted identifiers.

**Columns and wildcards**

- **No `*`, anywhere, except `COUNT(*)`.** `SELECT *` and `t.*` are refused so protected columns
  cannot come back by accident. This is a single, auditable rule rather than a grammar, and it has
  a real cost: it also refuses **arithmetic multiplication**. `SUM(qty * price)` will not run, and
  SQL has no other multiplication operator, so compute products from the returned columns on your
  side. (`*` is not checked inside `SHOW`/`DESCRIBE`/`EXPLAIN`, which return no table rows.)
- **Duplicate column names collapse** in the response, so `SELECT a.entity_id, b.entity_id` silently
  returns one key. Alias them.

**String literals**

- **Single quotes only.** `"…"` is refused: its meaning depends on `sql_mode` (a string normally, an
  *identifier* under `ANSI_QUOTES`), and treating it as either would be wrong on some installs.
- **No backslashes inside a literal, at all.** Whether `\` escapes the next byte depends on the
  server's `NO_BACKSLASH_ESCAPES` mode and on the connection charset, and the tool hands the
  validated string back for verbatim execution — so its literal boundaries must match the server's
  under *every* configuration, not just the sampled one. Practical consequences:
  - embed a quote by doubling it: `'O''Brien'`;
  - match a literal `_` or `%` with the `ESCAPE` clause: `LIKE 'a!_b' ESCAPE '!'`.

**Functions**

- **Built-in read-only functions only** — roughly 200 of them: aggregates and window functions,
  string/hash, numeric, date-time, cast and control-flow, JSON accessors and constructors. Anything
  not on the list is refused, which is the only rule that can stop a **stored function or UDF**,
  since the operator picks its name and a name-based denylist can be renamed around. The keyword
  argument forms do work: `EXTRACT(YEAR FROM col)`, `TRIM(LEADING '0' FROM col)`,
  `SUBSTRING(col FROM 2 FOR 3)`, `POSITION('x' IN col)`.
- **`GROUP_CONCAT`, `JSON_ARRAYAGG` and `JSON_OBJECTAGG` are refused** even though they are
  read-only: they collapse a whole column into one value, bounded only by `max_allowed_packet`,
  which defeats the row cap. Use `COUNT`/`SUM`, or return rows.
- The allowlist is **deliberately conservative**, so a legitimate built-in will occasionally be
  missing. Extend `ALLOWED_FUNCTIONS` in `Model/QueryGuard.php` — and **never**
  `STRUCTURAL_KEYWORDS`. That second set's safety rests on every entry being *reserved in every
  supported server, MariaDB included*, so that it can never be the unquoted name of a stored
  function. "Reserved in MySQL 8" is the wrong test, and is how `ANY`, `AGAINST` and `LATERAL` got
  in and had to come out. Cost of their removal: fulltext `MATCH … AGAINST (…)` and
  `= ANY (subquery)` are unsupported; `IN (subquery)` covers the realistic case.
- Also refused as a side effect: `WITH cte (a, b) AS (…)` — the column-list form reads as a
  function call. Use `WITH cte AS (SELECT … AS a, … AS b)`.

**`SHOW` forms**

Accepted: `TABLES`, `COLUMNS`, `FIELDS`, `INDEX`, `INDEXES`, `KEYS`, `DATABASES`, `SCHEMAS`,
`ENGINES`, `CHARACTER`, `CHARSET`, `COLLATION`, `VARIABLES`, `STATUS`, `WARNINGS`, `ERRORS`.
The forms that take a table target go through the allowlist like any other table position.
Everything else is refused, including `TRIGGERS` and `EVENTS` (they embed statement bodies),
`TABLE STATUS` and `OPEN TABLES` (per-table row counts and names), `FUNCTION`/`PROCEDURE STATUS`,
`ENGINE INNODB STATUS`, `PLUGINS`, `PRIVILEGES`, `PROFILES` and the replication-status forms.

Two honest caveats: `SHOW TABLES` and `SHOW DATABASES` still **enumerate names** outside the
allowlist (judged metadata — a Magento schema is public knowledge — but it is the one place the
allowlist is not the whole story), and `SHOW VARIABLES` / `SHOW STATUS` expose server paths and
tuning. If either matters in your threat model, note that neither returns table data.

**`{{table_name}}` placeholders**

Write `{{sales_order}}` and the real, prefixed table name is substituted before validation, so a
client never has to guess the table prefix. Two things to know:

- The substitution is **textual and unconditional** — it rewrites `{{…}}` **inside string literals
  too**, so do not write a placeholder-shaped literal you want left alone.
- Any brace that is not part of a resolved `{{name}}` placeholder is a hard refusal, so a wiring
  mistake surfaces as a message rather than a confusing SQL error.

**Row limit**

- A `SELECT`/`WITH` with no `LIMIT` gets one appended: the call's `limit` argument if given,
  otherwise **Default Row Limit**, clamped to **Maximum Row Limit**.
- A `LIMIT` above the ceiling is **refused with the ceiling in the message, never silently
  clamped** — clamping would let a client believe it had seen every matching row.
- `LIMIT` may appear **once**, as the final clause. `LIMIT n`, `LIMIT offset, n` and
  `LIMIT n OFFSET offset` are accepted; a `LIMIT` only inside a subquery, or a non-literal one
  (`LIMIT 1+1`), is refused with an instruction to put it at the end.
- `SHOW`/`DESCRIBE`/`EXPLAIN` get no `LIMIT` appended — the byte cap below is their only bound.

## Caps and bounds

| Bound | Value | What it bounds |
|---|---|---|
| Query length | 6 – 8000 characters | The work the validator itself can be made to do |
| Row cap | **Default Row Limit**, ceiling **Maximum Row Limit** | Rows returned |
| Response payload | ~1 MiB (1 048 576 bytes) of serialised JSON | Bytes returned, and PHP memory during the read |
| Statement timeout | **Statement Timeout (seconds)** | Server-side work per query |

**The row cap does not bound bytes.** One row of concatenated `TEXT` columns can be megabytes on
its own, which is what the payload cap is for. Rows are read one at a time with result buffering
switched off, and the read stops the moment the budget would be exceeded, so a result set far
larger than the cap is trimmed rather than materialised. Measured on a ~1 GB result set: no
measurable increase in PHP peak memory. If the connection cannot be switched to unbuffered reads
the tool still runs, but the whole result set is held in memory while it is read; that case is
logged as a warning in `var/log/magebit_mcp.log`.

If the *first* row alone exceeds the budget the call fails with an explanatory message rather than
returning zero rows, which would be indistinguishable from an empty result.

**The statement timeout is the only bound on query *work*.** The guard bounds a query's shape and
the tables it may name; it does not bound cost. An unindexed join, a cross join or a wide hashing
scan over an allowlisted table is limited by the timeout and by nothing else. Two consequences:

- The timeout is applied per call with `SET SESSION max_execution_time` (MySQL 5.7.8+) or
  `SET SESSION max_statement_time` (MariaDB 10.1+), whichever the server accepts — chosen by
  flavour, so a healthy install issues exactly one such statement and logs nothing.
- **If neither can be set, the query is refused rather than run without a time limit**, with a
  `critical` line in `var/log/magebit_mcp.log`. There is deliberately no override.

`sql_mode` is also pinned per call, by *appending* `NO_BACKSLASH_ESCAPES` to the session's existing
modes (never by assigning a mode set, which would drop `STRICT_TRANS_TABLES` and friends for the
rest of the request on the shared connection). This is belt-and-braces behind the backslash refusal;
if it fails, the call continues with a warning, because the guard refuses backslashes and `"`
whatever the mode is.

## Deployment caveats

All four are real and verified. Read them before enabling the tool on a shared or proxied database
connection.

**Persistent connections.** With `db/connection/default/persistent` set in `env.php`, the
connection outlives the request — so the session statement timeout and the appended `sql_mode` are
inherited by whatever reuses that connection next. `max_execution_time` applies only to read-only
`SELECT`s and the `sql_mode` change is additive, so this is not expected to break writes, but it is
a side effect on state you did not ask to change. If that is unacceptable, do not run this tool on
a persistent connection.

**Magento's reconnect-retry bypasses both session guards.** On MySQL errors 2006 (*server has gone
away*) and 2013 (*lost connection*), Magento reconnects and re-runs the statement on a **fresh**
connection, which carries neither the statement timeout nor the `sql_mode` pin. That attempt also
runs **buffered** — `closeConnection()` does not reset the adapter's connection-flags marker, so
the reconnect skips the block that would switch buffering off — so the memory bound is lost for
that one attempt too. The shared connection is never left unbuffered. Fixing this properly means
hooking the reconnect rather than the tool, so it is documented rather than worked around.

**A connection proxy that swallows `SET SESSION` breaks the tool, by design.** ProxySQL,
MaxScale-style multiplexers and some managed-database front ends silently drop or reject session
`SET` statements. If every query comes back refused with a message about `max_execution_time` /
`max_statement_time`, and `var/log/magebit_mcp.log` carries a `critical` line about it, that is the
cause. There is no configuration escape hatch: a query with no enforceable time limit is exactly
the case the refusal exists for. Give the tool a connection on which session variables can be set,
or leave it disabled.

**`SELECT VERSION()` runs outside the work bound.** The server-flavour probe (once per PHP process,
memoised) is issued before the timeout is applied, because the timeout's variable name depends on
its answer. It is a constant-time server call with no user input, so the practical exposure is nil
— stated here because it is genuinely the one statement in the flow with no time budget.

## The audit trail

Every call writes one row to `magebit_mcp_audit_log`, like every other MCP call. For this tool the
`result_summary_json` carries the **executed SQL, verbatim**, truncated to the first 1000
characters, plus the row count.

**The SQL is not PII-redacted.** MCP's redactor works on named arguments; a SQL string is opaque to
it. So a query such as `… WHERE email = 'someone@example.com'` leaves that e-mail address in the
audit table, and a `WHERE` clause naming a person, an order or an address puts it there too.

That is intentional — an audit trail for raw SQL access is worth very little without the statement
— but it has two consequences an operator should plan for:

1. **The audit table inherits the sensitivity of the queries run.** Whoever holds
   `Magebit_Mcp::mcp_audit` can read those literals. Include it in your retention policy;
   `magebit_mcp/general/retention_days` governs the purge cron.
2. **The tool cannot read its own trail.** `magebit_mcp_audit_log` is in the protected set, so
   allowlisting it is a configuration error. One operator cannot use `db.query` to read another
   operator's queries. Use the admin audit-log grid instead.

The `executed_sql` field in the response is the same normalised string that ran: comments stripped,
whitespace collapsed, `LIMIT` appended. What was validated is what executed.

## Left readable on purpose

Two tables an operator might expect to be protected are not. Both were considered and left out
deliberately, so that the decision is yours rather than ours:

- **`magebit_mcp_prompt`** — operator-authored MCP workflow text. Not credential material, and
  reading it is a legitimate way to audit what the prompts say.
- **`queue_message`** — a legitimate debugging target, and the reason the message-queue tables were
  left alone. **Be careful with it.** `queue_message.body` is a serialised consumer payload, and
  some consumers embed customer data or, in bespoke integrations, credentials. It is not blocked;
  it is simply not recommended. If you allowlist it, look at what your consumers actually put in
  there first, and use **Additional Denied Tables** to block it again if you would rather not
  think about it.

Nothing else outside the protected set is special: **any table you allowlist is readable**, up to
the row cap, in any shape the guard accepts.

## What this tool is not

- **Not a migration or administration tool.** It cannot write, create, alter, drop, lock, or run a
  stored procedure. There is no configuration that makes it able to.
- **Not a general metadata browser.** `information_schema` is protected, so schema-wide
  introspection is unavailable. What remains is `DESCRIBE` / `SHOW COLUMNS` / `SHOW INDEX` on
  allowlisted tables (plus the bare `SHOW TABLES` / `SHOW DATABASES` name lists noted above). A
  purpose-built redacting metadata tool would be the right answer if that becomes limiting; this
  tool is not it.
- **Not a substitute for the domain tools.** `Magebit_McpOrderTools`,
  `Magebit_McpCatalogTools`, `Magebit_McpCustomerTools` and their siblings expose the same data
  through Magento's own service contracts, with a narrow ACL per tool, a fixed set of fields per
  response, and named arguments that MCP's audit redactor can actually see. Reach for `db.query`
  when a question genuinely cannot be answered any other way.
- **Not a promise about your MCP client's limits.** The ~1 MiB cap is on this tool's own payload;
  the JSON-RPC envelope and transport escaping add to what goes over the wire.

## Tool reference

**Arguments**

| Argument | Type | Required | Notes |
|---|---|---|---|
| `query` | string | yes | 6–8000 characters, one read-only statement. `{{table_name}}` is substituted with the real, prefixed name. |
| `limit` | integer | no | 1 … **Maximum Row Limit**. Used **only** when the statement carries no `LIMIT` of its own; the statement's own `LIMIT` always wins. |

The `limit` argument's maximum is baked into the schema at `tools/list` time. If you lower
**Maximum Row Limit** after a client cached the tool list, that client may offer a maximum the
guard now refuses; the refusal names the real ceiling, so it self-corrects in one call.

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

- `columns` — keys of the first row; `[]` on an empty result set (an associative fetch cannot report
  column names for zero rows — use `DESCRIBE` if you need them).
- `limit_applied` — the row cap in force; `null` for `SHOW`/`DESCRIBE`/`EXPLAIN`.
- `truncated` — the row cap was reached, or the response was trimmed to fit the byte cap. Either
  way there are probably more rows: narrow the query rather than paging blindly.
- `executed_sql` — the exact statement that ran.

**Order of operations per call**

`enabled` gate (no connection opened if off) → `{{table_name}}` substitution → query guard →
session statement timeout and `sql_mode` pin → streamed execution → payload shaping → audit row.

Errors the database raises about the statement itself — an unknown column, a missing table, a query
the server interrupted on the time limit — are surfaced with the driver's own message, because those
are the ones a caller can act on. Connection-level failures are deliberately **not** echoed; their
messages describe the server and its credentials (`Access denied for user 'magento'@'…'`), so the
caller gets a generic message and the real one goes to `var/log/magebit_mcp.log`.

## Configuration reference

Stores → Configuration → Magebit → MCP Server → **MCP Database Reader** (`magebit_mcp/db_tools/*`),
gated by `Magebit_Mcp::config`.

| Field | Path | Default | Purpose |
|---|---|---|---|
| Enable Read-Only SQL Tool | `enabled` | `No` | Master switch. Off means `db.query` never runs and no connection is opened. |
| Default Row Limit | `default_rows` | `100` | Row cap when a call requests none. Clamped down to the ceiling if it is set higher. |
| Maximum Row Limit | `row_limit_ceiling` | `1000` | Hard ceiling. The bulk-extraction brake — raising it directly raises how much one call can pull out. |
| Statement Timeout (seconds) | `statement_timeout_seconds` | `10` | Server-side time budget per query, and the only bound on query work. |
| Allowed Tables | `allowed_tables` | *(empty)* | The only tables the tool may read, one bare name per line. Empty refuses every query. |
| Additional Denied Tables | `extra_denied_tables` | *(empty)* | Names protected on top of the allowlist, matched widely. Rarely needed; can only remove access. |

Blank or non-positive numeric values fall back to the defaults above, so a cleared field can never
widen a cap.

## Troubleshooting

| Symptom | Cause |
|---|---|
| Every query refused, message names `allowed_tables` | The allowlist is empty (the shipped default) or one line in it is malformed. Fix the named line. |
| "…is permanently protected and can never be read" | The allowlist names something in the protected set. Remove that line. |
| Every query refused, message names `max_execution_time` / `max_statement_time` | The connection cannot accept `SET SESSION` — usually a DB proxy. See [Deployment caveats](#deployment-caveats). |
| `-32004 FORBIDDEN` | The token's admin role lacks `Magebit_McpDbTools::tool_db_query`, or the token was created with `--scope` values that do not include `db.query`. |
| A refusal naming a table the query never mentioned | The wide protected/column matching fired on an identifier containing a protected word run — `admin_user_id`, `session_id`, a table called `token_registry`. Fail-closed by design; rename the alias or select different columns. |
| `SUM(qty * price)` refused | The no-`*` rule. Return `qty` and `price` and multiply client-side. |
| A column exists but is refused | The column deny-list. Select only what you need. |

`var/log/magebit_mcp.log` carries anything the tool could not do quietly: a lost `sql_mode` pin, a
failed buffering switch, a dropped cursor, a withheld driver message, and the `critical` line when
no statement timeout could be applied.

---

## Extending

The protected set is designed to be extended by other modules — see
[`docs/EXTENDING.md`](docs/EXTENDING.md).

## A closing warning

`db.query` gives its holder read access to every table an administrator has allowlisted, in any
shape the guard accepts, up to the row cap. The protected set keeps credentials, sessions and
configuration secrets out of reach; nothing keeps customer names, e-mail addresses, order history
or cart contents out of reach if those tables are listed.

**Grant `Magebit_McpDbTools::tool_db_query` only to roles you would trust with direct database read
access** — and grant `Magebit_Mcp::config`, which is what lets someone turn this on in the first
place, with the same care.
