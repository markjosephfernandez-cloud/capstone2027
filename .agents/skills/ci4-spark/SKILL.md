---
name: ci4-spark
description: >-
  Use this skill when managing CodeIgniter 4 CLI operations, running database
  migrations, executing database seeders, creating models/controllers/entities,
  inspecting routes, clearing caches/logs, or running Greyhound-specific Spark tasks.
---

# CodeIgniter 4 Spark Skill

This skill provides step-by-step procedures for running CodeIgniter 4 CLI commands in the Greyhound platform.

## Working Directory
Always execute spark commands from the repository root:
`/Applications/XAMPP/xamppfiles/htdocs/greyhound-admin-2025-final_greyhound_admin_2025`

## Common Spark Commands

### 1. Migrations & Database Schema
- **Check Migration Status**:
  ```bash
  php spark migrate:status
  ```
- **Run Pending Migrations**:
  ```bash
  php spark migrate
  ```
- **Rollback Last Batch**:
  ```bash
  php spark migrate:rollback
  ```
- **Refresh Database (Rollback + Migrate)**:
  ```bash
  php spark migrate:refresh
  ```

### 2. Database Seeders
- **Run a specific seeder**:
  ```bash
  php spark db:seed <SeederName>
  ```
- Example:
  ```bash
  php spark db:seed UserSeeder
  ```

### 3. Route Inspection & Debugging
- **List all registered routes**:
  ```bash
  php spark routes
  ```
- **Check filters applied to a route**:
  ```bash
  php spark filter:check GET api/v1/workspace
  ```

### 4. Code Generation
- **Create a Controller**:
  ```bash
  php spark make:controller <Name> --bare
  ```
- **Create a Model**:
  ```bash
  php spark make:model <Name>
  ```
- **Create an Entity**:
  ```bash
  php spark make:entity <Name>
  ```
- **Create a Migration**:
  ```bash
  php spark make:migration <MigrationName>
  ```
- **Create a Test**:
  ```bash
  php spark make:test <TestName>
  ```

### 5. Greyhound Custom Commands
- **Reanalyze Media with AI**:
  ```bash
  php spark ai:reanalyze-media
  ```
- **Backfill Department Storage**:
  ```bash
  php spark department:backfill-storage
  ```
- **Recover Department Media**:
  ```bash
  # Dry-run
  php spark department:recover-media
  # Apply changes
  php spark department:recover-media --apply
  ```
- **Clean Tag Metadata**:
  ```bash
  php spark media:clean-tags
  ```
- **Trash Retention & Backfill**:
  ```bash
  php spark trash:backfill-storage
  php spark trash:purge
  ```

### 6. System Housekeeping & Cache
- **Clear System Caches**:
  ```bash
  php spark cache:clear
  ```
- **Clear DebugBar and Log Files**:
  ```bash
  php spark debugbar:clear
  php spark logs:clear
  ```
