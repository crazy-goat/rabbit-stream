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
