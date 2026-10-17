---
name: ci4-testing
description: >-
  Use this skill when running PHPUnit test suites, writing unit tests for CodeIgniter 4
  controllers/models/services, or diagnosing test failures in the Greyhound platform.
---

# CodeIgniter 4 Testing Skill

This skill outlines how to run and debug automated PHPUnit tests for the Greyhound backend.

## Test Execution

### 1. Run Full Test Suite
```bash
./vendor/bin/phpunit
```

### 2. Run a Specific Test Directory or File
```bash
# Run all controller tests
./vendor/bin/phpunit tests/app/Controllers

# Run a single test file
./vendor/bin/phpunit tests/app/Controllers/Web/WorkspaceControllerTest.php
```

### 3. Filter by Test Name
```bash
./vendor/bin/phpunit --filter testWorkspaceIndexReturnsSuccess
```

### 4. Test Output and Coverage
- Coverage reports are stored in `build/logs/html/index.html`.
- Testdox summary is saved to `build/logs/testdox.txt`.

## Writing New Tests
Use Spark generator to create test files:
```bash
php spark make:test <TestName>
```
All test classes should extend `CodeIgniter\Test\CIUnitTestCase` or `CodeIgniter\Test\DatabaseTestTrait`.
