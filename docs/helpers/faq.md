# FAQ — Recurring Pitfalls and Their Solutions

**How to read this file:** load the tag index below, pick the tags that match
the files in your diff, then read only those `###` entries. Do not read the
whole file.

**Who writes here:** only the retro step. Implementation and review
subagents *propose* candidate entries in their report — they never append
(see [README.md](README.md)).

## Tag index

<!-- kb-index:start -->
- `e2e` — FAQ-001, FAQ-002
- `gh` — FAQ-003
- `protocol` — FAQ-002
- `socket` — FAQ-002
<!-- kb-index:end -->

## Pitfalls

### E2E tests need the RabbitMQ Stream plugin on port 5552
<!-- id=FAQ-001 date=2025-05-20 tags=e2e trigger="when touching tests/E2E or running run-e2e.sh" hits=0 status=active -->

`./run-e2e.sh` boots `rabbitmq:4-management` via `docker compose` and binds
port **5552** (the stream protocol port, not 5672 AMQP) plus 15672
(management API). The stream plugin is enabled by the image but the broker
must report `healthy` before the suite runs — the script waits for that. If
you see "Address already in use" or connection refused, ensure 5552 is free:
`docker compose down` (the `EXIT` trap does this, but an interrupted run can
leave it up). E2E tests respect `RABBITMQ_HOST` / `RABBITMQ_PORT` (default
`127.0.0.1:5552`). Run E2E **only** for wire-level / protocol changes; pure
serialization unit tests never need the broker.

### Server-push frames use the request key, not the response key
<!-- id=FAQ-002 date=2025-05-20 tags=protocol,socket,e2e trigger="when implementing PublishConfirm/PublishError/Deliver/MetadataUpdate/Heartbeat/ConsumerUpdate handlers" hits=0 status=active -->

`PublishConfirm` (`0x0003`), `PublishError` (`0x0004`), `Deliver` (`0x0008`),
`MetadataUpdate` (`0x0010`), `Heartbeat` (`0x0017`) and `ConsumerUpdate`
(`0x001a`) arrive as **server → client** frames carrying the **request** key,
*not* the `0x8000`-ORed response key. Routing them through a `0x8000`
dispatcher will never match. `readMessage()` handles them transparently
inside its `socket_select()` loop; `readLoop()` dispatches them to registered
callbacks. Heartbeat must be **echoed back immediately**. See
`AGENTS.md` → "Server-Push Frames (Async)".

### `gh issue list` caps at 30 results unless --limit is raised
<!-- id=FAQ-003 date=2025-05-20 tags=gh trigger="when searching issues for triage or duplicate detection" hits=0 status=active -->

`gh issue list` returns **at most 30** issues by default. Triaging or
duplicate-detecting across the backlog silently misses everything past page
one. Always pass an explicit `--limit` (e.g. `--limit 150`, max 1000), for
both `--state open` and `--state closed`. For keyword search prefer
`gh search issues --repo crazy-goat/rabbit-stream --limit 50 "…"`.
