# Greyhound Antigravity Customizations

This directory contains workspace-level customizations for Google Antigravity:

## Structure

```text
.agents/
├── plugins/
│   └── greyhound-kit/
│       ├── plugin.json         # Plugin manifest
│       └── mcp_config.json     # MCP server definition (MariaDB on port 3307)
├── skills/
│   ├── ci4-spark/              # Spark commands, migrations, seeds, Greyhound tasks
│   ├── ci4-database/           # MySQL inspection and MCP database tools
│   ├── ci4-testing/            # PHPUnit test workflows
│   └── expo-mobile/            # Mobile app dev server, proxy, and Metro commands
└── rules/                      # Optional directory-scoped rules
```

## How to Check in Antigravity UI
- In the left-hand navigation sidebar or **Additional Options (...) > MCP Servers**, you will see active MCP servers.
- Skills are automatically detected and progressively loaded when relevant tasks are requested.
