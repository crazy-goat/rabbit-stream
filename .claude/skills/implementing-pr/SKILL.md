---
name: implementing-pr
description: Use when user asks what to work on, wants to fix an issue, or provides a GitHub issue number to implement.
---

# Implementing a PR for rabbit-stream

## Overview

Workflow for picking and implementing a GitHub issue as a PR for `crazy-goat/streamy-carrot`.

Two entry points:
- **User gives issue number** → run `gh issue view {NUMBER}` first; if the issue is closed or doesn't exist, inform the user and ask them to provide a different issue number or say "pick" to see the ranked list
- **User asks "what can we do?" / "fix something"** → start at Step 1 (pick issue)

---

## Step 1 — Pick an Issue (skip if issue number already given)

Fetch all open issues with full metadata:

```bash
gh issue list --state open --json number,title,labels,body --limit 50
```

### Analyze and rank all issues

Before showing anything to the user, analyze the full list:

1. **Read labels** — map each issue to a priority tier:
   - Tier 1 — `security`, `vulnerability`, `CVE`
   - Tier 2 — `performance`, `optimization`, `slow`
   - Tier 3 — `easy pick`, `good first issue`, `help wanted`, `beginner`, `starter`, `low hanging fruit`
   - Tier 4 — everything else (new features, protocol commands, etc.)

2. **Detect dependencies** — read each issue body for phrases like "depends on", "blocked by", "requires #N", "after #N". If an issue depends on another open issue, it drops below all issues it depends on regardless of tier.

3. **Sort** — within the same tier, prefer issues with no dependencies over issues with dependencies. Among issues with dependencies, prefer those whose blockers are already closed. Within the same tier and dependency status, prefer lower issue numbers (older issues first).

4. **Pick top 5** — select the 5 highest-ranked issues after sorting. If fewer than 5 open issues exist, show all of them.

### Present top 5 to the user

Show the ranked list as plain text **before** asking the question — one issue per line, with a short explanation of why it ranks where it does:

```
Here are the top 5 issues ranked by priority:

1. #42 — [title]  [security]
   Reason: Security label — highest priority. No dependencies.

2. #17 — [title]  [performance]
   Reason: Performance improvement. Depends on #12 (already closed).

3. #8 — [title]  [easy pick]
   Reason: Easy pick, self-contained, no blockers.

4. #31 — [title]
   Reason: Core protocol feature, no dependencies.

5. #25 — [title]
   Reason: New command implementation, depends on #8 (open — ranks lower).
```

After displaying the list, use the `question` tool to ask the user which issue they want to work on. Offer: "Reply `all` to see the full list."

**Example question tool usage:**
```
question:
  questions:
    - header: "Select an issue"
      question: "Which issue would you like to work on?"
      options:
        - label: "Issue #42"
          description: "[title] - Security label, highest priority"
        - label: "Issue #17" 
          description: "[title] - Performance improvement"
        - label: "See all issues"
          description: "Show the complete ranked list"
```

If the user selects "See all issues" or replies **`all`**: display the full ranked list (all issues, all tiers) as plain text with reasoning, then use the `question` tool again to ask for an issue number.

Wait for the user to reply with a valid issue number before proceeding.

---

## Step 2 — Research the Issue

```bash
gh issue view {NUMBER}
```

**Always check reference implementations — no exceptions:**

### Go client (primary reference)
```bash
test -d /tmp/go-stream-ref/.git \
  && git -C /tmp/go-stream-ref pull \
  || gh repo clone rabbitmq/rabbitmq-stream-go-client /tmp/go-stream-ref
```
Key paths: `pkg/stream/`, `pkg/raw/`

If cloning fails (network/auth error): inform the user, ask whether to proceed without the Go reference or retry. Do not silently continue.

### Java client (secondary reference)
```bash
test -d /tmp/java-stream-ref/.git \
  && git -C /tmp/java-stream-ref pull \
  || gh repo clone rabbitmq/rabbitmq-stream-java-client /tmp/java-stream-ref
```
Key paths: `src/main/java/com/rabbitmq/stream/impl/`

If cloning fails: same as above — inform the user and ask.

### Protocol documentation
- Streams Protocol spec: `https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc`
- AMQP 1.0 spec (only if issue touches message encoding, properties, or annotations): search the Go client for `amqp` package usage in `/tmp/go-stream-ref/pkg/amqp/` and the Java client for `com.rabbitmq.stream.amqp` — read those files directly rather than fetching the spec download page.

**Rule:** pure stream commands/frames → Streams Protocol only. Message body/properties/annotations → read AMQP encoding from Go/Java client source.

---

## Step 3 — Present Implementation Plan

After research, present a plan to the user covering:

1. **What the protocol says** — frame structure, field types, sequence
2. **How Go does it** — key types/functions found in the Go client
3. **How Java does it** — key classes/methods found in the Java client
4. **Proposed PHP implementation** — which files to create/modify:
   - `src/Request/{Name}RequestV1.php`
   - `src/Response/{Name}ResponseV1.php`
   - `src/Enum/KeyEnum.php` (new enum cases)
   - `src/ResponseBuilder.php` (new match arm)
   - `tests/Request/` and `tests/Response/`
5. **Any open questions or edge cases**

**Wait for user approval before writing any code.**

---

## Step 4 — Create Branch (after user approves plan)

```bash
git checkout main && git pull
git checkout -b feature/issue-{NUMBER}-{short-description}
```

Branch `{short-description}`: 2-3 lowercase hyphenated words derived from the issue title (e.g. `feature/issue-9-delete-publisher`).

---

## Step 5 — Implement (TDD)

Load the `test-driven-development` skill and follow its workflow. Pass the approved plan from Step 3 and the issue number as context. Follow `AGENTS.md` conventions exactly.

Key TDD steps for this project:
1. Write the test first (`tests/Request/` or `tests/Response/`)
2. Run `composer test:unit` — confirm the test fails
3. Write the implementation
4. Run `composer test:unit` — confirm the test passes
5. Refactor if needed, keep tests green

---

## Step 6 — Quality Gates (ALL must pass before pushing)

```bash
composer test:unit   # unit tests
composer lint        # PHPCS code style
composer phpstan     # static analysis
```

If lint fails: run `composer lint:fix`, then re-run all three in order: `composer test:unit`, `composer lint`, `composer phpstan`.

If `composer lint` still fails after `lint:fix` (some violations aren't auto-fixable): fix the remaining violations manually, then re-run all three gates.

Never suppress PHPStan with `@phpstan-ignore`.

---

## Step 6.5 — Internal Code Review (MANDATORY - always use sub-agent)

**⚠️ CRITICAL: Code review MUST always be performed by a `build-heavy` subagent. Never do code review in the main thread.**

Before pushing anything, dispatch a `build-heavy` subagent (Task tool, `subagent_type: build-heavy`) to review the implementation. This is mandatory — do not skip.

**Why use sub-agent:** Code review requires deep analysis of multiple files, checking against protocol specs, and identifying subtle issues. Doing this in the main thread clutters the conversation and reduces quality. The `build-heavy` subagent is optimized for thorough code analysis.

**Prompt for the subagent:**

> You are a code reviewer for the `crazy-goat/streamy-carrot` PHP library (RabbitMQ Streams Protocol client).
>
> Run `git diff main...HEAD` to find all changed files, then read them in full. Check:
> - Correctness against the RabbitMQ Streams Protocol spec
> - PHP 8.1+ type safety (all param/return types declared, no `mixed` unless unavoidable)
> - PSR-12 code style
> - Test coverage (request serialization, response deserialization, edge cases)
> - Adherence to project conventions from `AGENTS.md`
> - No `@phpstan-ignore` suppressions
>
> Return findings categorized as Critical / Important / Minor with file:line references. Be specific.

**Communication via git notes (optional but recommended):**

The subagent can leave detailed review findings as git notes for structured parsing:

```bash
# Subagent adds review notes to HEAD
git notes add -m "REPORT|critical=2|important=1|minor=3" HEAD
git notes append -m "CRITICAL|src/File.php:42|Missing type declaration" HEAD
git notes append -m "IMPORTANT|src/File.php:55|Wrong return type" HEAD
```

Format: `CATEGORY|file:line|description` or `REPORT|critical=N|important=N|minor=N`

Main agent reads notes with: `git notes show HEAD`

**Important:** Clean up notes before pushing: `git notes remove HEAD`

**Iteration loop — repeat until zero Critical and zero Important:**

1. Subagent returns findings
2. Fix every Critical and every Important issue
3. Re-run quality gates: `composer test:unit`, `composer lint`, `composer phpstan`
4. Dispatch `build-heavy` subagent again with the same prompt
5. Stop only when subagent reports: no Critical, no Important

**If Critical or Important issues persist after reasonable effort:**

Use the `question` tool to ask the user for guidance:

```
question:
  questions:
    - header: "Code review issues remaining"
      question: "After multiple review cycles, Critical/Important issues remain. How should we proceed?"
      options:
        - label: "Continue fixing"
          description: "Keep iterating until all issues are resolved"
        - label: "Accept with issues"
          description: "Proceed to PR despite remaining issues (document them)"
        - label: "Abandon this approach"
          description: "Try a different implementation strategy"
```

**Never proceed to Step 7 while any Critical or Important issue remains without explicit user approval.**

### Handling Minor issues

Fix Minor issues if the fix is trivial (< 5 minutes). For non-trivial Minor issues:

1. List all remaining Minor issues to the user
2. Ask the user: "Should I create a follow-up GitHub issue for these, or fix them now?"
3. If user says create a ticket: create one issue collecting all remaining Minor items, then proceed to Step 7
4. If user says fix: fix them, re-run quality gates, then proceed to Step 7

**Do not declare the review "clean" or "passed" while any issues remain** — always show the user what was found and what was deferred.

---

## Step 7 — Push and Open PR

**Clean up git notes before pushing:**

```bash
# Remove all review notes from commits in this branch
git log main..HEAD --pretty=format:%H | while read commit; do
  git notes remove $commit 2>/dev/null || true
done
```

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

---

## Step 8 — Wait for CI, Then Ask for Approval

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

## Quick Reference

| Command | Purpose |
|---------|---------|
| `gh issue list --state open` | List open issues |
| `gh issue view {N}` | Read issue details |
| `composer test:unit` | Unit tests |
| `composer lint` | Check code style |
| `composer lint:fix` | Auto-fix style |
| `composer phpstan` | Static analysis |
| `gh pr checks --watch` | Wait for CI |
