<!--
  Message Consumption Flow Diagram
  Shows the complete consume workflow with credit system
  Width: 80 characters max
-->

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       Message Consumption Flow                               │
└─────────────────────────────────────────────────────────────────────────────┘

Basic Consume Flow:

    Consumer                                            Server
      │                                                   │
      │  1. Subscribe (0x0007)                              │
      │     [subscriptionId, stream, offset]                │
      │ ───────────────────────────────────────────────►  │
      │                                                   │
      │     SubscribeResponse (0x8007)                    │
      │ ◄───────────────────────────────────────────────  │
      │                                                   │
      │  2. Credit (0x0009)                                 │
      │     [subscriptionId, credit]                        │
      │ ───────────────────────────────────────────────►  │
      │                                                   │
      │     CreditResponse (0x8009)                       │
      │ ◄───────────────────────────────────────────────  │
      │                                                   │
      │     3. Deliver (0x0008) ◄──── Async ────────────  │
      │        [subscriptionId, messages[]]                 │
      │ ◄───────────────────────────────────────────────  │
      │                                                   │
      │  4. StoreOffset (0x000a)                            │
      │     [offset]                                        │
      │ ───────────────────────────────────────────────►  │
      │                                                   │
      │  5. Credit (0x0009) - request more                  │
      │ ───────────────────────────────────────────────►  │
      │                                                   │
      │     (repeat 3-5 for continuous consumption)       │
      │                                                   │
      │  6. Unsubscribe (0x000c)                            │
      │ ───────────────────────────────────────────────►  │
      │                                                   │
      │     UnsubscribeResponse (0x800c)                  │
      │ ◄───────────────────────────────────────────────  │
```

## Credit Flow Cycle

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│   ┌──────────┐    Credit(10)     ┌──────────┐    Deliver(10)    ┌─────────┐│
│   │ Consumer │ ────────────────► │  Server  │ ────────────────► │Consumer ││
│   │          │                   │          │                   │         ││
│   │          │ ◄──────────────── │          │ ◄──────────────── │         ││
│   └──────────┘   Credit(10)      └──────────┘    Processed      └─────────┘│
│        ▲                                                            │       │
│        └────────────────────────────────────────────────────────────┘       │
│                              StoreOffset                                    │
│                                                                              │
│  Credit = "I can handle N more messages"                                    │
│  Server stops sending when credit exhausted                                 │
│  Consumer must re-issue Credit to continue                                  │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Offset Tracking

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  Subscribe [offset=100] ──► Deliver [100-109] ──► StoreOffset [110]          │
│       │                                                            │         │
│       │◄─────────────────── Resume ────────────────────────────────┘         │
│       │                                                                      │
│       ▼                                                                      │
│  Subscribe [offset=110] ──► Deliver [110-119] ──► StoreOffset [120]          │
│                                                                              │
│  StoreOffset persists position for resumption after disconnect               │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Offset Types

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  Offset Types:                                                               │
│  ┌─────────────────┬──────────────────────────────────────────────────────┐   │
│  │ Type            │ Description                                          │   │
│  ├─────────────────┼──────────────────────────────────────────────────────┤   │
│  │ FIRST           │ Start from first message in stream                   │   │
│  │ LAST            │ Start from last message (new messages only)          │   │
│  │ NEXT            │ Start after last consumed message                      │   │
│  │ OFFSET          │ Start at specific offset number                        │   │
│  │ TIMESTAMP       │ Start at messages after timestamp                      │   │
│  └─────────────────┴──────────────────────────────────────────────────────┘   │
│                                                                              │
│  Example: Subscribe with LAST offset                                         │
│  ┌─────────────┐                                                             │
│  │  stream     │                                                             │
│  │ [msg1][msg2][msg3][msg4][msg5]                                            │
│  │                    ▲                                                      │
│  │                    │                                                      │
│  │              Subscribe(LAST) ──► receives msg5, msg6, msg7...             │
│  └─────────────┘                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Command Reference

| Command | Key | Direction | Response |
|---------|-----|-----------|----------|
| Subscribe | 0x0007 | Client → Server | SubscribeResponse (0x8007) |
| Deliver | 0x0008 | Server → Client | None (server push) |
| Credit | 0x0009 | Client → Server | CreditResponse (0x8009) |
| StoreOffset | 0x000a | Client → Server | None |
| Unsubscribe | 0x000c | Client → Server | UnsubscribeResponse (0x800c) |
