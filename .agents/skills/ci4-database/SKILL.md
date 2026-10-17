---
name: ci4-database
description: >-
  Use this skill when inspecting the MySQL/MariaDB database schema, executing SQL queries,
  verifying database state, diagnosing query performance, or utilizing the MySQL MCP server
  for the Greyhound platform.
---

# CodeIgniter 4 MySQL Database Skill

This skill explains how to interact with the Greyhound platform's MariaDB database running in XAMPP.

## Connection Parameters
- **Host**: `127.0.0.1`
- **Port**: `3307` (custom XAMPP MariaDB port for this setup)
- **User**: `root`
- **Password**: *(empty)*
- **Database**: `greyhound_integration_test`
- **Driver**: `MySQLi` / `utf8mb4`

## MCP Server Integration
The MCP server `greyhound-mysql` (or global `mysql`) provides direct LLM tools:
- **`search_objects`**: Inspect table schemas, list columns, keys, and constraints.
  ```json
  {"table": "users"}
  ```
- **`execute_sql`**: Execute SQL queries safely against `greyhound_integration_test`.
  ```json
  {"sql": "SELECT id, username, email, role FROM users LIMIT 10;"}
  ```

## Safe Execution Best Practices
1. **Prefer `SELECT` queries with `LIMIT`**: Always append `LIMIT` (e.g., `LIMIT 50`) when exploring data to avoid large dumps.
2. **Review schema first**: Use `search_objects` or `DESCRIBE <table_name>` before constructing complex joins.
3. **Use transactions for data updates**: When modifying test or production data, wrap updates in transactions where applicable.

## Direct CLI Queries via PHP
If executing outside the MCP tool:
```bash
php -r "
\$db = new mysqli('127.0.0.1', 'root', '', 'greyhound_integration_test', 3307);
\$res = \$db->query('SELECT COUNT(*) AS total FROM users');
print_r(\$res->fetch_assoc());
"
```
