# Contributing to Accessibility Quality Gate

Thank you for your interest in improving AQG.

## Before you start

- Search existing [Issues](https://github.com/priebera1/typo3-a11y-quality-gate/issues) and [Discussions](https://github.com/priebera1/typo3-a11y-quality-gate/discussions) to avoid duplicates.
- For questions about installation or usage, use [Q&A Discussions](https://github.com/priebera1/typo3-a11y-quality-gate/discussions/categories/q-a).
- For bugs and false positives, open an Issue using the appropriate template.

## What belongs in Issues vs Discussions

| Situation | Where to go |
|---|---|
| Reproducible bug | Issue — Bug report |
| AQG flags something incorrectly | Issue — False positive |
| AQG misses a real problem | Issue — Missed accessibility issue |
| Feature suggestion or UX idea | Issue — Feature or UX feedback |
| Installation question | Discussion — Q&A |
| General workflow feedback | Discussion — Feedback |
| Sharing how you use AQG | Discussion — Show and tell |

## Reporting bugs

Use the **Bug report** template and include:

- AQG version, TYPO3 version, PHP version
- Exact steps to reproduce
- Expected vs actual result
- Anonymised content — no client domain names, email addresses or licence keys

## Reporting false positives

A false positive is when AQG flags content that you believe is accessible. Use the **False positive** template and include the anonymised HTML or content that triggered it.

## Pull requests

1. Fork the repository and create a branch from `main`.
2. Make one focused change per pull request.
3. Run the test suite before submitting:

```bash
composer install
vendor/bin/phpunit -c phpunit.unit.xml
vendor/bin/phpunit -c phpunit.xml
```

4. Fill in the pull request template completely.
5. Do not include unrelated changes to README, localization or SCSS unless your PR specifically addresses those files.

## Supported versions

| TYPO3 | PHP | Status |
|---|---|---|
| 13.4 LTS | 8.2, 8.3 | Supported |
| 14.3+ | 8.2, 8.3 | Supported |

## Accessibility rule changes

If your PR modifies or adds an accessibility rule:

- Consider both false-positive and false-negative risk.
- Add or update EN and DE localization strings.
- Add a test fixture for PASS and FAIL cases.
- Document the WCAG reference.

## Data privacy

Never include real client data in issues, pull requests or discussion posts. This includes domain names, email addresses, page content, licence keys or screenshots showing private information.
