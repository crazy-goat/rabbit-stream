# Step 4 — Create Branch

After user approves the plan:

```bash
git checkout main && git pull
git checkout -b feature/issue-{NUMBER}-{short-description}
```

Branch `{short-description}`: 2-3 lowercase hyphenated words derived from the issue title (e.g. `feature/issue-9-delete-publisher`).
