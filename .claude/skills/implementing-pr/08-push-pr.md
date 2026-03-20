# Step 8 — Push and Open PR

## Clean Up Git Notes

```bash
git log main..HEAD --pretty=format:%H | while read commit; do
  git notes remove $commit 2>/dev/null || true
done
```

## Push and Create PR

Choose the conventional commit prefix based on the issue type: `feat` (new feature/command), `fix` (bug fix), `refactor` (refactoring), `docs` (documentation), `test` (tests only). Replace all `{placeholders}` with actual values before running.

```bash
git push -u origin feature/issue-{NUMBER}-{short-description}

gh pr create \
  --title "{prefix}: {description} (closes #{NUMBER})" \
  --body "$(cat <<'EOF'
## Summary

Closes #{NUMBER}

## Changes

- [list key changes]

## Reference implementations checked

- [x] Go client: rabbitmq/rabbitmq-stream-go-client
- [x] Java client: rabbitmq/rabbitmq-stream-java-client
- [x] Protocol spec: PROTOCOL.adoc
- [x or N/A] AMQP 1.0 spec — mark `[x]` if issue touched message encoding/properties/annotations (checked via Go/Java source), otherwise replace with `[N/A]`

## Testing

- Unit tests: pass
- Lint (PHPCS): pass
- PHPStan: pass
EOF
)"
```
