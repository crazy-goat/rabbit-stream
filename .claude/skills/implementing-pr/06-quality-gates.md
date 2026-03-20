# Step 6 — Quality Gates

ALL must pass before pushing. Run in this exact order:

```bash
# 1. Auto-fix code style violations (no manual fixes needed)
composer lint:fix

# 2. Static analysis - fix any errors manually
composer phpstan

# 3. Unit tests
composer test:unit

# 4. E2E tests (requires RabbitMQ running)
./run-e2e.sh
```

**Important:**
- `lint:fix` auto-fixes style - no manual intervention needed
- `phpstan` may report errors - you MUST fix these manually  
- Never suppress PHPStan with `@phpstan-ignore`
- All tests must pass before proceeding to code review
