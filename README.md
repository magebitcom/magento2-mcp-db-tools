# Magento2 MCP - Database Tools

This is a sub-module for the [Magento2 MCP module](https://github.com/magebitcom/magento2-mcp-module)

----

Read-only SQL access over MCP. Ships a single tool, `db.query`, that runs one
guarded `SELECT` against this store's database and returns the rows.

This module is deliberately shipped separately from the other MCP satellites,
because it is the highest-risk tool in the family:

- **Read-only.** Only a single `SELECT` is accepted. Writes, DDL, multi-statement
  input, and statements with side effects are rejected before they reach the
  database.
- **Allowlist-only.** The tool reads the tables an admin lists in **Allowed
  Tables** and nothing else — and refuses every query, `SELECT 1` included, while
  that list is empty. A protected set (admin accounts, tokens, sessions,
  `core_config_data`, the server's own schemas) is declared in the module's
  `etc/di.xml` and stays unreadable even if it is listed as allowed.
- **Disabled by default.** Installing and enabling the module does nothing on its
  own — `magebit_mcp/db_tools/enabled` defaults to `0`. An admin must turn it on
  under Stores → Configuration → Magebit → MCP Server → **MCP Database Reader**.
- **Its own ACL.** The tool is gated by `Magebit_McpDbTools::tool_db_query`
  ("Tool: Run Read-Only SQL Query"), separate from every other MCP tool ACL, so a
  role can hold the rest of the MCP surface without holding raw SQL.

Even with all three gates, a successful read-only query can expose customer PII,
order history, and configuration values. Grant the ACL only to operators you
would trust with direct database read access.

## Install

```bash
composer require magebitcom/magento2-mcp-db-tools
bin/magento module:enable Magebit_McpDbTools
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

`Magebit_Mcp` is the only Magebit dependency. If you do not want raw SQL exposed
over MCP at all, simply do not install this module.

## Configuration

Stores → Configuration → Magebit → MCP Server → **MCP Database Reader**
(`magebit_mcp/db_tools/*`):

| Field | Default | Purpose |
|---|---|---|
| Enable Read-Only SQL Tool | `No` | Master switch. Off means `db.query` never runs. |
| Default Row Limit | `100` | Row cap when a call does not request one. |
| Maximum Row Limit | `1000` | Hard ceiling a call can never exceed. |
| Statement Timeout (seconds) | `10` | Server-side time budget per query. |
| Allowed Tables | *(empty)* | **The only tables the tool may read**, one per line; `#` lines ignored. Empty refuses every query. |
| Additional Denied Tables | *(empty)* | Extra tables to protect on top of the allowlist, one per line. |

## The `db.query` tool

| | |
|---|---|
| Tool name | `db.query` |
| ACL | `Magebit_McpDbTools::tool_db_query` |
| Write mode | read |

**Arguments**

| Argument | Type | Required | Notes |
|---|---|---|---|
| `query` | string | yes | 6–8000 characters. One read-only statement. `{{table_name}}` is replaced with the real, prefixed table name. |
| `limit` | integer | no | 1 … **Maximum Row Limit**. Used only when the statement carries no `LIMIT` of its own. |

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

`truncated` is true when the row cap was reached or when the response was trimmed to stay
under its ~1 MiB size limit. `executed_sql` is the exact statement that ran — the guard
normalises whitespace, strips comments and appends the `LIMIT`, and that normalised form is
what is executed and echoed back.

Order of operations per call: the `enabled` flag → `{{table_name}}` substitution → the query
guard → a session statement timeout and pinned `sql_mode` → execution. A rejection at any step
comes back as an operator-facing message naming what was refused and, for configuration
problems, the setting to change.

**Audit.** Every call writes one `magebit_mcp_audit_log` row holding the executed SQL (first
1000 characters) and the row count. The SQL is stored verbatim and is *not* redacted, so
literals inside a query — `WHERE email = '…'` — are retained. That is intentional for an
audit trail of raw SQL, but it means the audit table inherits the sensitivity of the queries
that are run.

## Security documentation

Full threat model, the protected set, the SQL grammar that is accepted, and
operator guidance are documented separately once the tool ships.
