# Contributing

Thanks for helping. This is a small plugin with a strict job: never spend
more than the operator allowed, and never publish a picture nobody chose.
Changes that weaken either will not be merged.

## Setup

```sh
git clone https://github.com/IndianOpinion/featured-from-prompt.git
cd featured-from-prompt
composer install
```

PHP 8.1 or later. GD is needed for the tests (they build test images).

## Tests

```sh
composer test        # PHPUnit, unit suite (no WordPress, no network)
composer analyse     # PHPStan level 8
composer lint        # phpcs, PSR-12 with snake_case method names
```

The unit suite runs against the stub layer in `tests/stubs/`. Each PRD case
has a test named `test_case_NN_...`; add or change the test with the case.

The database suite needs MySQL or MariaDB:

```sh
mysql -e 'CREATE DATABASE ffp_test'
FFP_TEST_DB='127.0.0.1:3306:root:password:ffp_test' composer test:integration
```

`FFP_TEST_DB` is `host:port:user:password:database`. Set
`FFP_TEST_DB_SOCKET` to use a socket. Without `FFP_TEST_DB` those tests are
skipped. Tests never use the network.

## Style

- `declare(strict_types=1);` in every file, PHP 8.1 syntax only (no
  readonly classes, no DNF types).
- Typed exceptions inside, reason codes outside. No `@` suppression, no
  `eval`, no shell.
- Escape every output in admin, check a nonce and a capability on every
  form and route.
- Prose: short sentences, plain words, British spelling.

## Pull requests

- One topic per pull request, with tests.
- Describe the behaviour change and the PRD case it touches. If it changes
  a decision in `docs/ARCHITECTURE.md`, update that file.
- CI must be green: lint, phpcs, PHPStan and PHPUnit on PHP 8.1, 8.2 and
  8.3.
- A DCO-style sign-off is not required.
