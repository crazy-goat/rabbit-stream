# bin/

Repository tooling scripts. None of these ship in the distributed package
(see `.gitattributes`).

## install-hooks.sh

Symlinks the versioned git hooks from `bin/hooks/` into `.git/hooks/`. Git
does not version `.git/hooks/`, so this keeps hooks alongside the code that
depends on them. Re-run after a fresh clone or when a hook is added/renamed.

```bash
bash bin/install-hooks.sh     # or: php bin/install-hooks.sh
```

If a hook already exists in `.git/hooks/` as a real (non-symlink) file, it is
left untouched with a warning — the user may have customized it. Bypass any
hook with `git <cmd> --no-verify`.

## hooks/pre-push

Runs `composer lint` before every push. On failure it prints the log and
exits non-zero, blocking the push. Bypass with `git push --no-verify` — CI
runs the same checks, so skipping locally just moves the failure later.

## kb-lint.php

Lints the subagent knowledge base under `docs/helpers/`:

- every `###` entry must carry single-line HTML-comment front matter
  (`id`, `date`, `tags`, `trigger`, `hits`, `status`, optional `gate`),
- ids are unique across both files and match the file's prefix (`FAQ-NNN` /
  `DEC-NNN`),
- the generated tag index between `<!-- kb-index:start -->` and
  `<!-- kb-index:end -->` is in sync with the entries,
- near-duplicate entries are reported,
- the per-file line budget (300, index excluded) is watched,
- `stale` entries (0 hits in 20 cycles) are listed for the retro to remove.

Runs inside `composer lint` (fails the build when the index is out of sync or
front matter is malformed). Regenerate the index with `composer kb-lint:fix`
or `php bin/kb-lint.php --fix`.

```bash
php bin/kb-lint.php            # check
php bin/kb-lint.php --fix      # regenerate tag indexes
php bin/kb-lint.php --json     # machine-readable output
php bin/kb-lint.php --help
```

Exit codes: `0` clean, `1` lint failure (or `--fix` needed and not given),
`2` usage error. See `docs/helpers/README.md` for the entry format and decay
rules.

## check-test-suites.php

Guards against silent test exclusions (#476): `phpunit --testsuite unit` and
`--testsuite e2e` only discover the files listed under their `<testsuite>` in
`phpunit.xml`, so a `tests/**/*Test.php` file outside every allow-list is
never run by CI — the #459 failure mode (six files, 182 tests, invisible on
green CI).

The script parses `phpunit.xml` and diffs the union of all suites'
`<directory>`/`<file>` entries against the `*Test.php` files present on disk:

- fails if any test file is covered by **no** suite,
- fails the reverse direction too — an allow-list path that no longer exists —
  so a directory rename cannot leave a suite pointing at nothing.

Parsing the config instead of hard-coding paths keeps the gate in sync
automatically. Runs inside `composer lint` and as a step in the CI `lint` job.

Config resolution follows PHPUnit's precedence: `phpunit.xml` wins over
`phpunit.xml.dist`. An explicit argument may name a config file, or a directory
in which the `phpunit.xml` / `phpunit.xml.dist` pair is looked up. Relative
`<directory>`/`<file>` entries and the `tests/` discovery root resolve against
the config's own directory, so the invocation is independent of the current
working directory.

```bash
php bin/check-test-suites.php                  # repo root, phpunit.xml then .dist
php bin/check-test-suites.php path/to/phpunit.xml
php bin/check-test-suites.php path/to/config-dir
```

Exit codes: `0` clean, `1` uncovered test file(s) or stale allow-list
entry(ies), `2` usage/config error.
