---
name: implementing-pr
description: Use when user asks what to work on, wants to fix an issue, or provides a GitHub issue number to implement.
---

# Implementing a PR for rabbit-stream

## Overview

Workflow for picking and implementing a GitHub issue as a PR for `crazy-goat/streamy-carrot`.

**Step files** (in this skill's directory):
- `01-pick-issue.md` — fetch and rank open issues by priority/dependencies
- `02-research.md` — research issue with reference implementations
- `03-plan.md` — present implementation plan to user
- `04-branch.md` — create feature branch
- `05-implement.md` — implement with code agent
- `06-quality-gates.md` — run all QA checks
- `07-code-review.md` — internal code review with sub-agent (mandatory)
- `08-push-pr.md` — push and open PR
- `09-ci-approval.md` — wait for CI, ask for approval, merge PR, switch to main

## Entry Points

Two entry points:
- **User gives issue number** → run `gh issue view {NUMBER}` first; if the issue is closed or doesn't exist, inform the user and ask them to provide a different issue number or say "pick" to see the ranked list
- **User asks "what can we do?" / "fix something"** → start at Step 1 (pick issue)

## Workflow Summary

```
┌─────────────────────────────────────────────────────────────┐
│  Step 7: Code Review Loop (with Git Notes Checklist)       │
│                                                             │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐   │
│  │   Review     │───→│    Build     │───→│   Review     │   │
│  │  (checks)    │    │   (fixes)    │    │ (verifies)   │   │
│  └──────────────┘    └──────────────┘    └──────────────┘   │
│         ↑                                    │              │
│         └────────────────────────────────────┘              │
│                                                             │
│  Git Notes (markdown checklist):                            │
│  - [ ] Critical issues to fix                               │
│  - [ ] Important issues to fix                              │
│  - [x] Completed items                                      │
│  - Loop counter                                             │
│                                                             │
│  Every 10 loops → ask user: "Continue or proceed to PR?"   │
└─────────────────────────────────────────────────────────────┘
```

**Key principle:** Review agent maintains the checklist, build agent fixes unchecked items, main thread orchestrates and asks user every 10 loops.
