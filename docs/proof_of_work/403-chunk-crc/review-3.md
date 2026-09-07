# #403 Chunk CRC — Review Round 3 (confirmation round)

Date: 2026-09-07. Scope: verify round-2 responses, then check commits `e22b2da..73efbb6` for regressions.

## Round-2 finding verification

### Finding 5 — tracked `.DS_Store` in the diff — **FIXED, response accurate**

Evidence:

- `git ls-files | grep -i ds_store` returns nothing — the file is no longer tracked.
- Commit `73efbb6` ("chore: remove tracked .DS_Store") deletes it from the index exactly as prescribed.
- `git diff main...HEAD --stat` shows `.DS_Store | Bin 6148 -> 0 bytes` — the only `.DS_Store` trace in the diff is its removal relative to `main` (the file was already tracked on `main`); it is not being modified/re-added by this branch.
- The file still exists in the working tree (6148 bytes) but `.gitignore:12` covers it, and `git status --short` is clean — it cannot sneak back into a commit.

### Finding 6 — `crc32()` signed-int nit on 32-bit PHP — **NOT A REAL FINDING, rationale defensible**

Evidence from the actual code:

- `src/Client/OsirisChunkParser.php:375` compares `crc32(substr(...))` against the header uint32. On a 32-bit PHP build `crc32()` could indeed return a value above `PHP_INT_MAX` as a float / behave as signed, making `!==` unreliable.
- However, `src/Buffer/ReadBuffer.php:84-88` (docblock on `getUint32()`) and lines 100-104 (`getUint64()`) document that the constructor **rejects 32-bit platforms outright (#458)** — `unpack('N')` would return a float for large uint32s. The platform gate lives in `src/Platform.php:36` (`PHP_INT_SIZE >= 8` check).
- Since the library cannot even construct a ReadBuffer on 32-bit PHP, the parser's CRC comparison can never execute there. The nit is purely theoretical on supported targets. No action required.

## New issues in `e22b2da..73efbb6`

The range contains exactly three commits: `15b5bd5` (docs: review round 2 records), `73efbb6` (.DS_Store removal), and the two files they touch — no source changes beyond the index deletion. Nothing to regress.

Automated checks in the worktree, all green:

- `composer cs` — OK (273/273, PHPCS clean)
- `composer phpstan` — `[OK] No errors`
- `./vendor/bin/phpunit --testsuite unit` — **OK (1070 tests, 8190 assertions)**

## Verdict: **clean**

Both round-2 responses verified accurate; finding 5 fixed, finding 6 correctly closed as not-a-real-finding. No new issues. The branch is ready to merge.
