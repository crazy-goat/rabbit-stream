# Step 1 — Pick an Issue

Skip this step if the user already provided an issue number.

## Fetch Issues

Fetch all open issues with full metadata:

```bash
gh issue list --state open --json number,title,labels,body --limit 50
```

## Analyze and Rank

Before showing anything to the user, analyze the full list:

1. **Read labels** — map each issue to a priority tier:
   - Tier 1 — `security`, `vulnerability`, `CVE`
   - Tier 2 — `performance`, `optimization`, `slow`
   - Tier 3 — `easy pick`, `good first issue`, `help wanted`, `beginner`, `starter`, `low hanging fruit`
   - Tier 4 — everything else (new features, protocol commands, etc.)

2. **Detect dependencies** — read each issue body for phrases like "depends on", "blocked by", "requires #N", "after #N". If an issue depends on another open issue, it drops below all issues it depends on regardless of tier.

3. **Sort** — within the same tier, prefer issues with no dependencies over issues with dependencies. Among issues with dependencies, prefer those whose blockers are already closed. Within the same tier and dependency status, prefer lower issue numbers (older issues first).

4. **Pick top 5** — select the 5 highest-ranked issues after sorting. If fewer than 5 open issues exist, show all of them.

## Present to User

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
