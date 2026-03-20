# Step 5 — Implement

Dispatch a `code` or `code-heavy` agent to implement the approved plan.

**Prompt for code agent:**

> Implement issue #{NUMBER} for rabbit-stream PHP library following the approved plan.
>
> **Context from research:**
> - [paste research findings from Step 2]
> - [paste approved plan from Step 3]
>
> **Implementation requirements:**
> 1. Follow AGENTS.md conventions exactly
> 2. Create/modify files as specified in the plan:
>    - `src/Request/{Name}RequestV1.php`
>    - `src/Response/{Name}ResponseV1.php`
>    - `src/Enum/KeyEnum.php` (add new enum cases)
>    - `src/ResponseBuilder.php` (add new match arm)
>    - `tests/Request/` and `tests/Response/` (unit tests)
> 3. Use PHP 8.1+ features: backed enums, constructor property promotion, match expressions
> 4. All public methods must have parameter and return types
> 5. Follow PSR-12 code style
> 6. Throw \Exception for protocol errors
>
> **Quality gates to pass (run in this order):**
> 
> ```bash
> # 1. Auto-fix code style (no manual fixes needed)
> composer lint:fix
> 
> # 2. Fix static analysis errors manually if any
> composer phpstan
> 
> # 3. Run unit tests
> composer test:unit
> 
> # 4. Run E2E tests
> ./run-e2e.sh
> ```
> 
> **Important:** 
> - `lint:fix` auto-fixes style - no manual intervention needed
> - `phpstan` may report errors - you MUST fix these manually
> - All tests must pass before returning
>
> Return when all quality gates pass.
