# Docker dev environment

PHP is not required on the host. Run everything from the repo root.

```bash
alias lw='docker compose -f docker/docker-compose.yml run --rm app'
alias lwb='docker compose -f docker/docker-compose.yml run --rm browser'

lw composer install                       # vendor/ (bind-mounted into the repo, owned by you)
lw npm ci && lw npm run build             # node_modules/ and dist/ (dist/ is gitignored, never commit it)
lw vendor/bin/phpunit --testsuite Unit    # ~20s, 1535 tests
lw npm run test:run                       # vitest (JS)
lw vendor/bin/phpunit src/Performance/RenderPerformanceBenchmarkTest.php   # existing mount benchmark
lwb vendor/bin/phpunit --testsuite Browser --filter SupportWireText        # Dusk: ALWAYS use --filter
```

- Another PHP version: `PHP_VERSION=8.2 docker compose -f docker/docker-compose.yml build app`. The image tag
  includes the version (`livewire-dev:8.2`). Remove `vendor/` and reinstall when switching versions.
- `app` sets `DUSK_DRIVER_URL`, which makes testbench-dusk skip launching chromedriver in `setUpBeforeClass`.
  Without it every unit test class errors with "Invalid path to Chromedriver".
- `browser` adds Chromium + chromedriver; its entrypoint symlinks chromedriver into `vendor/laravel/dusk/bin`.
- PHP extensions: intl, zip, pdo_sqlite, mbstring, pcntl, gd (GD is needed by `UploadedFile::fake()->image()`).
- `memory_limit=-1`; opcache is installed but disabled for CLI unless `OPCACHE_CLI=1` is set. Benchmarks should
  state which they used.
