# Step 7 — Internal Code Review (MANDATORY)

**CRITICAL: Code review MUST always be performed by a `code-heavy` subagent.**

Dispatch a `code-heavy` subagent to review the implementation and update the review checklist in git notes.

## Git Notes Format (Markdown Checklist)

The subagent maintains a markdown checklist in git notes:

```markdown
# Code Review Checklist - Loop {N}

## Summary
- **Status**: IN_PROGRESS | PASSED | FAILED
- **Loop**: {counter}
- **Critical**: {count}
- **Important**: {count}
- **Minor**: {count}

## Checklist

### Critical (MUST FIX)
- [ ] src/File.php:42 | Missing type declaration
- [ ] src/Other.php:55 | Protocol violation

### Important (SHOULD FIX)
- [ ] src/File.php:60 | Wrong return type

### Minor (NICE TO HAVE)
- [ ] src/File.php:70 | Trailing whitespace
- [ ] tests/Test.php:30 | Missing edge case

## Previous Loops
- Loop 1: 3 critical, 2 important found
- Loop 2: 1 critical, 0 important found
```

## Prompt for the Review Subagent

> Review the current branch against `main`. 
>
> **First, read existing git notes:**
> ```bash
> BRANCH=$(git branch --show-current)
> git notes --ref=refs/notes/review show $BRANCH 2>/dev/null || echo "NO_NOTES"
> ```
>
> **Check what was already fixed:**
> - Compare previous checklist with current code
> - Mark completed items as checked [x]
> - Add new issues found as unchecked [ ]
>
> **Check:**
> - Protocol correctness
> - PHP 8.1+ type safety
> - PSR-12 compliance
> - Test coverage
> - AGENTS.md conventions
>
> **Update git notes with new checklist:**
> ```bash
> BRANCH=$(git branch --show-current)
> # Remove old notes
> git notes --ref=refs/notes/review remove $BRANCH 2>/dev/null || true
> # Add new checklist (use heredoc or echo with newlines)
> git notes --ref=refs/notes/review add -m "# Code Review Checklist - Loop {N}..." $BRANCH
> ```
>
> **Return exactly one sentence:**
> - If all Critical/Important checked: "Code review passed - ready to proceed to PR"
> - If any Critical/Important unchecked: "Code review found issues - continue fixing"

## Prompt for the Build Subagent (Fixing Issues)

> Read the current checklist from git notes:
> ```bash
> BRANCH=$(git branch --show-current)
> git notes --ref=refs/notes/review show $BRANCH 2>/dev/null || echo "NO_NOTES"
> ```
>
> Fix ALL unchecked Critical and Important items:
> - Read the files mentioned
> - Apply fixes
> - Do NOT update git notes (review agent does that)
>
> After fixing, run quality gates from Step 6:
> - `composer test:unit`
> - `composer lint`  
> - `composer phpstan`
>
> Return: "Fixed {N} critical and {M} important issues"

## Main Thread Loop Logic

```
LOOP_COUNTER = 0
MAX_LOOPS = 10

while true:
    LOOP_COUNTER += 1
    
    # Every 10 loops, ask user
    if LOOP_COUNTER % 10 == 0:
        ask_user: "Continue fixing or proceed to PR?"
        if user says proceed:
            break
    
    # Run review subagent
    result = dispatch_review_subagent()
    
    if result == "Code review passed - ready to proceed to PR":
        break
    
    if result == "Code review found issues - continue fixing":
        # Run build subagent to fix issues
        dispatch_build_subagent()
        # Loop continues - review will check what was fixed
```

## Decision Rules

**Review subagent decides:**
- PASSED = all Critical and Important items are checked [x]
- FAILED = any Critical or Important item is unchecked [ ]

**Main thread stops when:**
1. Review returns "passed" → proceed to Step 8
2. User says "proceed anyway" after 10+ loops
3. Review returns "failed" but no new issues in 3 consecutive loops (stuck detection)
