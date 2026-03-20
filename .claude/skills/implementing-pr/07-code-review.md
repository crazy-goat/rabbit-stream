# Step 7 — Internal Code Review (MANDATORY)

**CRITICAL: Code review MUST always be performed by a `code-heavy` subagent. Never do code review in the main thread.**

Before pushing anything, dispatch a `code-heavy` subagent (Task tool, `subagent_type: code-heavy`) to review the implementation. This is mandatory — do not skip.

**Why use sub-agent:** Code review requires deep analysis of multiple files, checking against protocol specs, and identifying subtle issues. Doing this in the main thread clutters the conversation and reduces quality. The `code-heavy` subagent is optimized for thorough code analysis.

## Prompt for the Subagent

**DO NOT run lint, fixer, rector, or phpstan in code review** - these are already done in Step 6 (Quality Gates). Focus on code analysis only.

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
> 
> **ALSO - Check previous review and save new findings:**
> 
> First, check if there are previous review notes:
> ```bash
> git notes show HEAD
> ```
> If notes exist, compare with current code to verify previous issues were fixed.
> 
> Then save new findings to git notes:
> ```bash
> git notes add -m "REPORT|critical=2|important=1|minor=3" HEAD
> git notes append -m "CRITICAL|src/File.php:42|Missing type declaration" HEAD
> git notes append -m "IMPORTANT|src/File.php:55|Wrong return type" HEAD
> git notes append -m "MINOR|src/File.php:60|Trailing whitespace" HEAD
> ```
> Format: `CATEGORY|file:line|description` or `REPORT|critical=N|important=N|minor=N`

## Communication via Git Notes (REQUIRED)

Git notes act as shared memory between review and build agents:

1. **Review agent (code-heavy)** → **WRITES** findings to notes
2. **Build agent (code/code-heavy)** → **READS** notes and fixes issues
3. **Next review agent** → **READS** previous notes to verify fixes

**For Build agent - read and clear notes:**
```bash
git notes show HEAD
git notes remove HEAD 2>/dev/null || true
```

**For Review agent - check previous fixes:**
```bash
git notes show HEAD
```

## Iteration Loop

Repeat until zero Critical and zero Important:

1. **Review** (code-heavy) analyzes code, WRITES findings to git notes
2. **Build** (code/code-heavy) READS notes, fixes every Critical/Important issue, CLEARS notes
3. **Build agent re-runs quality gates** (already defined in Step 6): `composer test:unit`, `composer lint`, `composer phpstan`
4. **Review** (code-heavy) READS previous notes, verifies fixes, WRITES new findings
5. Stop only when review reports: no Critical, no Important (and notes show REPORT|critical=0|important=0)

**Note:** The code review subagent should NOT run lint, fixer, rector, or phpstan - these are already handled in Step 6 (Quality Gates) by the build agent. The reviewer's job is code analysis only.

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

**Never proceed to Step 8 while any Critical or Important issue remains without explicit user approval.**

## Handling Minor Issues

Fix Minor issues if the fix is trivial (< 5 minutes). For non-trivial Minor issues:

1. List all remaining Minor issues to the user
2. Ask the user: "Should I create a follow-up GitHub issue for these, or fix them now?"
3. If user says create a ticket: create one issue collecting all remaining Minor items, then proceed to Step 8
4. If user says fix: fix them, re-run quality gates, then proceed to Step 8

**Do not declare the review "clean" or "passed" while any issues remain** — always show the user what was found and what was deferred.
