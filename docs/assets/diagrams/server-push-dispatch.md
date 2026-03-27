# Server Push Dispatch Diagram

> TODO: Create diagram

This diagram should illustrate how server-push frames are dispatched.

## Description

The diagram should show:
- readMessage() loop
- socket_select() waiting
- Frame reading
- Server-push frame identification
- Dispatch to callbacks
- Heartbeat echo
- Return of response frames

## Format

Consider creating this as:
- Flowchart
- Activity diagram
- Sequence diagram

## References

- See `docs/en/protocol/server-push-frames.md` for context
- See `src/StreamConnection.php` for implementation details
