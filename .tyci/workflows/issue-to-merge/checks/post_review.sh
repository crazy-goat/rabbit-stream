#!/usr/bin/env bash
# post_review.sh: posts the newest review report as one PR review (state post_review).
# The reviewer agent stays read-only; this script does the only write to GitHub.
# Every post starts with the marker line that fetch_comments.sh uses to
# recognise the comments of the agent itself.
#
# Env in:  TYCI_PR, TYCI_REPO, TYCI_REVIEW_DIR (the artifact dir of the newest review
#          step, set by the runner). The review is report.md in that dir.
# The report must match the format of prompts/review.md: line 1 is ACCEPT or CHANGES, and line 2 is
# "Reviewed: <40 lower-case hex digits>". The sha must be HEAD, or an ancestor of HEAD that only
# merge commits follow. The update step (rebase.sh) merges origin/<default> before this check, so
# HEAD can be a merge commit above the reviewed commit. A bad report is not posted, because the
# next review round takes its base from the Reviewed: line.
# Keys:    ok    the review was posted
#          skip  the run has no review step (it continued an open PR without a new review)
#          fail  no PR, an empty or malformed review report, or gh failed (the runner records it, the run goes on)
# Always exits 0.
set -euo pipefail
# describe.sh prints the failure block (see there). A copy of this script without it still works.
describe_lib="$(dirname "${BASH_SOURCE[0]}")/describe.sh"
# shellcheck disable=SC1090 # sibling file; shellcheck checks it on its own
if [ -f "$describe_lib" ]; then . "$describe_lib"; else describe() { echo "$(basename "$0"): $2" >&2; }; fi

pr="${TYCI_PR:-}"
review_dir="${TYCI_REVIEW_DIR:-}"
if [ -z "$review_dir" ]; then
    echo "post_review.sh: this run has no review step (it continued an open PR of an earlier run); nothing to post" >&2
    echo skip
    exit 0
fi
review="$review_dir/report.md"
if [ -z "$pr" ] || [ ! -s "$review" ]; then
    describe fail "no PR number (TYCI_PR='$pr') or an empty review report ($review)" \
        "the reviewer wrote an empty report.md, or the run lost its PR number" \
        "nothing to do: the run records a warning and goes on to ci"
    echo fail
    exit 0
fi

# Line 1 and line 2 of review.md. A missing line is an empty string and fails the match.
line1=$(sed -n 1p "$review")
line2=$(sed -n 2p "$review")
reviewed=${line2#Reviewed: }
head_sha=$(git rev-parse HEAD 2>/dev/null || echo unknown)
if [ "$line1" != ACCEPT ] && [ "$line1" != CHANGES ]; then
    bad_format=1
elif ! grep -Eq '^Reviewed: [0-9a-f]{40}$' <<<"$line2"; then
    bad_format=1
elif ! git merge-base --is-ancestor "$reviewed" HEAD 2>/dev/null ||
    [ -n "$(git rev-list --first-parent --no-merges "$reviewed..HEAD")" ]; then
    bad_format=1
else
    bad_format=0
fi
if [ "$bad_format" -eq 1 ]; then
    describe fail "report.md format is wrong: line 1 must be ACCEPT or CHANGES, and line 2 must be 'Reviewed: <40 lower-case hex digits>' with the sha of HEAD ($head_sha) or of an ancestor that only merge commits follow" \
        "the reviewer did not follow prompts/review.md lines 11-12, or the reviewed commit is not on the branch" \
        "nothing to do: the review is not posted, the run records a warning and goes on to ci" \
        "line 1 = '$line1'; line 2 = '$line2'"
    echo fail
    exit 0
fi

body=$(mktemp)
trap 'rm -f "$body"' EXIT
{
    echo "<!-- tyci-agent -->"
    cat "$review"
} >"$body"

if gh pr review "$pr" -R "${TYCI_REPO:-}" --comment --body-file "$body" >&2; then
    echo ok
else
    describe fail "gh pr review $pr --comment failed (see the gh output above); the review was not posted" \
        "no network, gh is not logged in, or no permission to comment" \
        "nothing to do: the run records a warning and goes on to ci"
    echo fail
fi
