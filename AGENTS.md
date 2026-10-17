# Greyhound Development Guidelines

## Architecture Overview
- **Backend**: CodeIgniter 4 (PHP 8.4) located at workspace root. Serves REST API and web views.
- **Mobile Client**: Expo React Native located in `greyhound-app-2025-main/`.
- **Database**: MariaDB running via XAMPP on `127.0.0.1:3307`, default database `greyhound_integration_test`.

## Code & CLI Standards
1. **Migrations & Spark**:
   - Always run migrations using `php spark migrate`.
   - Never write raw DDL migrations outside CodeIgniter's migration system.
2. **Database Queries**:
   - Use Query Builder or Models in `app/Models/` rather than raw SQL in controllers.
   - For database schema inspection, use the MCP MySQL tools or `php spark db:table <name>`.
3. **Mobile Client Development**:
   - Mobile changes should be tested within `greyhound-app-2025-main/`.
   - Ensure CORS headers and proxy endpoints are maintained for local mobile testing.
4. **Testing**:
   - Validate PHP backend changes with `./vendor/bin/phpunit`.
