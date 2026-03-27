<!--
  Server-Push Frame Dispatch Diagram
  Shows how async frames are handled by the client
  Width: 80 characters max
-->

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    Server-Push Frame Dispatch                                │
└─────────────────────────────────────────────────────────────────────────────┘

readMessage() Internal Loop:
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│   ┌─────────────┐                                                            │
│   │  Start      │                                                            │
│   └──────┬──────┘                                                            │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐     No     ┌─────────────┐                                 │
│   │ socket_     │ ─────────► │  Timeout    │                                 │
│   │ select()    │            │  Exception  │                                 │
│   └──────┬──────┘            └─────────────┘                                 │
│          │ Yes                                                               │
│          ▼                                                                   │
│   ┌─────────────┐                                                            │
│   │ Read Frame  │                                                            │
│   └──────┬──────┘                                                            │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐     No     ┌─────────────┐                                 │
│   │ Server-Push │ ─────────► │  Return to  │                                 │
│   │ Frame?      │            │  Caller     │                                 │
│   └──────┬──────┘            └─────────────┘                                 │
│          │ Yes                                                               │
│          ▼                                                                   │
│   ┌─────────────┐                                                            │
│   │ Dispatch to │                                                            │
│   │ Callback    │                                                            │
│   └──────┬──────┘                                                            │
│          │                                                                   │
│          └───────────────────┐                                               │
│                              ▼                                               │
│                       ┌─────────────┐                                        │
│                       │   Loop      │                                        │
│                       └─────────────┘                                        │
│                                                                              │
│  Server-push frames are handled transparently - caller never sees them      │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Server-Push Frame Types (7 total)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  ┌──────────────┬──────────┬──────────────────────────────────────────────┐│
│  │ Frame Type   │ Key      │ Routing / Action                             ││
│  ├──────────────┼──────────┼──────────────────────────────────────────────┤│
│  │ PublishConfirm│ 0x0003   │ Route by publisherId → onConfirm callback    ││
│  │ PublishError  │ 0x0004   │ Route by publisherId → onError callback      ││
│  │ Deliver       │ 0x0008   │ Route by subscriptionId → subscriber cb      ││
│  │ MetadataUpdate│ 0x0010   │ Route by stream name → metadata callback     ││
│  │ Close         │ 0x0016   │ Server-initiated close → close connection    ││
│  │ Heartbeat     │ 0x0017   │ Echo back immediately → heartbeat cb         ││
│  │ ConsumerUpdate│ 0x001a   │ Route by subscriptionId → consumer cb        ││
│  └──────────────┴──────────┴──────────────────────────────────────────────┘│
│                                                                              │
│  Note: Server-push frames use REQUEST keys, not response keys (0x8000+)    │
└─────────────────────────────────────────────────────────────────────────────┘
```

## readLoop() Event Loop

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│   ┌─────────────┐                                                            │
│   │  Start      │                                                            │
│   │  Loop       │                                                            │
│   └──────┬──────┘                                                            │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────┐     Yes    ┌─────────────┐                                │
│   │ running &&  │ ─────────► │   Exit      │                                │
│   │ connected?  │            │   Loop      │                                │
│   └──────┬──────┘            └─────────────┘                                │
│          │ No                                                                │
│          ▼                                                                   │
│   ┌─────────────┐     No     ┌─────────────┐     Yes    ┌─────────────┐    │
│   │ socket_     │ ─────────► │  Continue   │ ─────────► │ Read Frame  │    │
│   │ select()    │            │  Loop       │            │             │    │
│   └──────┬──────┘            └─────────────┘            └──────┬──────┘    │
│          │ Yes                                               │            │
│          │                                                   ▼            │
│          │                                          ┌─────────────┐       │
│          │                                          │ Server-Push │       │
│          │                                          │ Frame?      │       │
│          │                                          └──────┬──────┘       │
│          │                                                 │              │
│          │                    ┌─────────────┐               │ Yes          │
│          │                    │  Log &      │◄─────────────┘              │
│          │                    │  Discard    │               No             │
│          │                    └─────────────┘                              │
│          │                                                                   │
│          └──────────────────────────────────────────────────────────────────┤
│                                                                             │
│   ┌─────────────┐                                                           │
│   │ Check       │                                                           │
│   │ maxFrames   │                                                           │
│   └──────┬──────┘                                                           │
│          │                                                                  │
│          └──────────────────────────────────────────────────────────────────┘
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Heartbeat Handling

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  Server ──► Heartbeat (0x0017) ──► Client                                   │
│                                     │                                        │
│                                     ▼                                        │
│                              Echo immediately                                │
│                              Heartbeat (0x0017)                              │
│                                     │                                        │
│                                     ▼                                        │
│  Server ◄───────────────────────────┘                                        │
│                                                                              │
│  Heartbeat keeps connection alive during idle periods                        │
│  Both sides send heartbeats at negotiated interval                           │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Routing by ID

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  PublishConfirm [publisherId=5]                                               │
│       │                                                                      │
│       ▼                                                                      │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                      Publisher Registry                              │    │
│  │  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐   │    │
│  │  │ ID: 1   │  │ ID: 2   │  │ ID: 3   │  │ ID: 4   │  │ ID: 5   │   │    │
│  │  │ onConf  │  │ onConf  │  │ onConf  │  │ onConf  │  │ onConf  │   │    │
│  │  │ onErr   │  │ onErr   │  │ onErr   │  │ onErr   │  │ onErr   │   │    │
│  │  └─────────┘  └─────────┘  └─────────┘  └─────────┘  └─────────┘   │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                     │                                        │
│                                     ▼                                        │
│                              Call onConfirm()                                │
│                              for publisher #5                                │
│                                                                              │
│  Same routing applies to:                                                    │
│  • PublishError → onError callback                                           │
│  • Deliver → subscriber callback                                               │
│  • ConsumerUpdate → consumer callback                                          │
└─────────────────────────────────────────────────────────────────────────────┘
```
