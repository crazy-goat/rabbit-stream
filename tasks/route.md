# Route

**Protocol Key:** `0x0018`  
**Direction:** Client → Server  
**Expects Response:** Yes

## Description
Client sends a RoutingKey and SuperStream name; server responds with a ResponseCode and an array of Stream names that the routing key maps to.

## Protocol Structure
```
RouteQuery => Key Version CorrelationId RoutingKey SuperStream
  RoutingKey => string
  SuperStream => string
RouteResponse => Key Version CorrelationId ResponseCode [Stream]
  Stream => string
```

## Implementation Tasks
- [ ] Create `src/Request/RouteRequestV1.php`
- [ ] Create `src/Response/RouteResponseV1.php`
- [ ] Register response key in `src/Enum/KeyEnum.php`
- [ ] Register in `src/ResponseBuilder.php`
- [ ] Write test/example
