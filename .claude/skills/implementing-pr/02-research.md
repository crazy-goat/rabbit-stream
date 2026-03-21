# Step 2 — Research the Issue

Dispatch an `explore` agent to research the issue and reference implementations:

**Prompt for explore agent:**

> Research issue #{NUMBER} for rabbit-stream project. 
>
> 1. Run `gh issue view {NUMBER}` to get issue details
 > 2. Clone or update reference implementations into the `.ref-clients/` directory **in the project root** (this directory is already in `.gitignore`). Run this script with `workdir` set to the project root:
 > ```bash
 > mkdir -p .ref-clients
 > declare -A REPOS=(
 >   [go-stream-client]="rabbitmq/rabbitmq-stream-go-client"
 >   [java-stream-client]="rabbitmq/rabbitmq-stream-java-client"
 >   [rust-stream-client]="rabbitmq/rabbitmq-stream-rust-client"
 >   [python-stream-client]="qweeze/rstream"
 > )
 > for dir in "${!REPOS[@]}"; do
 >   if [ -d ".ref-clients/$dir/.git" ]; then
 >     git -C ".ref-clients/$dir" pull --ff-only 2>/dev/null || true
 >   else
 >     gh repo clone "${REPOS[$dir]}" ".ref-clients/$dir"
 >   fi
 > done
 > ```
 > 3. Analyze the issue and find relevant code in all clients
 > 4. Check protocol spec at: https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc
 >
 > Return:
 > - Issue summary (what needs to be implemented)
 > - Key files/functions in Go client
 > - Key classes/methods in Java client
 > - Key structs/functions in Rust client
 > - Key classes/functions in Python client
 > - Protocol frame structure and field types
 > - Any dependencies or blockers mentioned in the issue
