# Read Loop (Async Frame Dispatch)

**Type:** Infrastructure (not a protocol command)  
**Branch:** `feature/read-loop`  
**Depends on:** `feature/publish` merged

## Problem

The current `StreamConnection::readMessage()` is synchronous — it reads exactly one frame and returns. This works for request/response commands (handshake, DeclarePublisher, etc.) but breaks for publishing because:

- `PublishConfirm` (0x0003) and `PublishError` (0x0004) are **server-push frames** — they arrive asynchronously, not as a direct response to a specific request
- `Deliver` (0x0008), `MetadataUpdate` (0x0010), `Heartbeat` (0x0017), `ConsumerUpdate` (0x001a) are also server-push frames
- Between sending a Publish and receiving PublishConfirm, the server may send a Heartbeat or MetadataUpdate

## Solution: Callback-based read loop

Add a `readLoop()` method to `StreamConnection` that reads frames in a blocking loop and dispatches them to registered callbacks.

### API Design

```php
// Register callbacks before starting the loop
$connection->registerPublisher(
    publisherId: 1,
    onConfirm: function(array $publishingIds): void { ... },
    onError:   function(array $errors): void { ... },
);

$connection->registerSubscriber(
    subscriptionId: 1,
    onDeliver: function(DeliverResponseV1 $deliver): void { ... },
);

$connection->onMetadataUpdate(function(MetadataUpdateResponseV1 $update): void { ... });
$connection->onHeartbeat(function(): void { ... });
$connection->onConsumerUpdate(function(int $subscriptionId, bool $active): ConsumerUpdateReply { ... });

// Blocking loop — reads until connection closed or exception
$connection->readLoop();
```

### Internal dispatch logic

```
readLoop():
  while (connected):
    frame = readFrame()
    key = frame.key  // e.g. 0x0003, 0x0008, 0x0010, 0x0017, 0x001a
    switch key:
      0x0003 (PublishConfirm)  -> publisherCallbacks[publisherId].onConfirm(publishingIds)
      0x0004 (PublishError)    -> publisherCallbacks[publisherId].onError(errors)
      0x0008 (Deliver)         -> subscriberCallbacks[subscriptionId].onDeliver(deliver)
      0x0010 (MetadataUpdate)  -> metadataUpdateCallback(update)
      0x0017 (Heartbeat)       -> send Heartbeat back; heartbeatCallback?()
      0x001a (ConsumerUpdate)  -> reply = consumerUpdateCallback(subscriptionId, active); send ConsumerUpdateResponse
      default -> throw or log unknown frame
```

## Server-push frames that need callback routing

From the protocol spec — frames sent **Server → Client** with no correlation ID:

| Key    | Command         | Routed by        | Notes |
|--------|-----------------|------------------|-------|
| 0x0003 | PublishConfirm  | publisherId      | Already implemented |
| 0x0004 | PublishError    | publisherId      | Already implemented |
| 0x0008 | Deliver         | subscriptionId   | Needs implementation |
| 0x0010 | MetadataUpdate  | stream name      | Needs implementation |
| 0x0017 | Heartbeat       | —                | Must echo back |
| 0x001a | ConsumerUpdate  | subscriptionId   | Server asks client; client must reply |

## Implementation Tasks

- [ ] Add `registerPublisher(int $publisherId, callable $onConfirm, callable $onError): void` to `StreamConnection`
- [ ] Add `registerSubscriber(int $subscriptionId, callable $onDeliver): void` to `StreamConnection`
- [ ] Add `onMetadataUpdate(callable $callback): void` to `StreamConnection`
- [ ] Add `onHeartbeat(?callable $callback = null): void` to `StreamConnection` (default: auto-reply)
- [ ] Add `onConsumerUpdate(callable $callback): void` to `StreamConnection`
- [ ] Implement `readLoop(): void` — blocking dispatch loop
- [ ] Update `PublishTest` E2E to use `registerPublisher` + `readLoop()` instead of raw `readMessage()`
- [ ] Unit test: mock frame dispatch in readLoop (or test via integration)
- [ ] E2E test: publish → readLoop → confirm callback fires

## Notes

- `readLoop()` should be interruptible — consider a `stop()` method or a max-iterations option for testing
- Heartbeat must be echoed automatically (send `HeartbeatRequestV1` back) — the loop handles this transparently
- `ConsumerUpdate` requires the loop to send a `ConsumerUpdateResponse` back synchronously before continuing
- This is the foundation for the consumer (`Subscribe` + `Deliver`) implementation
