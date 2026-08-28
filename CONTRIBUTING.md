# Contributing

Changes to the Alitycs PHP SDK must preserve wire compatibility, PHP 8.1 runtime compatibility,
fork and long-running-worker safety, bounded delivery, and honest lifecycle outcomes.

Run these checks before opening a pull request:

```bash
composer validate --strict
composer install
composer test
composer run test:coverage
./scripts/verify-workflow-pins.rb
./scripts/validate-coderabbit.sh
./scripts/test-coderabbit-policy.rb
```

Use private vulnerability reporting for security findings. Never commit credentials, customer
data, vendor output, or local environment files. Keep `CHANGELOG.md` current.

CodeRabbit automatically reviews ready pull requests, including dependency updates. Its native
status reports review completion, not approval. Resolve blocking findings and check its formal
review after every push. Governance changes additionally require code-owner approval; see
[CodeRabbit reviews](docs/coderabbit.md).
