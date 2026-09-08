# Review round 2 — Issue #400 TLS support (branch `feature/issue-400-tls-support`)

Scope: verify that fix commit `ca42b89` ("fix(connection): address round-1 review findings")
resolves the round-1 findings, then look for new issues introduced by the fixes.
Read-only review; nothing was changed or committed.

## Verification of fix commit `ca42b89`

Diff vs. `8992ecd` touches `src/StreamConnection.php` (+142/−29), `tests/StreamConnectionTest.php` (+32),
`CHANGELOG.md` (+3), plus the round-1 review docs. All five claimed areas are present in the code.

## Round-1 finding statuses (verified)

| # | Round-1 finding | Round-2 status | Evidence |
|---|-----------------|----------------|----------|
| 1 | Unbounded TLS handshake on blocking stream (medium) | **fixed** | `connect()` now sets non-blocking *before* `stream_socket_enable_crypto()` (the old inline handshake block was removed) and delegates to the new `enableCrypto()` (src/StreamConnection.php:206-266). The handshake loop uses an absolute `$deadline = microtime(true) + $this->socketTimeout`, retries while `stream_socket_enable_crypto()` reports no OpenSSL error (would-block), waits via `stream_select()`, and throws `ConnectionException("TLS handshake with host:port timed out after Ns")` with `fclose($stream)` on deadline. Bounded handshake confirmed. |
| 2 | No OpenSSL error detail on handshake failure | **fixed** | `lastOpenSslError()` (src/StreamConnection.php:271-281) drains `openssl_error_string()` and both the failure and timeout paths include it in the `ConnectionException` message. |
| 3 | Misleading "peer closed" message on fwrite false/0 | **fixed** | `writeAll()` (src/StreamConnection.php:742-746) now mentions the possible ssl:// would-block/renegotiation state and points at `stream_get_meta_data()`. |
| 4 | No EINTR retry after `stream_select() === false` | **fixed** | New `selectWasInterrupted()` helper (src/StreamConnection.php:286-292) checks `error_get_last()` for "interrupted system call"; both `writeAll()` (line 727) and `readBytes()` (line 1378) `continue` on EINTR instead of throwing. All loops use absolute deadlines (lines 655, 706, 1357), so a retry does not extend the timeout. |
| 5 | Inline FQCN `\CrazyGoat\RabbitStream\VO\TlsConfig` | **fixed** | Replaced by `$useTls = $this->tls instanceof TlsConfig;` (src/StreamConnection.php:171); import already present. |
| 6 | Stale `isConnected()` docblock | **fixed** | Single docblock now documents the resource-validity-only contract and notes a dead peer surfaces on the next read/write. |
| 7 | `readBytes()` docblock: `@return string` vs `?string`, stale `stream_set_timeout` | **fixed** | `@return ?string` and the prose now describes the `$socketTimeout`-bounded `stream_select()` mechanism. |
| 8 | Duplicated docblock on `isConnected()` | **fixed** | Merged into one docblock (removed in the same hunk as #6). |
| 9 | No scheme-selection tests; no TLS E2E | **fixed (partially, accepted)** | `testConnectUsesTcpSchemeWithoutTlsConfig` / `testConnectUsesSslSchemeWithTlsConfig` added and passing — the error message from a closed port reveals the scheme. TLS E2E remains open as a follow-up (no TLS-capable Docker infra); acceptable as documented. |
| 10 | CHANGELOG note on changed connect-timeout semantics | **fixed** | `### Changed` entry in `[Unreleased]` documents the TLS transport, the streams move, and the now-tighter connect/handshake timeouts. |
| 11 | `$timeout` arg vs `$socketTimeout` asymmetry in `writeAll()` | **not a real finding (acknowledged)** | Pre-existing, documented behavior; no action required per round 1. |

## QA run (all green)

- `composer cs` — PHPCS: 275 files, no violations.
- `composer phpstan` — PHPStan level 9: 0 errors (269/269 files).
- `composer rector` (dry-run) — no suggestions.
- `./vendor/bin/phpunit --testsuite unit` — OK, 1076 tests, 8199 assertions (includes the two new scheme-selection tests).

## New issues introduced by the fixes

Only one minor, non-blocking observation:

1. **`selectWasInterrupted()` relies on `error_get_last()`, which can be stale** (low/nit) —
   src/StreamConnection.php:286-292. `error_get_last()` is global state; a *stale*
   "interrupted system call" warning from an earlier suppressed call could cause one
   extra `continue` iteration after a genuine select failure. In practice a genuine
   select failure raises its own warning ("unable to select…"), which overwrites the
   stale entry, so the misread window is effectively nil — and one extra loop
   iteration still respects the absolute deadline. No action required; noting for
   awareness.

Nothing else new: the `enableCrypto()` would-block heuristic (false with an empty
OpenSSL queue → retry) matches how PHP streams report would-block, both failure
paths `fclose($stream)` before throwing (no resource leak), and the microseconds
arithmetic in the handshake's `stream_select()` call is correct.

## Verdict: **clean** (approve)

All nine actionable round-1 findings verified as fixed (one partially, with a
documented follow-up for TLS E2E). QA suite fully green. The single new
observation is informational and requires no change.
