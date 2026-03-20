# Step 3 — Present Implementation Plan

After research, present a plan to the user covering:

1. **What the protocol says** — frame structure, field types, sequence
2. **How Go does it** — key types/functions found in the Go client
3. **How Java does it** — key classes/methods found in the Java client
4. **Proposed PHP implementation** — which files to create/modify:
   - `src/Request/{Name}RequestV1.php`
   - `src/Response/{Name}ResponseV1.php`
   - `src/Enum/KeyEnum.php` (new enum cases)
   - `src/ResponseBuilder.php` (new match arm)
   - `tests/Request/` and `tests/Response/`
5. **Any open questions or edge cases**

**Wait for user approval before writing any code.**
