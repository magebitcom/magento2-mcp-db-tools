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
| Additional Denied Tables | *(empty)* | Extra tables to block, one per line; `#` lines ignored. |

## Security documentation

Full threat model, the built-in table deny-list, the SQL grammar that is accepted,
and operator guidance are documented separately once the tool ships.
