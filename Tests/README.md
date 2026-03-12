# Testing

This extension contains both unit tests and functional tests.

- **Unit tests** verify rule logic, API/controller behavior, DTO/value object behavior, and smaller isolated services.
- **Functional tests** verify repository persistence and scan-related behavior against a real TYPO3 testing database.

---

## Covered test areas

### Unit tests

- RTE accessibility rules
- structured field rules
- controller request validation and response flow
- scan result / verdict / context objects
- smaller logic-only services and helpers

### Functional tests

**`IssueRepository`**

- inserts new issues
- updates existing issues without duplicates
- re-opens resolved issues when found again
- never overwrites ignored or muted issues
- resolves unseen open issues correctly

**`SourceStateRepository`**

- detects changed vs unchanged content hashes
- inserts and updates source state rows
- isolates state by language
- deletes state rows per page

---

## Test configuration

This extension uses two separate PHPUnit config files:

| Config file | Purpose |
|---|---|
| `packages/a11y_quality_gate/phpunit.unit.xml` | Unit tests |
| `packages/a11y_quality_gate/phpunit.xml` | Functional tests |

The separation is intentional — unit tests use a lightweight custom bootstrap, functional tests use the TYPO3 testing framework bootstrap. Unit tests also enable mocking of final classes via `dg/bypass-finals`.

---

## Requirements

### General

- a TYPO3 project with this extension installed through Composer
- `phpunit/phpunit`
- Composer dev autoload for the extension test namespace

### Functional tests only

- `typo3/testing-framework`
- a working database connection for the TYPO3 testing framework
- permission to create temporary functional test databases

---

## Composer setup

```json
"require-dev": {
  "phpunit/phpunit": "^11.5",
  "typo3/testing-framework": "^9.4",
  "dg/bypass-finals": "^1.6"
},
"autoload-dev": {
  "psr-4": {
    "Priebera\\A11yQualityGate\\Tests\\": "packages/a11y_quality_gate/Tests/"
  }
}
```

After changing Composer configuration, run:

```bash
composer dump-autoload
```

---

## Running tests

### Run all unit tests

```bash
./vendor/bin/phpunit -c packages/a11y_quality_gate/phpunit.unit.xml
```

### Run all functional tests

```bash
./vendor/bin/phpunit -c packages/a11y_quality_gate/phpunit.xml
```

### Run one unit test file

```bash
./vendor/bin/phpunit -c packages/a11y_quality_gate/phpunit.unit.xml \
  packages/a11y_quality_gate/Tests/Unit/Controller/IssueApiControllerTest.php
```

### Run one functional test file

```bash
./vendor/bin/phpunit -c packages/a11y_quality_gate/phpunit.xml \
  packages/a11y_quality_gate/Tests/Functional/Domain/Repository/IssueRepositoryTest.php
```

### Run one specific test method — unit

```bash
./vendor/bin/phpunit -c packages/a11y_quality_gate/phpunit.unit.xml \
  --filter ignoreActionCallsIgnoreWhenValid
```

### Run one specific test method — functional

```bash
./vendor/bin/phpunit -c packages/a11y_quality_gate/phpunit.xml \
  --filter resolveUnseenDoesNotTouchIgnoredIssues
```

---

## DDEV database setup for functional tests

Functional tests create temporary databases. In DDEV, the database user must have sufficient privileges.

Set these variables before running functional tests:

```bash
export typo3DatabaseDriver=mysqli
export typo3DatabaseHost=db
export typo3DatabaseName=db
export typo3DatabaseUsername=root
export typo3DatabasePassword=root
export typo3DatabasePort=3306
```

> Running inside DDEV is usually simpler and more reliable than connecting from the host via a forwarded port.

---

## Recommended workflow in DDEV

### Run all functional tests

```bash
ddev exec env \
  typo3DatabaseDriver=mysqli \
  typo3DatabaseHost=db \
  typo3DatabaseName=db \
  typo3DatabaseUsername=root \
  typo3DatabasePassword=root \
  typo3DatabasePort=3306 \
  ./vendor/bin/phpunit -c packages/a11y_quality_gate/phpunit.xml
```

### Run one functional test file

```bash
ddev exec env \
  typo3DatabaseDriver=mysqli \
  typo3DatabaseHost=db \
  typo3DatabaseName=db \
  typo3DatabaseUsername=root \
  typo3DatabasePassword=root \
  typo3DatabasePort=3306 \
  ./vendor/bin/phpunit -c packages/a11y_quality_gate/phpunit.xml \
  packages/a11y_quality_gate/Tests/Functional/Domain/Repository/IssueRepositoryTest.php
```

### Run one specific functional test method

```bash
ddev exec env \
  typo3DatabaseDriver=mysqli \
  typo3DatabaseHost=db \
  typo3DatabaseName=db \
  typo3DatabaseUsername=root \
  typo3DatabasePassword=root \
  typo3DatabasePort=3306 \
  ./vendor/bin/phpunit -c packages/a11y_quality_gate/phpunit.xml \
  --filter resolveUnseenDoesNotTouchIgnoredIssues
```

---

## Unit test bootstrap

Unit tests use:

```
packages/a11y_quality_gate/Tests/Bootstrap/UnitTestsBootstrap.php
```

This bootstrap loads Composer autoload and enables `DG\BypassFinals`, which allows mocking final classes in PHPUnit. This is needed because several production classes are intentionally declared `final`.

---

## Interpreting results

### Successful unit run

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

................................
OK (269 tests, 369 assertions)
```

### Successful functional run

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

..............
OK, but there were issues!
Tests: 14, Assertions: 35, PHPUnit Deprecations: 14.
```

This means all tests and assertions passed. PHPUnit deprecations may still exist and should be cleaned up over time.

---

## Current status

- unit and functional tests are separated
- unit tests can mock final classes via `dg/bypass-finals`
- functional tests run against the TYPO3 testing framework
- repository persistence and rule logic are covered
- controller tests are covered in unit scope

---

## Troubleshooting

| Problem | Fix |
|---|---|
| `ClassIsFinalException` in unit tests | Ensure `dg/bypass-finals` is installed and unit tests are run with `phpunit.unit.xml` |
| Database permission errors | Use the root database user in DDEV |
| Missing class loading errors | Run `composer dump-autoload` |
| Functional bootstrap / DB errors | Verify paths and DB env vars in `phpunit.xml` |
| Wrong config file used | Use `phpunit.unit.xml` for unit tests and `phpunit.xml` for functional tests |

---

## Useful commands

```bash
composer dump-autoload

./vendor/bin/phpunit -c packages/a11y_quality_gate/phpunit.unit.xml

./vendor/bin/phpunit -c packages/a11y_quality_gate/phpunit.xml

ddev exec ./vendor/bin/phpunit -c packages/a11y_quality_gate/phpunit.xml
```