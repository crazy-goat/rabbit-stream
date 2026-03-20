# Step 2 — Research the Issue

Dispatch an `explore` agent to research the issue and reference implementations:

**Prompt for explore agent:**

> Research issue #{NUMBER} for rabbit-stream project. 
>
> 1. Run `gh issue view {NUMBER}` to get issue details
> 2. Clone/update reference implementations:
>    - Go client: `gh repo clone rabbitmq/rabbitmq-stream-go-client .ref-clients/go-stream-client` (or pull if exists)
>    - Java client: `gh repo clone rabbitmq/rabbitmq-stream-java-client .ref-clients/java-stream-client` (or pull if exists)
> 3. Analyze the issue and find relevant code in both clients
> 4. Check protocol spec at: https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc
>
> Return:
> - Issue summary (what needs to be implemented)
> - Key files/functions in Go client
> - Key classes/methods in Java client  
> - Protocol frame structure and field types
> - Any dependencies or blockers mentioned in the issue
