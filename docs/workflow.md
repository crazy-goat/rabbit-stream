# Workflow: Issue → Feature Branch → Implementation → Review Rounds → PR → CI → Merge

This document describes the complete workflow for handling issues in the
[crazy-goat/rabbit-stream](https://github.com/crazy-goat/rabbit-stream) repository
using `gh` and `git`. It is adapted from the workerman-bundle workflow, but
reflects the realities of this project: `main` (not `master`), `feature/issue-NNN-*`
branches, the `composer lint` / `composer test` / `run-e2e.sh` toolchain, PHPStan
level 9, and the milestone-driven issue backlog.

Every cycle leaves a **proof of work** under `docs/proof_of_work/<NNN>-<slug>/`:
four kinds of Markdown file, written by the agents that did the work and
committed on the branch. See [Proof of Work](#proof-of-work-docsproof_of_work)
below. Nothing enforces them. They are read during review, like the code.

> This project now has a knowledge base (`docs/helpers/`, see
> "Knowledge Base (docs/helpers/)" below) and proof-of-work files
> (`docs/proof_of_work/`). Where the workerman-bundle workflow relied on
> `bin/gh-branch` and `bin/pick-issue.php`, this project has no such helpers
> yet — the equivalent steps are done inline with `gh` + `git` below. If you
> add the helpers, replace the inline commands.

---

## 1. Browse Open Issues via Subagent

Browsing and triaging open issues is token-heavy (titles, bodies, labels,
comments, related code). Delegate it to a subagent with its own context.

```bash
# The subagent receives a task like:
# "List the top 5 most impactful open issues in crazy-goat/rabbit-stream.
# For each, return: number, title, labels, milestone, one-paragraph rationale.
# Do NOT propose branch names — the main session derives them in step 2.
# Prioritize by the project's labels:
#   - type:    bug, security, enhancement, documentation, performance
#   - priority: priority:critical, priority:high, priority:medium, priority:low
#   - onboarding: good first issue
#   - stage:   stage:blocked (deprioritize), stage:awaiting-approval (skip unless asked)
# Prefer the lowest open milestone (v1.3.0 < v1.4.0 < v1.5.0).
# Use gh issue list --state open --limit 100 and gh issue view <n> --json
# title,body,labels,state,milestone."
```

The subagent uses `gh issue list --state open --limit 100` and
`gh issue view <n> --json title,body,labels,milestone` to gather data, then
returns a ranked shortlist. The main session picks one issue and proceeds to
step 2.

> **Note:** `gh issue list` returns **at most 30 issues by default** — the
> triage task must explicitly raise `--limit` (e.g. `--limit 100`, max 1000)
> so issues beyond the first page are not missed.
>
> **Why a subagent:** issue bodies, comments, and related code can easily
> exceed thousands of tokens. Keeping this in a separate context protects the
> main session's budget for implementation and review.

### Milestone-driven selection

This project uses GitHub milestones as release buckets
(`v1.3.0`, `v1.4.0`, `v1.5.0`). Work is driven by the **lowest open milestone**
— a milestone is a release candidate, not a bottomless backlog. When picking an
issue, prefer the lowest milestone first. When the current milestone has no open
issues left, the workflow must **stop** — do not silently pick an issue from a
higher milestone. Cut the release (see `release-workflow.md` if present, else
follow the "After Merging" section below), close the milestone, then resume.

> Unlike workerman-bundle, there is no `bin/pick-issue.php` to enforce the
> release gate automatically. The triage subagent must check milestone
> emptiness explicitly:
> `gh api repos/crazy-goat/rabbit-stream/milestones --jq '.[] | {title,open_issues}'`

**Selection criteria (applied by the subagent):**

- Issues labeled `bug`, `security`, `performance` (correctness/stability first)
- Issues labeled `enhancement`, `documentation`, `good first issue`
- Issues blocking other tasks (check `stage:blocked` on dependents)
- Issues most relevant to users (README, API docs, protocol coverage)

---

## 2. Create a Fresh Feature Branch

This project has no `bin/gh-branch` helper, so the branch is created inline.
The name follows the **existing repository convention** `feature/issue-NNN-<slug>`
(seen in `feature/issue-9-delete-publisher`, `feature/issue-347-keyenum-fallback`,
`feature/issue-360-readme-consumer-autocommit-example`).

```bash
# 0. Start from a clean, up-to-date main
git checkout main
git pull origin main

# 1. Derive a short kebab-case slug from the issue title
#    e.g. issue #437 "Docs: CHANGELOG.md is ~30 commits behind…" → changelog-sync
NN=<issue-number>
SLUG=<short-kebab-slug>
BRANCH="feature/issue-${NN}-${SLUG}"

# 2. Create and switch
git checkout -b "$BRANCH"

# 3. Push with upstream
git push -u origin "$BRANCH"
```

**Branch naming convention:** `feature/issue-NNN-<slug>` (e.g.
`feature/issue-437-changelog-sync`). This matches every existing feature branch
in the repository. Do **not** use `feat/`/`fix/` prefixes — the type is
conveyed by issue labels and commit-message type, not the branch prefix.

> A `process/` prefix is reserved for changes to the workflow itself
> (`docs/workflow.md`, `.github/workflows/*`, the `scripts` block of
> `composer.json`, `run-e2e.sh`) — so "we changed the rules" is visible in the
> branch name. Use `process/issue-NNN-<slug>` for those.

Then make the directory this cycle's proof of work lives in:

```bash
mkdir -p "docs/proof_of_work/${NN}-${SLUG}"
```

Four kinds of file end up there: `findings-coder.md`, `findings-review.md`,
`code-decision-<x>.md` and `review-<x>.md`, where `<x>` is the round of the
inner loop. They are written by the subagents that do the work and committed on
the branch like any other change. See
[Proof of Work](#proof-of-work-docsproof_of_work) below.

---

## 3. Implement the Change (via Worker/Coder Subagent)

Implementation is delegated to a subagent (`worker` or `coder`) so the main
session stays free to orchestrate, review findings, and handle the next steps.

```bash
# The subagent receives a task like:
# "Implement issue #<NN> on branch feature/issue-<NN>-<slug>.
# Read AGENTS.md first — especially 'Implementing a New Protocol Command' and
# 'Server-Push Frames (Async)' if the issue touches the protocol surface.
# Read the issue body first, then make the smallest correct change.
# Run the relevant tests for the changed behavior:
#   - unit:    ./vendor/bin/phpunit --testsuite unit
#   - one file: ./vendor/bin/phpunit tests/Request/SaslHandshakeRequestV1Test.php
#   - e2e (only if the change is wire-level): ./run-e2e.sh
# E2E boots RabbitMQ via docker compose on port 5552 — run it only for
# protocol-level changes, never for pure unit/serialization fixes.
#
# Write two files under docs/proof_of_work/<NN>-<slug>/:
# - code-decision-<x>.md (x = this round): the approach you took, what you
#   rejected and why, and anything you were unsure about
# - findings-coder.md (append if it exists): what you found along the way —
#   obstacles, surprises, and any bugs or weak spots you noticed, INCLUDING
#   ones outside this issue's scope, each with file/line and a suggested fix
#
# Commit and push everything, the two files included."
```

After the subagent reports, commit and push if it did not do so already:

```bash
git add -A
git commit -m "feat(<scope>): <short description> (closes #<NN>)"
git push origin "feature/issue-${NN}-${SLUG}"
```

**Commit message convention:**

- Type: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `perf`, `ci`
- Scope: `(<scope>)` where scope matches the touched area — `(core)`,
  `(protocol)`, `(buffer)`, `(connection)`, `(consumer)`, `(producer)`,
  `(response)`, `(request)`, `(docs)`, `(ci)`, etc. For a new protocol command,
  use the command name, e.g. `(query-publisher)`.
- Reference to issue: `(closes #<NN>)`

> **Coder output contract (non-negotiable):** the subagent must always report
> (1) changed files, (2) the biggest problem it faced with details, and
> (3) any discovered bugs / places to improve — even ones outside the current
> issue's scope — and must write (2) and (3) into `findings-coder.md` rather
> than leaving them in chat. The main session reuses them for the final report
> (step 14).

---

## 4. Code Review via Subagent

After implementation, run a code review using a subagent (separate agent with
its own context). The subagent checks:

- Alignment with project structure (PSR-4, namespace `CrazyGoat\RabbitStream\`,
  the `src/` ↔ `tests/` mirror, the `V1` suffix convention)
- Protocol correctness — frame layout, big-endian integers, the
  request-key/`0x8000` response-key rule, server-push frames use the **request**
  key (see AGENTS.md "Server-Push Frames")
- Type correctness (PHPStan **level 9** — run `composer phpstan`)
- Error handling and edge cases (`assertResponseCodeOk`, `fromStreamBuffer`
  returning `null` on graceful parse failure, custom exceptions)
- Coding style (PSR-12 via PHPCS — run `composer cs`)
- Test coverage — unit tests for every new request/response serialization;
  E2E only when the wire format changes
- Security (raw socket I/O, untrusted server data, `socket_select` loop)

**Review round `<x>` reads `findings-review.md` first.** Before looking for
anything new it goes through what earlier rounds recorded and says, for each,
whether it is still present. Nothing is deleted from that file — a finding the
coder believes fixed and the review still sees is a disagreement worth keeping
on the record.

```bash
# The subagent receives a task like:
# "Code review the changes in files: <list>.
# Read AGENTS.md first — 'Code Style Guidelines', 'Implementing a New Protocol
# Command', and 'Server-Push Frames (Async)' — and flag any violations of the
# documented conventions. Then read docs/proof_of_work/<NN>-<slug>/findings-review.md
# and, for every finding an earlier round left open, state explicitly:
# still present / fixed / not a real finding (with evidence). Only then look
# for NEW issues.
# Run locally: composer cs, composer phpstan, composer rector (dry-run),
#   ./vendor/bin/phpunit --testsuite unit.
# Check: protocol wire-format correctness, type correctness (PHPStan 9),
# PSR-12 compliance, missing tests, outdated docs.
#
# Write two files under docs/proof_of_work/<NN>-<slug>/:
# - review-<x>.md (x = this round): your full review for this round
# - findings-review.md (append if it exists): one entry per finding —
#   file:line, what is wrong, severity, and what happened to it
#
# For any finding an automated check could plausibly have caught, say which
# check that would be. If the same class of defect has been seen before,
# write the check in this PR rather than reporting it again."
```

Severities are `high`, `medium`, `low`, `nit`. Anything non-obvious the review
learned is recorded in `findings-review.md` (this project has no
`docs/helpers/` knowledge base yet — see "Knowledge Base" below).

> **Why a subagent:** code review reads the full diff plus surrounding code,
> runs static analysis, and produces a structured findings list. Delegating
> keeps the main session focused on fixes and the next workflow step.

---

## 5. Fix the Findings

```bash
# For each finding:
# 1. Apply the fix
# 2. Note in findings-review.md what happened to it
# 3. Commit
git add -A
git commit -m "fix(<scope>): <what was fixed>"
git push origin "feature/issue-${NN}-${SLUG}"
```

**All findings get an answer — even the `nit`s.** Fixed, deliberately not fixed
(say why, and cite AGENTS.md if there is a relevant rule), or not a real finding
(say what the evidence was). Silence is not an answer.

> **A finding first seen in round 2 or later escaped round 1.** That usually
> means a check was missing rather than that a reviewer was unlucky — prefer
> adding the test over just fixing the line.

---

## 6. Repeat the Review

After fixing, invoke the review subagent again for round `<x+1>`. It writes
`review-<x+1>.md` and appends to `findings-review.md`. Repeat steps 5 → 6 until
the review reports no open findings.

**Commit the review's files after every round — including a clean one.** The
review subagent writes `review-<x>.md` and appends to `findings-review.md` but
never commits (it is read-only by contract). After a round with fixes, step 5's
`git add -A` sweeps those files up; after a **clean** round there is no fix
commit, so the main session must commit them itself — otherwise the round's
record silently never makes it into the merged PR:

```bash
# after EVERY round, clean or not — before moving on to linting (the
# files live in the working tree until committed)
git add "docs/proof_of_work/${NN}-${SLUG}/"
git commit -m "docs: record review round <x> for #<NN>"
```

`main` is protected (see step 12), so uncommitted review files would have to be
carried to `main` through a second PR. Uncommitted review files are not proof
of work yet.

Four rounds is a lot. A loop that has not converged by then usually needs a
decision rather than another iteration — narrow the issue and file the rest
separately, throw the approach away and re-plan, or ask the user. Say which one
you chose, in the last `code-decision-<x>.md`.

> **Never lower a gate to reach a clean round.** Disabling a linter rule or
> relaxing the PHPStan level to make a round look clean is forbidden outright.
> Ask the user instead.

---

## 7. Run Linters and Tests Locally

Before opening a PR, verify that all linters and tests pass on your machine:

```bash
# Run all linters (PHPCS PSR-12 + Rector dry-run + PHPStan level 9)
composer lint

# Auto-fix fixable issues (Rector + phpcbf)
composer lint:fix

# Run unit tests (pure PHP, no broker needed)
composer test:unit

# Run E2E tests — boots RabbitMQ via docker compose on port 5552
./run-e2e.sh
# (equivalent to: composer test:e2e with RABBITMQ_HOST/RABBITMQ_PORT set)
```

> **Note:** This project has **no coverage gate** (unlike workerman-bundle's
> 80% floor). There is no `composer test:coverage` / `composer coverage:check`.
> Do not invent one — if coverage matters for a PR, run PHPUnit with PCOV/Xdebug
> locally and read the report, but it is not a merge gate.
>
> **Note:** `./run-e2e.sh` boots RabbitMQ via `docker compose` binding port 5552
> (stream) and 15672 (management). If you see "Address already in use" errors,
> ensure port 5552 is free. The script traps `EXIT` and runs `docker compose down`
> automatically, but if tests were interrupted you may need:
> `docker compose down`.
>
> The pre-push hook (see step 12 "Notes") runs `composer lint` before every
> push — so `composer lint` here also runs in CI and on push. If it is not
> installed yet, run `bash bin/install-hooks.sh`.

After `composer lint:fix`, commit any fixes:

```bash
git add -A
git commit -m "style: auto-fix lint issues"
```

**Only open the pull request (step 9) when all lints and tests pass locally.**

---

## 8. Update CHANGELOG.md

```bash
# Edit CHANGELOG.md:
# - Add entry under [Unreleased] section
# - Follow Keep a Changelog format (https://keepachangelog.com/en/1.0.0/)
# - Use the appropriate section: Added, Changed, Fixed, Removed, Deprecated
# - Include issue number, e.g. (#437)
# - For a NEW public API method, list it under Added explicitly
# - For a behaviour change, list it under Changed with upgrade notes
```

> Per AGENTS.md "After Merging a Feature Branch": the README Protocol
> Implementation Status table also needs a `❌`→`✅` flip when an issue
> implements a protocol command. Do that edit here too, in the same commit, if
> applicable.

---

## 9. Open the Pull Request

The PR is created here — **after** implementation and after step 7's linters
and tests pass locally. There is nothing for a PR to converge (the diff, CI
status, the review conversation) before there is content: a PR opened on a
branch with no new commits runs CI on nothing. The issue is linked from the
first push regardless: `closingIssuesReferences` comes from the `Closes #<NN>`
line in the body, not from when the PR was opened:

```bash
gh pr create \
  --title "feat(<scope>): <short description> (closes #<NN>)" \
  --body "## Description
Closes #<NN>

## Changes
- ...

## Changelog
See CHANGELOG.md `[Unreleased]`.

## Proof of Work
\`docs/proof_of_work/<NN>-<slug>/\` — <N> review round(s)

## Code Review
- [ ] Passed subagent code review
- [ ] Every finding answered" \
  --base main --assignee @me
```

The PR is created ready — the review rounds (steps 4-6) already happened on the
branch itself, and CI runs on the PR from its first push.

> **Note:** If you don't use `gh`, create the PR manually via GitHub UI.

---

## 10. Wait for CI

```bash
# Check PR status
gh pr view --json statusCheckRollup

# Wait for all checks to finish
gh pr checks --watch
```

The CI workflow (`.github/workflows/ci.yml`) triggers on pull requests to
`main`. It has a `check-actor` gate (only the repo owner or
admin/maintain/write collaborators run the real jobs; everyone else is skipped),
then:

1. **lint** — `composer cs` (PHPCS PSR-12), `composer rector` (dry-run),
   `composer phpstan` (level 9).
2. **unit-tests** matrix — PHP **8.1, 8.2, 8.3, 8.4**; `./vendor/bin/phpunit
   --testsuite unit`. `needs: lint`.
3. **e2e-tests** — boots `rabbitmq:4-management`, enables the
   `rabbitmq_stream` plugin, creates a stream fixture, runs
   `--testsuite e2e`. `needs: lint`.

There is **no `concurrency: cancel-in-progress`** on this workflow, so pushing
again to the same PR starts a second full run rather than cancelling the first
— be deliberate about pushes.

---

## 11. Handle CI Failures

If CI fails:

```bash
# 1. See which checks failed
gh pr checks

# 2. View logs
gh run view --log --job <job-id>

# 3. Fix the issues locally
# 4. Run code review via subagent again (repeat steps 4-6)
# 5. Commit the fixes
git add -A
git commit -m "fix(<scope>): <what was fixed>"
git push origin "feature/issue-${NN}-${SLUG}"

# 6. Wait for CI to re-run
gh pr checks --watch
```

> **Note:** The pre-push hook (installed via `bash bin/install-hooks.sh`)
> runs `composer lint` before every push. To skip it in emergencies:
> `git push --no-verify` — CI runs the same checks, so skipping locally just
> moves the failure later.

**Repeat until all CI checks pass.**

> **A CI failure is an escaped defect.** Record it in `findings-review.md`
> like any other finding before fixing it — round 1 should have caught it and
> did not, which is usually a missing check rather than bad luck.

---

## 12. Merge PR and Close Issue

```bash
# Merge PR (squash merge recommended for clean history)
gh pr merge --squash --delete-branch

# Close the issue (automatic if commit contains "closes #<NN>")
# Alternatively: gh issue close <NN>
```

> **Note:** `main` is protected by a **branch protection rule** (not a
> ruleset like workerman-bundle). It requires **strict** status checks — the
> branch must be up to date with `main` — and these required checks must be
> green:
> `lint`, `unit-tests (PHP 8.1)`, `unit-tests (PHP 8.2)`,
> `unit-tests (PHP 8.3)`, `unit-tests (PHP 8.4)`, `e2e-tests`.
> Direct pushes to `main` are blocked; every change comes through a PR with
> green CI. There is **no required review** (single-maintainer project —
> GitHub won't let you approve your own PR), so the actual gates are CI + your
> own decision to merge.

---

## 13. Switch Back to main

```bash
git checkout main
git pull origin main
```

---

## 14. Report Implementation Problems and Offer a GitHub Issue

At the end of the workflow, present the findings collected during the cycle and
decide with the user whether they deserve a dedicated GitHub issue. They are
already written down — read them out of
`docs/proof_of_work/<NN>-<slug>/findings-coder.md` and `findings-review.md`
rather than out of the chat log, which may since have been compacted.

**Display to the user:**

1. **Biggest problem(s) faced during implementation** — as reported by the
   worker/coder subagent in step 3.
2. **Discovered bugs / places to improve** — each with file/line, short
   description, and suggested fix (including findings outside the scope of the
   issue just closed).

**Verify each candidate finding with a review subagent (read-only) before
offering or creating an issue.** For every candidate finding the subagent must
confirm:

1. **The finding is real** — read the cited file/line(s) on the current branch
   and confirm the behavior actually occurs and is reachable; check whether it
   is by-design and already documented (those are skipped, not filed).
2. **No similar issue exists on GitHub** — search open *and* closed issues.
   `gh issue list` returns at most 30 issues by default, so always pass an
   explicit limit:

   ```bash
   gh issue list --state open   --limit 150 --json number,title,labels,body
   gh issue list --state closed --limit 150 --json number,title,labels
   gh search issues --repo crazy-goat/rabbit-stream --state open --limit 50 "<keyword>"
   ```

   Same or overlapping scope counts as tracked; known related issues (e.g.
   referenced from CHANGELOG entries) must be checked explicitly.
3. **A recommendation per finding**: (a) create a new issue — with proposed
   title and labels per the project's conventions (`bug` / `enhancement` /
   `documentation` / `performance` / `security` / `priority:low|medium|high` /
   `good first issue`), (b) skip — already tracked (cite the issue number), or
   (c) skip — not real or by-design and documented.

The verification subagent must not modify files and must not create/close/edit
issues itself. It reads AGENTS.md first and writes nothing there. Only findings
that pass verification (real + untracked) are offered to the user / created.

**Then ask:** "Create GitHub issue(s) for these findings?"

- If yes, create an issue via `gh` (adjust labels to the project's
  conventions):

  ```bash
  gh issue create \
    --title "<title>" \
    --body "## Description
  <what is wrong>

  ## Where
  - `src/...:NN`

  ## Suggested fix
  <one paragraph>" \
    --label bug
  ```

  Assign `--label bug` for confirmed bugs, `enhancement` or `performance` for
  improvement candidates, `documentation` for doc issues. One issue per
  distinct finding keeps them actionable. Consider a milestone
  (`--milestone v1.4.0`) when the finding fits a planned release.

- If the user declines or the findings are already tracked, just record the
  outcome and finish.

> **Note:** findings that were already fixed as part of this workflow do not
> need an issue — only newly discovered, still-open problems should be
> reported.

---

## After Merging a Feature Branch

Per AGENTS.md, after **every** merge to `main`:

1. **Close the GitHub issue** — e.g. `gh issue close 21`
2. **Update `README.md`** — change `❌` to `✅` in the Protocol Implementation
   Status table (only when the issue implemented a protocol command)
3. **Update `CHANGELOG.md`** — move items from `[Unreleased]` if releasing,
   or add to it (step 8 already added the entry; on a release, move the whole
   `[Unreleased]` block under a new `[1.x.0] - YYYY-MM-DD` heading)
4. Commit directly to `main` with a message like:
   ```
   docs: mark Subscribe as implemented in README, close issue #21
   ```

> **Milestone release gate:** when the lowest open milestone has **0 open
> issues**, stop picking — cut the release (tag `v1.x.0`, publish, close the
> milestone) before resuming. There is no `pick-issue.php` to enforce this; the
> triage subagent must check `gh api repos/crazy-goat/rabbit-stream/milestones
> --jq '.[] | {title,open_issues,closed_issues}'` and report when a milestone
> is empty.

---

## Proof of Work (docs/proof_of_work/)

Every cycle leaves four kinds of file behind, in
`docs/proof_of_work/<NN>-<slug>/`:

| File | Written by | What goes in it |
| --- | --- | --- |
| `findings-coder.md` | the coder, appended across rounds | obstacles, surprises, bugs noticed in passing — including ones outside this issue's scope |
| `findings-review.md` | the review, appended across rounds | one entry per finding: `file:line`, what is wrong, severity, what happened to it |
| `code-decision-<x>.md` | the coder, one per round | the approach taken in round `<x>`, what was rejected, what was uncertain |
| `review-<x>.md` | the review, one per round | the review output for round `<x>` |

`<x>` is the round of the inner loop, starting at 1. Six files means three
rounds, and three rounds means something was hard — which is most of what a
reader wants to know at a glance.

The two `findings-*` files are separate because the two roles disagree, and a
shared file turns disagreement into an edit war. Keeping them apart lets the
review say "still present" about something the coder called fixed, with both
statements surviving in the record.

Nothing validates these files. There is no schema, no manifest and no CI gate —
a reader checks them during review, the same way they check the code.

---

## Knowledge Base (docs/helpers/)

A persistent, **single-writer** knowledge base so lessons learned carry over
to future tasks. Same shape as the workerman-bundle one, now ported to this
project:

- `docs/helpers/faq.md` — recurring pitfalls and their solutions (RabbitMQ
  Stream plugin / E2E broker ports, the server-push-frame key rule, `gh`
  default limits, `socket_select` loop gotchas). Ids `FAQ-NNN`.
- `docs/helpers/decisions.md` — project decisions with rationale (the `V1`
  suffix convention, the custom exception hierarchy from #242, the
  `feature/issue-NNN-*` branch convention, the pre-push lint gate). Ids
  `DEC-NNN`.
- `docs/helpers/README.md` — entry format, single-writer rule, decay rules.

Both files are linted by `bin/kb-lint.php`, which runs inside `composer lint`.

**Read the index, not the file.** Every file opens with a generated **tag
index** (between `<!-- kb-index:start -->` and `<!-- kb-index:end -->`)
mapping tags to entry ids. A subagent loads the index, picks the tags
matching the files in its diff, and reads only those `###` entries. Reading
300 lines of FAQ for a two-file change is exactly what the index exists to
prevent. Regenerate it with `php bin/kb-lint.php --fix` (or
`composer kb-lint:fix`); `composer lint` fails when it is out of sync.

**One writer.** Only the **main session**, at the end of the cycle (step 14),
writes to `docs/helpers/`. `coder`/`coder-high` and `review`/`review-critical`
**propose** candidate entries in their report — title, tags, trigger, one
paragraph — and the main session decides what lands. Two writers produced
duplicates, unlabelled entries and a file that had to be read in full; a
subagent that appends to the knowledge base itself is doing the wrong thing.

**Prefer a gate over an entry.** If a regression test, PHPStan rule, or
PHPCS rule could catch the class of defect, add the check. The knowledge base
is a buffer for what cannot be automated yet, not a destination. PHPStan runs
at **level 9** here — a missing type or unreachable branch is usually a
static-analysis catch, not a knowledge-base entry.

Every entry carries single-line front matter (`id`, `date`, `tags`,
`trigger`, `hits`, `status`, optional `gate`) in an HTML comment right after
its heading. `bin/kb-lint.php` validates it, regenerates the tag index, warns
above 300 lines per file, and lists `stale` entries (0 hits in 20 cycles).
Full reference: [docs/helpers/README.md](helpers/README.md) and
[bin/README.md](../bin/README.md#kb-lintphp).

---

## Agent Map

Which agent runs at which step. These are **role names, not a harness**: the
workflow assumes you can start a subagent with its own context and give it a
scoped instruction, and assumes nothing else.

| Step | Agent | Role |
| --- | --- | --- |
| 1 | `delegate` | triage open issues, return a ranked shortlist (milestone-aware) |
| 3 | `coder` / `coder-high` / `worker` | implement; write `code-decision-<x>.md` and `findings-coder.md` |
| 4, 6 | `review` / `review-critical` | code review; write `review-<x>.md` and `findings-review.md` |
| 11 | `delegate` | compress CI logs into actionable failures |
| 14 | `reviewer` | verify candidate findings before opening GitHub issues |

**`review-critical` is mandatory**, not a judgement call, when the diff touches
any of:

- `src/StreamConnection.php` (the `socket_select` loop, server-push dispatch,
  heartbeat echo, `ConsumerUpdate` reply)
- protocol wire-format code — any `*RequestV1` / `*ResponseV1` / `Buffer/` change
  that alters the byte layout
- `ResponseBuilder` dispatch or `KeyEnum` key values
- security-relevant code (raw socket I/O, untrusted server data parsing)
- more than **200 changed lines**
- a public API surface (`Connection`, `Producer`, `Consumer`, `ResponseBuilder`
  public methods)

Otherwise `review` is enough.

---

## Quick Reference – Full Cycle

```bash
# 1. Pick an issue — delegate triage to a subagent ("List top 5 impactful…")
#    Milestone-aware: prefer the lowest open milestone (v1.3.0 < v1.4.0 < v1.5.0).
#    When the lowest milestone is empty → STOP, cut a release, then resume.

# 2. Feature branch — feature/issue-NNN-<slug> (repository convention)
NN=<issue-number>; SLUG=<short-kebab-slug>
BRANCH="feature/issue-${NN}-${SLUG}"
git checkout main && git pull origin main
git checkout -b "$BRANCH"
git push -u origin "$BRANCH"
mkdir -p "docs/proof_of_work/${NN}-${SLUG}"

# 3. Implementation (worker/coder subagent)
#    subagent: "Implement issue #<NN>…"
#    writes code-decision-1.md + findings-coder.md, commits them with the change
#    report must include: files changed, BIGGEST problem, discovered bugs
#    / places to improve (also out of scope)
#    E2E (./run-e2e.sh) only for wire-level changes; otherwise composer test:unit

# 4. Code review (subagent) — reads findings-review.md first, then new issues
#    writes review-1.md + findings-review.md
#    AFTER EVERY ROUND (clean or not): commit the review's files — a clean
#    round has no fix commit to sweep them up, so commit them explicitly:
#    git add docs/proof_of_work/${NN}-${SLUG}/ && git commit -m "docs: record review round <x>"

# 5-6. Fix, answer every finding, re-review (review-2.md, review-3.md, …)
#    a finding that an automated check could have caught: write the check
#    past ~4 rounds, decide instead of iterating — narrow, re-plan, or ask

# 7. Run linters and tests locally
composer lint && composer test:unit
# wire-level change only: ./run-e2e.sh

# 8. Update CHANGELOG.md (+ README status table if a protocol command landed)

# 9. Open the pull request — after implementation and local gates (created ready)
gh pr create --title "feat(<scope>): … (closes #<NN>)" --body "…" --base main --assignee @me

# 10-11. CI (no concurrency cancel — push deliberately)
gh pr checks --watch
# ... if failures → fix, review, push → wait for CI (repeat)

# 12. Merge
gh pr merge --squash --delete-branch

# 13. Switch back to main
git checkout main && git pull origin main

# 14. Report + offer GitHub issue for discovered problems
#    show: biggest problem(s), discovered bugs / places to improve
#    (read them out of findings-coder.md and findings-review.md)
#    verify each candidate with a review subagent (finding is real?
#    no duplicate on GitHub? use --limit >30 in issue lists)
#    then ask: "Create GitHub issue(s)?" → if yes: gh issue create ...
#    labels: bug / enhancement / documentation / performance / security
#            priority:low|medium|high / good first issue
```

---

## Subagent Usage Summary

Most steps of this workflow are delegated to subagents to keep the main
session's context lean.

| Step | Subagent task | Why delegate |
| ---- | ------------------------------------------ | ------------------------------------- |
| 1 | Triage open issues, return ranked shortlist | Issue bodies + comments are token-heavy |
| 3 | Implement the issue (worker/coder) | Coding context is token-heavy; agent returns structured report (files, biggest problem, discovered bugs) |
| 4, 6 | Code review of the implementation diff, `findings-review.md` first | Full diff + surrounding code is token-heavy; the review must revisit every open finding before hunting for new ones |
| 14 | Verify candidate findings before creating GitHub issues (read-only: is the finding real? is it already tracked?) | GitHub duplicate search (open + closed, `--limit` > 30) plus code verification across several findings is query-heavy |

All subagents have read/write/edit/bash tools and operate on the same repository
(the step-14 verifier is instructed to run read-only). Give each one a clear,
scoped instruction and a defined output format (ranked list with rationale /
numbered findings list with `file:line | description | severity` / coder report
with biggest problem + discovered bugs). The coder and the review each write
their own files under `docs/proof_of_work/<NN>-<slug>/` — the main session does
not retype their output into a summary. A report that only exists in chat is gone
the moment the context is compacted.

---

## Notes

- **gh** must be configured and authenticated (`gh auth status`).
- `main` is protected by a **branch protection rule** — every push must come
  through a pull request with the green required checks
  (`lint`, `unit-tests (PHP 8.1)`, `unit-tests (PHP 8.2)`,
  `unit-tests (PHP 8.3)`, `unit-tests (PHP 8.4)`, `e2e-tests`); direct pushes
  are blocked. This is a solo-maintainer project, and GitHub does not allow
  approving your own pull request, so there is still no *review* to require.
  What actually gates a merge: CI (the six required checks) plus the
  maintainer's own decision to merge.
- There **is** a pre-push hook on this project: `.git/hooks/pre-push` runs
  `composer lint` before every push. Install it (and any future hooks) with
  `bash bin/install-hooks.sh` after a fresh clone. Bypass with
  `git push --no-verify` — CI runs the same checks.
- Keep feature branches short-lived. If a rebase is needed:

  ```bash
  git fetch origin main
  git rebase origin/main
  git push --force-with-lease origin "feature/issue-${NN}-${SLUG}"
  ```

- Code review via subagent runs locally. `coder`/`coder-high` are granted
  read/write/edit/bash; `review`, `review-critical`, and `reviewer` are granted
  only read/bash — there is nothing to withhold by instruction, they simply
  cannot write or edit. Give each one clear instructions on what to check
  (see AGENTS.md for the project-specific rules to verify).
- **Lowering a gate is never an option.** Disabling a linter rule or relaxing
  the PHPStan level to make a round look clean is forbidden — a metric improved
  by weakening its own check measures nothing. Ask the user instead.
- `docs/proof_of_work/` and `docs/helpers/` are `export-ignore`d in
  `.gitattributes`, so they are not part of the distributed package.
