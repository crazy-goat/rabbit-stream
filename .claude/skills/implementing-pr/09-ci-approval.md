# Step 9 — Wait for CI, Then Ask for Approval

```bash
timeout 600 gh pr checks --watch
```

If checks haven't completed after 10 minutes (`timeout` exits with code 124), inform the user of the current status and stop — do not block indefinitely.

**When all checks are green**, use the `question` tool to ask the user for approval:

```
question:
  questions:
    - header: "PR Ready for Review"
      question: "All CI checks passed. Do you approve this PR for code review?"
      options:
        - label: "Yes, approve"
          description: "PR is ready for code review"
        - label: "No, needs changes"
          description: "I want to make additional changes first"
```

Only proceed with merge/close when the user explicitly approves.

**Never merge or close without explicit user approval.**

---

## After User Approves

When the user selects **"Yes, approve"**, perform the following steps in order:

### 1. Merge the PR to `main`

```bash
gh pr merge --merge --delete-branch
```

This merges the PR using a merge commit, deletes the remote feature branch, and closes the associated issue if the PR body contains `Closes #N`.

### 2. Switch to `main` and pull

```bash
git checkout main && git pull
```

### 3. Clean up local feature branch (if it still exists)

```bash
BRANCH_NAME=$(git branch --list 'feature/issue-*' | head -1 | tr -d ' *')
if [ -n "$BRANCH_NAME" ]; then
  git branch -d "$BRANCH_NAME" 2>/dev/null || true
fi
```

### 4. Post-merge documentation updates

Follow the **"After Merging a Feature Branch"** section from `AGENTS.md`:

1. **Close the GitHub issue** (if not auto-closed by the PR merge):
   ```bash
   gh issue close {NUMBER}
   ```

2. **Update `README.md`** — change `❌` to `✅` in the Protocol Implementation Status table for the implemented command.

3. **Update `CHANGELOG.md`** — add the change under `[Unreleased]` if not already present.

4. **Commit documentation updates** directly to `main`:
   ```bash
   git add README.md CHANGELOG.md
   git commit -m "docs: mark {CommandName} as implemented in README, close issue #{NUMBER}"
   git push
   ```

### 5. Confirm completion

Report to the user:
- ✅ PR merged to `main`
- ✅ Feature branch deleted
- ✅ Issue #{NUMBER} closed
- ✅ README and CHANGELOG updated
- ✅ Now on `main` branch
