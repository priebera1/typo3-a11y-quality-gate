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

The separation is intentional — unit tests use a lightweight custom bootstrap, functional tests use the TYPO3 testing framework bootstrap through an extension-local bootstrap. Unit tests also enable mocking of final classes via `dg/bypass-finals` when the package is installed.

---

## Requirements

### General

- a TYPO3 project with this extension installed through Composer
- `phpunit/phpunit`
- Composer dependencies installed in the root TYPO3 project

### Functional tests only

- `typo3/testing-framework`
- `typo3/cms-rte-ckeditor` installed in the root TYPO3 test project
- a working database connection for the TYPO3 testing framework
- permission to create temporary functional test databases

---

## Composer setup

```json
"require-dev": {
  "phpunit/phpunit": "^11.5 || ^12.0",
  "typo3/testing-framework": "^9.4",
  "dg/bypass-finals": "^1.8"
}
```

The extension ships its own PHPUnit bootstraps. The functional bootstrap registers the extension test namespace before loading TYPO3 testing-framework, so the root project does not need to duplicate the extension test namespace in its own `autoload-dev`.

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

Functional tests create temporary databases. In DDEV, the database user must have sufficient privileges. The value of `typo3DatabaseName` is used as the prefix for generated test databases such as `func_ft...`; it does not need to be the live project database name.

Set these variables before running functional tests:

```bash
export typo3DatabaseDriver=mysqli
export typo3DatabaseHost=db
export typo3DatabaseName=func
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
  typo3DatabaseName=func \
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
  typo3DatabaseName=func \
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
  typo3DatabaseName=func \
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

Functional tests use:

```
packages/a11y_quality_gate/Tests/Bootstrap/FunctionalTestsBootstrap.php
```

The unit bootstrap loads Composer autoload and enables `DG\BypassFinals` when available, which allows mocking final classes in PHPUnit. The functional bootstrap registers the extension test namespace and then loads TYPO3 testing-framework.

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
| `Package "a11y_quality_gate" depends on package "rte_ckeditor" which does not exist` | Install `typo3/cms-rte-ckeditor` in the root TYPO3 test project, for example `composer require "typo3/cms-rte-ckeditor:^13.4 || ^14.3" -W`, and run `composer dump-autoload` |
| `Unknown database ..._ft...` | Verify DDEV DB env vars and ensure the DB user can create databases; use `typo3DatabaseName=func` as test DB prefix |
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