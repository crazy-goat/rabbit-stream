<!--
  Message Publishing Flow Diagram
  Shows the complete publish workflow including async confirms
  Width: 80 characters max
-->

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Message Publishing Flow                               │
└─────────────────────────────────────────────────────────────────────────────┘

Basic Publish Flow:

    Publisher                                           Server
      │                                                   │
      │  1. DeclarePublisher (0x0001)                       │
      │     [publisherId, streamName]                       │
      │ ───────────────────────────────────────────────►  │
      │                                                   │
      │     DeclarePublisherResponse (0x8001)             │
      │ ◄───────────────────────────────────────────────  │
      │                                                   │
      │  2. Publish (0x0002)                                │
      │     [publisherId, messages[]]                       │
      │ ───────────────────────────────────────────────►  │
      │                                                   │
      │     (no immediate response)                       │
      │                                                   │
      │     3. PublishConfirm (0x0003) ◄── Async ───────  │
      │        [publisherId, publishingIds[]]               │
      │ ◄───────────────────────────────────────────────  │
      │                                                   │
      │  4. DeletePublisher (0x0006)                        │
      │ ───────────────────────────────────────────────►  │
      │                                                   │
      │     DeletePublisherResponse (0x8006)              │
      │ ◄───────────────────────────────────────────────  │
```

## Batch Confirm Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  Publish #1 ──►  Publish #2 ──►  Publish #3 ──►  Publish #4                 │
│       │              │              │              │                          │
│       └──────────────┴──────────────┴──────────────┘                          │
│                          │                                                  │
│                          ▼                                                  │
│              PublishConfirm [1,2,3,4] (batch confirmation)                      │
│                                                                              │
│  Server batches confirms for efficiency - not 1:1 with publishes             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Named Producer Deduplication

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  Publisher A ──► Publish [seq=1] ──► Server (stored)                        │
│  Publisher A ──► Publish [seq=2] ──► Server (stored)                        │
│  Publisher B ──► Publish [seq=1] ──► Server (duplicate - ignored)             │
│  Publisher A ──► Publish [seq=3] ──► Server (stored)                        │
│                                                                              │
│  Deduplication is per-producer-name, not per-publisher-id                    │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Publish Error Handling

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  Publish ──► Server ──► Error (code, message)                                 │
│                              │                                               │
│                              ▼                                               │
│                    PublishError (0x0004)                                      │
│                    [publisherId, publishingId, code, message]                 │
│                              │                                               │
│                              ▼                                               │
│                         Application                                          │
│                      (retry / log / fail)                                    │
│                                                                              │
│  Common error codes:                                                         │
│  • 0x02 (StreamNotAvailable) - Stream doesn't exist                          │
│  • 0x03 (PublisherDoesNotExist) - Invalid publisher ID                       │
│  • 0x0c (AccessRefused) - No write permission                                │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Command Reference

| Command | Key | Direction | Response |
|---------|-----|-----------|----------|
| DeclarePublisher | 0x0001 | Client → Server | DeclarePublisherResponse (0x8001) |
| Publish | 0x0002 | Client → Server | None (async confirm) |
| PublishConfirm | 0x0003 | Server → Client | None (server push) |
| PublishError | 0x0004 | Server → Client | None (server push) |
| DeletePublisher | 0x0006 | Client → Server | DeletePublisherResponse (0x8006) |
