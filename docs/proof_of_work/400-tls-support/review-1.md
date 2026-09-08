# Code Review Round 1 — Issue #400 (TLS support)

Branch `feature/issue-400-tls-support` vs `origin/main`. Commit `8992ecd`.

## Scope reviewed

- `src/StreamConnection.php` — socket→stream refactor (full I/O path rewrite)
- `src/VO/TlsConfig.php` — new VO
- `src/Client/Connection.php` — new `tls:` parameter on `Connection::create()`
- `README.md`, tests

## Local QA (all green)

- `composer cs` — clean
- `composer phpstan` (level 9) — clean
- `composer rector` (dry-run) — clean
- `./vendor/bin/phpunit --testsuite unit` — 1074 tests, 8197 assertions, OK

## Overall assessment

The refactor is well-motivated and well-documented: TLS in PHP is only reachable
via stream transports, so a single `stream_socket_client()` I/O path is the right
call. The non-blocking + `stream_select()`-bounded design preserves the #402
guarantee structurally, and the frame-boundary vs mid-frame timeout semantics
from #389/#390 are faithfully ported (`writeTimeout()` / `readTimeout()` helpers
match the old behavior, including `TimeoutException` when nothing was sent and
`close()` + `ConnectionException` on a partial frame).

`Connection::create()` BC is preserved (new trailing nullable parameter with
default). `TlsConfig` has secure defaults (`verify_peer`/`verify_peer_name` true,
nothing settable that silently downgrades security).

The findings below are mostly robustness/documentation issues; one medium
(handshake not bounded by the configured timeout) and several low items.

## What I verified in detail

- **Wire format**: untouched; framing code (`readFrame`, caps, key parsing) is
  unchanged aside from the transport call swaps. Protocol correctness not at risk.
- **#389**: `writeTimeout()` — `TimeoutException` only when `$sent === 0`,
  otherwise `close()` + `ConnectionException`. Matches old semantics.
- **#390**: `readTimeout()` — returns "retry-able" only at a frame boundary
  (`$data === '' && !$mustComplete`); mid-frame closes the connection. Matches.
- **#391** (nested request / parked correlations): dispatch code untouched.
- **#402**: every blocking call is now either `stream_select()` bounded by
  `$socketTimeout` or a non-blocking `fread`/`fwrite` after a ready state —
  except the TLS handshake (see finding 1).
- **Timeout deadline math**: `readBytes()` re-checks `$remainingTime <= 0` at the
  top of every iteration including after an empty-read `continue` — no unbounded
  spin. `writeAll()` likewise.
- **`isConnected()`**: the old `socket_last_error()` fatal-error probe is gone;
  a stream equivalent doesn't exist, so downgrade to resource-validity is
  acceptable (see finding 6).

## Findings

1. **HIGH→MEDIUM (accepted as medium after checking)** — TLS handshake duration
   is not bounded by `socketTimeout`. `stream_socket_enable_crypto()` is called
   while the stream is still blocking, so a stalled broker can hang the client
   up to `default_socket_timeout` (INI, typically 60s) instead of the configured
   per-call timeout. Weakens the #402 guarantee on the ssl:// path.

2. **LOW** — TLS handshake failure reason is swallowed: `@`-suppressed call, and
   the exception message does not include any OpenSSL error detail.

3. **LOW** — `fwrite() === false || 0` after a writable select is treated as
   "peer closed". On `ssl://` a false/0 can also occur with a pending
   `STREAM_..._WOULD_BLOCK`/renegotiation state; the message would mislead.
   (Low: on a non-blocking plaintext stream this is genuinely fatal.)

4. **LOW** — `stream_select() === false` now throws immediately; the old
   `socket_write`/`socket_recv` paths retried `EINTR`. If the process uses
   `pcntl` signal handlers, select can return false on `EINTR` and abort an
   otherwise-healthy connection.

5. **LOW** — FQCN used inline in `connect()` (`\CrazyGoat\RabbitStream\VO\TlsConfig`)
   despite the class already being imported — violates the AGENTS.md import rule.

6. **LOW** — `isConnected()` lost its "fatal error state" detection (there is no
   stream equivalent of `socket_last_error()`); documented behavior comment still
   claims it ("no fatal error state"). Behavior change worth a docblock note.

7. **LOW** — `readBytes()` docblock is stale/wrong: `@return string` but signature
   is `?string`; prose still references `stream_set_timeout`, which this diff
   removes/never uses.

8. **NIT** — `isConnected()` has two stacked docblocks (leftover from the edit).

9. **LOW (test gap)** — no test for the TLS scheme selection (`ssl://` URL),
   handshake-failure path, or `stream_socket_enable_crypto` failure cleanup
   (`fclose`). `TlsConfigTest` covers only `toStreamContext()`. Also no E2E TLS
   job against a broker with the stream TLS listener (README advertises port
   5551 but nothing exercises it).

10. **NIT** — `connect()` reports `stream_socket_client` failure as
    "Cannot connect to ..."; for TLS the real cause (e.g. bad cafile path) is in
    `$errorMessage`, which is included — fine — but the error code/int framing
    differs from the old message shape; purely cosmetic.

11. **LOW** — heartbeat echo (writeAll path) is now bounded by a fresh
    `$this->socketTimeout` deadline — correct — but note it uses the *current*
    property, not the per-call `$timeout` argument the caller passed to
    `sendMessage(..., timeout:)`; the pre-select wait honors `$timeout` but the
    actual write loop does not. Same as old behavior (SO_SNDTIMEO), so not a
    regression — flagging as a known asymmetry only.

Conclusion: **approve with changes** — fix 1, 2, 5, 7 before merge; the rest are
follow-ups.
