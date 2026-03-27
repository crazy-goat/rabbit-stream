<!--
  Connection Handshake Sequence Diagram
  Shows the 5-step connection establishment process
  Width: 80 characters max
-->

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    Connection Handshake Sequence                             │
└─────────────────────────────────────────────────────────────────────────────┘

     Client                                              Server
       │                                                   │
       │  TCP Connect (port 5552)                          │
       │ ═══════════════════════════════════════════════►│
       │                                                   │
       │  1. PeerProperties (0x0011)                       │
       │ ───────────────────────────────────────────────►  │
       │     [client properties]                               │
       │                                                   │
       │     PeerPropertiesResponse (0x8011)               │
       │     [server properties]                             │
       │ ◄───────────────────────────────────────────────  │
       │                                                   │
       │  2. SaslHandshake (0x0012)                        │
       │ ───────────────────────────────────────────────►  │
       │                                                   │
       │     SaslHandshakeResponse (0x8012)                │
       │     [mechanisms: PLAIN, AMQPLAIN, EXTERNAL]       │
       │ ◄───────────────────────────────────────────────  │
       │                                                   │
       │  3. SaslAuthenticate (0x0013)                       │
       │     [username, password, mechanism]                 │
       │ ───────────────────────────────────────────────►  │
       │                                                   │
       │     SaslAuthenticateResponse (0x8013)             │
       │ ◄───────────────────────────────────────────────  │
       │                                                   │
       │  4. Tune (0x0014)                                   │
       │ ───────────────────────────────────────────────►  │
       │     [frameMax, heartbeat]                           │
       │                                                   │
       │     TuneResponse (0x8014)                         │
       │     [frameMax, heartbeat]                         │
       │ ◄───────────────────────────────────────────────  │
       │                                                   │
       │  5. Open (0x0015)                                   │
       │     [virtualHost]                                   │
       │ ───────────────────────────────────────────────►  │
       │                                                   │
       │     OpenResponse (0x8015)                         │
       │ ◄───────────────────────────────────────────────  │
       │                                                   │
       ▼                                                   ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    Connection Established - Ready for Use                    │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Handshake Details

| Step | Request | Response | Purpose |
|------|---------|----------|---------|
| 1 | PeerProperties (0x0011) | PeerPropertiesResponse (0x8011) | Exchange capabilities |
| 2 | SaslHandshake (0x0012) | SaslHandshakeResponse (0x8012) | Get auth mechanisms |
| 3 | SaslAuthenticate (0x0013) | SaslAuthenticateResponse (0x8013) | Authenticate user |
| 4 | Tune (0x0014) | TuneResponse (0x8014) | Negotiate settings |
| 5 | Open (0x0015) | OpenResponse (0x8015) | Open virtual host |

## State Transitions

```
┌─────────────┐    TCP Connect     ┌─────────────┐
│   START     │ ═════════════════► │  CONNECTED  │
└─────────────┘                    └─────────────┘
                                         │
                                         │ PeerProperties
                                         ▼
                                  ┌─────────────┐
                                  │  PROPERTIES │
                                  └─────────────┘
                                         │
                                         │ SaslHandshake
                                         ▼
                                  ┌─────────────┐
                                  │   SASL      │
                                  │ HANDSHAKE   │
                                  └─────────────┘
                                         │
                                         │ SaslAuthenticate
                                         ▼
                                  ┌─────────────┐
                                  │ AUTHENTICATED│
                                  └─────────────┘
                                         │
                                         │ Tune
                                         ▼
                                  ┌─────────────┐
                                  │   TUNED     │
                                  └─────────────┘
                                         │
                                         │ Open
                                         ▼
                                  ┌─────────────┐
                                  │   OPEN      │
                                  │  (Ready)    │
                                  └─────────────┘
```

## Protocol Keys Reference

| Command | Request Key | Response Key |
|---------|-------------|--------------|
| PeerProperties | 0x0011 | 0x8011 |
| SaslHandshake | 0x0012 | 0x8012 |
| SaslAuthenticate | 0x0013 | 0x8013 |
| Tune | 0x0014 | 0x8014 |
| Open | 0x0015 | 0x8015 |
| Close | 0x0016 | 0x8016 |
| Heartbeat | 0x0017 | 0x8017 |

Note: Response keys are request keys OR'd with 0x8000 (bit 15 set).
