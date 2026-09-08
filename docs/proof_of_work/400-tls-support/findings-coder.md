# #400 TLS support — coder findings

## Obstacles

1. **`StreamConnection` was built entirely on ext-sockets.**
   `src/StreamConnection.php` (all I/O: `readBytes()`, `writeAll()`, `readFrame()`,
   `readLoop()`, `sendFrame()`) used `\Socket` + `socket_recv(MSG_WAITALL)` /
   `socket_write()` / `socket_select()` / SO_RCVTIMEO/SO_SNDTIMEO. TLS in PHP
   exists only for stream resources, so every one of those call sites had to
   change — see `code-decision-1.md` for the rejected alternatives.

2. **Blocking `fwrite()` ignores `stream_set_timeout()`.**
   First implementation used blocking streams with `stream_set_timeout()`.
   `tests/StreamConnectionTest.php::testFrameThatCannotBeFullyWrittenClosesTheConnection`
   (the #389 test: 1 MB frame into a 4 KB SNDBUF socketpair) hung forever — the
   write never hit the timeout. Fixed by keeping the stream non-blocking and
   driving writes with `stream_select()` + a deadline (`StreamConnection::writeAll()`).
   Cost: SO_RCVTIMEO-style per-call timeout became an explicit deadline loop.

3. **Unit tests injected a `\Socket` via reflection.**
   `tests/StreamConnectionTest.php::injectSocket()` reflected on the `socket`
   property; it now injects a stream into `stream` (and switches it to
   non-blocking, since `connect()` is bypassed). The helper pair switched from
   `socket_create_pair()` to `stream_socket_pair()`; `socket_write/read/close`
   became `fwrite/fread/fclose`. One test (`testTransientSocketError…`) relied
   on stickiness of `socket_last_error()` — that failure mode does not exist on
   streams, so the test now asserts the underlying invariant (a benign timed-out
   read never kills the connection).

4. **phpcbf/rector churn.** Several mechanical lint fixes were needed (PSR-12
   control-structure spacing, line length); Rector auto-applied property
   promotion and `instanceof` rewrites. `composer cs-fix` + `rector` resolved all.

## Surprises

- **PHP writes can block past timeouts** (obstacle 2) — `stream_set_timeout()`
  is documented as applying to reads *and* writes but empirically does not bound
  a blocking write that fills the kernel buffer. Worth knowing for anyone else
  touching this I/O layer.
- **`fread()` on `ssl://` can return `''` even when `stream_select()` reports
  readable** (incomplete TLS record in flight). `readBytes()` therefore retries
  empty reads until the deadline and only treats EOF (`meta['eof']`) as a closed
  connection. This is invisible to callers but is new logic worth an E2E test.

## Bugs / improvements noticed (out of scope)

1. **Duplicated docblocks on `readBytes()`** (`src/StreamConnection.php`,
   formerly ~line 1244–1278): two consecutive `/** … */` blocks before the same
   method — the first (MSG_WAITALL rationale) is now stale/removed by this
   change, but the pattern of stacked docblocks should be avoided.

2. **`Client/Connection::create()` parameter list is unsustainable**
   (`src/Client/Connection.php:90-103`): 12 optional parameters; this change adds
   `?TlsConfig $tls` as #13. A single `ConnectionOptions`/config VO (host, port,
   credentials, TLS, timeouts, frame sizes) would stop the list growing
   positionally and remove ambiguity at call sites.

3. **No TLS-enabled E2E path** (`docker-compose.yml` / `run-e2e.sh`): the issue's
   acceptance criteria include an E2E test against a TLS broker; the compose file
   has no TLS listener or certificates. Suggested follow-up: generate a local CA
   + server cert in the compose setup, expose 5551, and add
   `tests/E2E/TlsConnectionTest.php` using `TlsConfig(verifyPeer: true,
   cafile: …)`. Also note `run-e2e.sh` would need no change beyond env defaults.

4. **`readFrame()` select + `readFrameNoWait()` double-read pattern**: on the
   stream transport, `readFrame()` selects once, then `readFrameNoWait()`
   `readBytes()` selects again per chunk. For TLS the extra select is harmless
   but slightly wasteful; if a future profile shows overhead, `readBytes()` could
   take an optional "already selected" hint. Cosmetic only.

5. **Connect timeout undocumented change**: previously `socket_connect()` blocked
   indefinitely; now `connect()` is bounded by `$socketTimeout` (default 30 s).
   Documented in `code-decision-1.md`; a CHANGELOG entry is recommended.
