# Release workflow

One milestone = one release. Versions follow
[Semantic Versioning](https://semver.org/) and the changelog follows
[Keep a Changelog](https://keepachangelog.com/). Tags are `vX.Y.Z`.

Everything is written in **English**, including release notes.

## 1. Release gate

A release is ready when the milestone has **no open issues**:

```bash
gh api repos/{owner}/{repo}/milestones --jq '.[] | select(.title=="vX.Y.Z") | {title, open_issues, closed_issues}'
```

- Open issues that will not make it: move them to the next milestone
  (`gh issue edit <N> --milestone vX.Y.(Z+1)`).
- The default branch must have a green `ci-ok`.

## 2. Choose the version

| Change | Bump |
|---|---|
| Bug fixes only | patch (`1.2.3` → `1.2.4`) |
| New, backward compatible features | minor (`1.2.3` → `1.3.0`) |
| Breaking changes | major (`1.2.3` → `2.0.0`) |

Before `1.0.0`, breaking changes bump the minor version. The milestone title
already holds the planned version. Change the milestone title if the plan changed.

### Intended API breaks

The CI step `api-signature` (`bin/check-api-signature.php`) compares `src/` with the newest
`vX.Y.Z` tag. It fails when a public member is removed or changed. It fails when a new abstract
or interface method is added to a type that exists in the tag. It also checks protected members
of non-final classes, because subclasses use them.

A constructor is an exception. The check passes when a constructor gains optional parameters at
the end. Each new parameter must have a default value. The check fails when a constructor
parameter changes its name, type or default value, or when a required parameter is added.
Other methods keep the strict rule. An optional parameter added to a normal method is a break.

To accept an intended break:

1. Run `php bin/check-api-signature.php`. Copy the key from the `BREAK` line. The key starts
   with `M|`, `P|`, `C|`, `E|`, `T|` or `I|`. The line shows `removed`, `changed` or
   `added required member`. For example: `M|CrazyGoat\RabbitStream\Client\Producer::send`.
2. Add a line to `api-breaks.txt` in the repository root. Write the key, a space and the reason,
   with the issue number.
3. Commit the line in the same pull request as the break.

The check prints `WARNING stale allow-list entry` when an entry no longer matches a break.
Remove that entry.

Each release starts with an empty allow-list. Remove all entries in the release pull request,
before the tag is created.

Keep `api-breaks.txt` empty on the `1.x` line. The `1.x` line takes no API breaks. The `main`
line can use the file for 2.0.0.

## 3. Prepare the CHANGELOG (pull request)

```bash
git switch -c chore/release-vX.Y.Z
```

In `CHANGELOG.md`:

- Rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`.
- Add a fresh empty `## [Unreleased]` above it.
- Group entries under Added, Changed, Deprecated, Removed, Fixed, Security.
- Update the compare links at the bottom, if the file has them.
- Bump the version in files that carry it (`composer.json` does not need it, but
  `package.json`, `version.go`, extension headers do). See `AGENTS.md`.

Open a PR titled `chore: release vX.Y.Z`, wait for `ci-ok`, squash merge.

rabbit-stream carries no version number in source files (`composer.json` has no `version`;
Packagist reads the tag). The `CHANGELOG.md` section is the only thing to prepare.

## 4. Tag

Tag the merge commit on the default branch with an **annotated** tag:

```bash
git switch <default-branch> && git pull --ff-only
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin vX.Y.Z
```

## 5. GitHub Release

Pushing the tag starts `.github/workflows/release.yml`, which calls the shared
workflow from `crazy-goat/.github`. It reads `CHANGELOG.md` from the **tagged commit**,
so the release PR with the `## [X.Y.Z]` section must be **merged before** you tag. It creates the GitHub Release with the notes
from the matching `CHANGELOG.md` section, and fails when the section is missing.

Repositories that build binaries or images run their build first. Only the last
step (creating the release) is shared.

```bash
gh run watch
gh release view vX.Y.Z
```

## 6. Close the milestone

```bash
gh api -X PATCH repos/{owner}/{repo}/milestones/<number> -f state=closed
```

Make sure the next milestone `vX.Y.(Z+1)` (or the next minor) exists.

## 7. After the release

- Check that install instructions work with the new version (Packagist, Go proxy, ...).
- If something is wrong, do not move the tag. Fix forward with a patch release.

## Checklist

- [ ] Milestone has no open issues, CI is green
- [ ] CHANGELOG section `[X.Y.Z] - date` written, `[Unreleased]` is empty
- [ ] Release PR merged
- [ ] Annotated tag `vX.Y.Z` pushed
- [ ] GitHub Release exists with the CHANGELOG notes
- [ ] Milestone closed, next milestone exists
