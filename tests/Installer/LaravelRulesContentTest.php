<?php

declare(strict_types = 1);

test('laravel rules prefer filled()/blank() helpers over strict empty-string comparisons', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('## String Emptiness Checks');
    expect($content)->toContain('`filled()`');
    expect($content)->toContain('`blank()`');
    expect($content)->toContain('`!== \'\'`');
    expect($content)->toContain('`=== \'\'`');
});

test('laravel rules extend Database and Eloquent with index and EXPLAIN guidance (issue #525)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('verify indexes for every high-cardinality');
    expect($content)->toContain('check `EXPLAIN` before shipping');
    expect($content)->toContain('left-most prefix');
    expect($content)->toContain('Do not add indexes blindly');
});

test('laravel rules forbid dispatching full Eloquent models to queued jobs (issue #525)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('Do not dispatch full Eloquent models to queued jobs');
    expect($content)->toContain('Fetch fresh models inside `handle()`');
    expect($content)->toContain('serialize only the explicit fields needed by the job');
    expect($content)->toContain('Queue constructors must only accept lightweight scalar values');
});

test('laravel rules tighten Dependency Injection with hot-path and lazy resolution guidance (issue #525)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('Do not call `app()`, `resolve()`, or `$container->make()` inside loops or hot paths');
    expect($content)->toContain('Bind stateless expensive services as singletons');
    expect($content)->toContain('Prefer lazy service resolution');
    expect($content)->toContain('Keep service constructors lightweight');
});

test('laravel rules require selective and lightweight middleware (issue #525)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('Apply middleware selectively');
    expect($content)->toContain('Put cheap fast-failing middleware before expensive middleware');
    expect($content)->toContain('Do not perform database queries, service orchestration, or external API calls in middleware');
});

test('laravel rules add Stateless Runtime, Caching, and Long-Running Runtime Safety sections (issue #525)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('## Stateless Runtime');
    expect($content)->toContain('Production application servers must be disposable');
    expect($content)->toContain('`onOneServer()` or another explicit distributed mutex');

    expect($content)->toContain('## Caching');
    expect($content)->toContain('Use Redis or another shared cache for sessions, queues, cross-server locks');
    expect($content)->toContain('Always set explicit TTLs for cached values');
    expect($content)->toContain('Do not cache user-specific or permission-sensitive data without including the relevant identity');

    expect($content)->toContain('## Long-Running Runtime Safety');
    expect($content)->toContain('safe for long-running PHP processes');
    expect($content)->toContain('Octane');
    expect($content)->toContain('worker recycling');
});

test('laravel rules document Laravel 13 Bus::bulk, scheduler metadata, and Schema::hasForeignKey (issue #551)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('Use `Bus::bulk()` to dispatch many jobs onto the queue in a single call');
    expect($content)->toContain('Reserve `Bus::batch()` for cases that genuinely need progress tracking');

    expect($content)->toContain('## Scheduling');
    expect($content)->toContain('Attach structured metadata to scheduled commands with `withAttributes()`');
    expect($content)->toContain('monitoring, logging, and alerting');

    expect($content)->toContain('Use `Schema::hasForeignKey()` to verify a foreign key exists before creating or dropping it');
});

test('laravel rules require user-facing UI, console, and API strings to be translatable (issue #553)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('## Localization and Translatable Strings');
    expect($content)->toContain('Every string a user can see must go through Laravel\'s translation layer');
    expect($content)->toContain('**UI**');
    expect($content)->toContain('**Console**');
    expect($content)->toContain('**API**');
    expect($content)->toContain('$this->info()');
    expect($content)->toContain('JSON `message` fields');
    expect($content)->toContain('add every new key to **all** shipped locales');
});

test('laravel rules forbid real HTTP and real system processes in tests (issue #553)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('Never allow real external HTTP calls in tests.');
    expect($content)->toContain('Never let tests run real system processes outside the application.');
    expect($content)->toContain('Tests must never invoke an external binary or script directly on the system');
    expect($content)->toContain('`Process::fake()`');
    expect($content)->toContain('shell_exec()');
    expect($content)->toContain('proc_open()');
});

test('architecture rules enumerate the seven allowed business logic layers including Eloquent models', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/architecture.md');

    expect($content)->toContain('## Business Logic Layers');
    expect($content)->toContain('seven class types');
    expect($content)->toContain('**Actions**');
    expect($content)->toContain('**Model Services**');
    expect($content)->toContain('**Repositories**');
    expect($content)->toContain('**ModelManagers**');
    expect($content)->toContain('**Data Validators**');
    expect($content)->toContain('**Data Builders**');
    expect($content)->toContain('**Eloquent models**');
    expect($content)->toContain('simple, self-contained domain methods');
    expect($content)->toContain('@skills/class-refactoring/SKILL.md');
});

test('laravel rules permit simple self-contained logic on Eloquent models', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('Simple, self-contained domain logic may live as methods on the model.');
    expect($content)->toContain('$user->isActive()');
    expect($content)->toContain('Forbidden on models');
    expect($content)->toContain('$user->sendWelcomeEmail()');
    expect($content)->toContain('lazy-load relationships count as new database queries');
    expect($content)->not->toContain('Keep business logic out of models.');
    expect($content)->not->toContain('Keep business logic out of controllers, middleware, Blade views, and Eloquent models.');
});

test('architecture bullets remain under the Architecture heading and Business Logic Layers sits before Actions', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/architecture.md');

    $architectureHeading = strpos($content, "\n## Architecture\n");
    $multitenancyBullet = strpos($content, 'Multitenancy remains mandatory');
    $customHelpersBullet = strpos($content, '**Custom Helpers:**');
    $businessLogicHeading = strpos($content, "\n## Business Logic Layers\n");
    $actionsHeading = strpos($content, "\n## Actions\n");

    assert($architectureHeading !== false);
    assert($multitenancyBullet !== false);
    assert($customHelpersBullet !== false);
    assert($businessLogicHeading !== false);
    assert($actionsHeading !== false);

    expect($architectureHeading)->toBeLessThan($multitenancyBullet);
    expect($multitenancyBullet)->toBeLessThan($businessLogicHeading);
    expect($customHelpersBullet)->toBeLessThan($businessLogicHeading);
    expect($businessLogicHeading)->toBeLessThan($actionsHeading);
});

test('architecture rules carry the Shared Concerns (Traits) section scoped to globally reusable, domain-agnostic logic (issue #531)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/architecture.md');

    expect($content)->toContain('## Shared Concerns (Traits)');
    expect($content)->toContain('`app/Concerns/` is the **canonical home for all globally shared and reusable logic**');
    expect($content)->toContain('**Globally applicable**');
    expect($content)->toContain('**Domain-agnostic**');
    expect($content)->toContain('**Reusable as-is**');
    expect($content)->toContain('**Forbidden in `app/Concerns/`:**');
    expect($content)->toContain('Domain-specific logic');
    expect($content)->toContain('Single-use traits or helpers consumed by exactly one class');
    expect($content)->toContain('Orchestration, persistence, query, or HTTP/queue dispatching logic');
    expect($content)->toContain('The **Validation Rules (Traits)** section below is one specific instance of this broader rule');
    expect($content)->toContain('This is the canonical worked example of the **Shared Concerns (Traits)** rule above.');
});

test('architecture Shared Concerns (Traits) section sits immediately before Validation Rules (Traits) (issue #531)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/architecture.md');

    $sharedConcernsHeading = strpos($content, "\n## Shared Concerns (Traits)\n");
    $validationRulesHeading = strpos($content, "\n## Validation Rules (Traits)\n");
    $dataValidatorsHeading = strpos($content, "\n## Data Validators\n");

    expect($sharedConcernsHeading)->not->toBeFalse();
    expect($validationRulesHeading)->not->toBeFalse();
    expect($dataValidatorsHeading)->not->toBeFalse();
    assert($sharedConcernsHeading !== false);
    assert($validationRulesHeading !== false);
    assert($dataValidatorsHeading !== false);

    expect($sharedConcernsHeading)->toBeLessThan($validationRulesHeading);
    expect($validationRulesHeading)->toBeLessThan($dataValidatorsHeading);
});

test('architecture CR Severity Rules cover app/Concerns misuse in both directions (issue #531)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/architecture.md');

    expect($content)->toContain('domain-specific code placed under `app/Concerns/`');
    expect($content)->toContain('shared, reusable trait or helper logic placed outside `app/Concerns/`');
    expect($content)->toContain('single-use trait parked in `app/Concerns/`');
    expect($content)->toContain('per **Shared Concerns (Traits)**');
});

test('laravel rules carry the parallel Shared Concerns section and Layer Responsibilities bullet (issue #531)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('## Shared Concerns');
    expect($content)->toContain('Shared Concerns (`app/Concerns/`): globally shared and reusable logic');
    expect($content)->toContain('canonical home for all globally shared and reusable logic in the application');
    expect($content)->toContain('**globally applicable**');
    expect($content)->toContain('**domain-agnostic**');
    expect($content)->toContain('**reusable as-is**');
    expect($content)->toContain('Never put domain-specific logic in `app/Concerns/`');
    expect($content)->toContain('Validation rule traits (see the **Validation** section below) are one specific worked example');
});

test('architecture rules require request->DTO transformation in the FormRequest, not the controller (issue #698)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/architecture.md');

    expect($content)->toContain('Request → DTO transformation belongs in the FormRequest, not the controller.');
    expect($content)->toContain('toDto()');
    expect($content)->toContain('`$request->toDto()`');
    expect($content)->toContain('do **not** call `SomeData::from($request)`');
});

test('laravel-security skill carries the secure-defaults reference and checklist', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/laravel-security/SKILL.md');

    expect($content)->toContain('Quick Security Checklist');
    expect($content)->toContain('Mass assignment');
    expect($content)->toContain('@rules/security/backend.md');
    expect($content)->toContain('@skills/security-review/SKILL.md');
});

test(
    'architecture rules require match() over an enum mode to live in a Data Validator when pekral/arch-app-services is installed (issue #708)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $content = (string) file_get_contents($packageDir . '/rules/laravel/architecture.md');
    
        expect($content)->toContain('`match()` over an enum mode is domain validation and must live in a Data Validator');
        expect($content)->toContain('only when `pekral/arch-app-services` is installed');
        expect($content)->toContain('ContactChangeDataValidator::evaluate(ContactChangeCondition $condition, ChangeModel $change): bool');
        expect($content)->toContain('vendor/pekral/arch-app-services');
    },
);

test(
    'architecture rules mandate an unconditional Service→BaseModelService rule with a structural Action-shape test (issue #126)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $content = (string) file_get_contents($packageDir . '/rules/laravel/architecture.md');

        expect($content)->toContain('Every class named with a `Service` suffix must extend `BaseModelService`');
        expect($content)->toContain('unconditionally; there is no');
        expect($content)->toContain('A class whose only public method is already `__invoke()` needs no counting');
        expect($content)->toContain('A `Service`-suffixed class exposing exactly one public business method');
        expect($content)->toContain('is Action-shaped, not Model-Service-shaped');
        expect($content)->toContain('not gated on whether it is judged to be "tied to one model"');
        expect($content)->toContain('a `Service`-suffixed class that already `extends BaseModelService` (so neither Critical bullet above fires)');
        expect($content)->toContain('not flagged by this bullet regardless of its public method count');

        // The dedup between the two Critical restatements must stay scoped to this one condition.
        expect($content)->toContain('this condition earns exactly one Critical finding, not two');
        expect($content)->toContain('never suppresses a separately triggered Critical on the same class');
        expect($content)->not->toContain('a violating class earns exactly one Critical finding');

        // The subjective wording the mechanical test replaces must be gone, not merely supplemented.
        expect($content)->not->toContain('primarily serve a single model');
        expect($content)->not->toContain('If logic does not primarily serve a single model');
    },
);

test('laravel rules mandate the native Image facade over Intervention Image/GD/Imagick for image processing (issue #118)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('## Image Processing');
    expect($content)->toContain('Illuminate\Support\Facades\Image');
    expect($content)->toContain('Laravel 13.20');
    expect($content)->toContain('Intervention Image v4');
    expect($content)->toContain('This sits ahead of the **New Feature Implementation** package waterfall');
    expect($content)->toContain('$request->image(\'avatar\')');
    expect($content)->toContain('Image::fromPath(...)');
    expect($content)->toContain('cover($width, $height)');
    expect($content)->toContain('Reserve `resize($width, $height)` for a call site that intentionally accepts distortion.');
    expect($content)->toContain('An `Image` instance cannot be serialized and throws `ImageException` when passed into a queued job.');
});

test('architecture rules forbid introducing a new project-owned Facade as a home for business logic (issue #254)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/architecture.md');

    expect($content)->toContain('**Never introduce a new project-owned Facade.**');
    expect($content)->toContain('A Laravel Facade is a static proxy to a container binding, not a home for logic');
    expect($content)->toContain(
        'a class extending `Illuminate\Support\Facades\Facade`, a `*Facade`-suffixed class under `App\`, or a new `App\Facades\…` entry',
    );
    expect($content)->toContain('The correct home is the **base service**');
    expect($content)->toContain('Pekral\Arch\Service\BaseModelService');

    // The legacy carve-out keeps the pre-existing Facade enumerations from reading as permission.
    expect($content)->toContain('describe **legacy** code the project still carries, never a shape a new design may reach for');

    // Consuming a vendor facade stays allowed — the rule governs declared facades only.
    expect($content)->toContain('this rule governs the facades a project *declares*, never the ones it *uses*');

    // Critical severity, and it fires only on a facade the diff adds.
    expect($content)->toContain('a new project-owned Facade added as a home for business logic, or as a static entry point to it');
    expect($content)->toContain('the rule fires only on a facade the change **adds**');
});

test('architecture rules collapse an Action that only forwards to another Action (issue #20)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/architecture.md');

    // The pre-existing Pass-through Action rule enumerates "Service / Facade / Model Service",
    // which structurally excludes an Action forwarding to another Action — the gap issue #20 names.
    expect($content)->toContain('- **Action-to-Action pass-through rule (Action pattern):**');
    expect($content)->toContain('an `__invoke()` whose entire body is `($this->otherAction)($payload)` and nothing else');

    // The carve-out is what keeps the rule from flagging legitimate orchestration.
    expect($content)->toContain('one of them another Action, is the pattern working as intended');
    expect($content)->toContain('two names for one use case');

    // An Action is a use case, not a reusable method, so both branches collapse the pair.
    expect($content)->toContain('an Action is a use case rather than a reusable method, so the resolution is always to **collapse the two into one**');
    expect($content)->toContain('If the outer Action is the **only** caller of the inner one, merge them');
    expect($content)->toContain('`$outerAction($payload)` becomes `$innerAction($payload)`');

    // Symmetric gating against the general-logic finding, stated on both halves in this file.
    expect($content)->toContain('when the **entire** `__invoke()` body is that single delegating call, this rule owns it');
    // Same hand-off, same two targets — and "the matching ... finding" rather than "the
    // pass-through finding", because two pass-through findings follow this line, not one.
    expect($content)->toContain(
        'When the **entire** `__invoke()` body is a single delegating call to a Service / Facade / Model Service method '
        . 'or to another Action, the matching pass-through finding below owns it instead — never both',
    );

    // CR Severity Rules and Exceptions both name the new case, so the walk and the carve-out agree.
    expect($content)->toContain('merge the two Actions when the outer one is the inner one\'s only caller');
    expect($content)->toContain('a pure single-call delegation to another Action with no orchestration');

    // Exactly one rule definition — a second copy is how two severities drift apart — and exactly
    // two cross-references back to it (the CR Severity Rules entry and the Exceptions entry).
    expect(substr_count($content, '- **Action-to-Action pass-through rule (Action pattern):**'))->toBe(1);
    expect(substr_count($content, '**Action-to-Action pass-through rule**'))->toBe(2);
});

test('architecture rules cap an Action __invoke() at exactly one DTO parameter', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/architecture.md');

    // The rule itself: one payload per use case, carried by one typed DTO.
    expect($content)->toContain('- **One DTO per `__invoke()` — when the Action needs a DTO, it takes exactly one.**');
    expect($content)->toContain('merge them into one DTO named after the use case');
    expect($content)->toContain('belongs as a property of that DTO, not beside it');

    // The rule caps DTO count; it never mandates a DTO where none is warranted.
    expect($content)->toContain('**The rule fires only when a DTO is used at all.**');
    expect($content)->toContain('it only caps how many appear once one is warranted');

    // The three shapes that are not a second payload.
    expect($content)->toContain('**Not a second DTO:**');
    expect($content)->toContain('`__invoke(OrderModel $order, UpdateOrderData $data)` is one payload plus its subject');
    expect($content)->toContain('the DTO the Action **returns**, which is a separate type by design');

    // Declared Moderate, so the default stratification cannot push it to Critical, and gated
    // against the >4-parameter rule that prescribes the same fix.
    expect($content)->toContain('an Action `__invoke()` that declares **more than one DTO parameter**');
    expect($content)->toContain('**Gating — one finding per signature, never two:**');

    // Exactly one rule definition; a second copy is how two severities drift apart.
    expect(substr_count($content, '- **One DTO per `__invoke()`'))->toBe(1);
});

test('the Action-pattern CR walk and refactor skill both carry the one-DTO signature rule', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // The mandatory Architecture conformance walk names it, so a reviewer actually checks it.
    $walk = (string) file_get_contents($packageDir . '/rules/code-review/core-analysis.md');
    expect($walk)->toContain('**one DTO per `__invoke()`**');
    expect($walk)->toContain('merges into one carrier named after the use case');

    // The skill that designs the extracted signature applies it at that decision point.
    $skill = (string) file_get_contents($packageDir . '/skills/refactor-entry-point-to-action/SKILL.md');
    expect($skill)->toContain('needs a DTO to carry its input, give it **exactly one**');
    expect($skill)->toContain('*One DTO per `__invoke()`*');
});

test('laravel rules prefer a fluent collection pipeline over reassigned intermediates', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/laravel.md');

    expect($content)->toContain('## Collections');
    expect($content)->toContain('**Chain collection operations into one fluent pipeline.**');

    // The three shapes the rule replaces.
    expect($content)->toContain('**Reassigning one variable step by step is the shape to replace.**');
    expect($content)->toContain('accumulate into an array a `map()` / `filter()` / `groupBy()` / `sum()` call already expresses');
    expect($content)->toContain('**Never leave the collection mid-pipeline.**');

    // Formatting, and the two limits that keep the rule from distorting readable code.
    expect($content)->toContain('**Break a long chain across lines, one operation per line**');
    expect($content)->toContain('a step whose closure needs more than one statement is');
    expect($content)->toContain('**Name an intermediate result when it genuinely has more than one consumer.**');
    expect($content)->toContain('it never mandates one expression per method');

    // Volume and query shape stay with the rules that already own them.
    expect($content)->toContain('**A chain is not permission to load the set.**');
    expect($content)->toContain('*Bulk Data & Batch Processing (issue #223)*');
});

test('the CR walk and refactoring skill carry the fluent collection pipeline rule at Moderate', function (): void {
    $packageDir = dirname(__DIR__, 2);

    $walk = (string) file_get_contents($packageDir . '/rules/code-review/core-analysis.md');
    expect($walk)->toContain('- **Collection pipelines are fluent (Laravel)**');
    expect($walk)->toContain('Severity: **Moderate**');
    expect($walk)->toContain('The **Suggested Fix** is the chained rewrite itself, written out.');
    // Owns the pipeline's shape only; volume, per-row queries and query shape keep their owners.
    expect($walk)->toContain('this bullet owns the *shape* of the pipeline and nothing else');

    $skill = (string) file_get_contents($packageDir . '/skills/class-refactoring/SKILL.md');
    expect($skill)->toContain('**Chain collection operations into one fluent pipeline.**');
    expect($skill)->toContain('In `MODE=cr`, emit the chained rewrite as a written proposal rather than applying it.');
});

test('architecture rules keep the HTTP request out of an Action __invoke()', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/laravel/architecture.md');

    // The rule, stated as the input-side mirror of the HTTP-response ban.
    expect($content)->toContain('- **accept the HTTP request** — no `Illuminate\Http\Request` parameter');
    expect($content)->toContain('no reach for the request from inside `__invoke()` (`request()`, the `Request` facade, `app(\'request\')`)');
    expect($content)->toContain('the controller owns client↔server communication in **both** directions');

    // The three costs the rule names, so a reader sees why it is Critical.
    expect($content)->toContain('**binds the use case to HTTP**');
    expect($content)->toContain('**hides the input contract**');
    expect($content)->toContain('**reopens the trust boundary**');

    // The fix, and the values that are not the request.
    expect($content)->toContain('The controller converts the request into the payload first');
    expect($content)->toContain('Concrete values extracted from the request are not the request');

    // Critical, matching its output-side mirror.
    expect($content)->toContain('an Action `__invoke()` that **accepts the HTTP request**');
    expect($content)->toContain('This is the input-side mirror of the HTTP-response finding below and carries its severity for the same reason');

    // Exactly one rule definition; the severity entry cross-references it rather than restating
    // it, which is how the two would otherwise drift into disagreeing severities.
    expect(substr_count($content, '- **accept the HTTP request**'))->toBe(1);
    expect($content)->toContain('(see **Action Rules** → *accept the HTTP request*)');
});

test('the CR walk and refactor skill keep the request out of the extracted Action signature', function (): void {
    $packageDir = dirname(__DIR__, 2);

    $walk = (string) file_get_contents($packageDir . '/rules/code-review/core-analysis.md');
    expect($walk)->toContain('- **Action accepts the HTTP request (Action pattern)**');
    expect($walk)->toContain('Severity: **Critical**');
    expect($walk)->toContain('no HTTP request, no `Request::create()`, no route dispatch');
    // The walk's Action Rules checklist names it, so the mandatory walk actually checks it.
    expect($walk)->toContain('**no `Request` / `FormRequest` parameter and no `request()` reach inside the body**');
    // Gated against the one-DTO rule that owns the other half of the same signature.
    expect($walk)->toContain('the request is this finding, the second DTO is that one');

    $skill = (string) file_get_contents($packageDir . '/skills/refactor-entry-point-to-action/SKILL.md');
    expect($skill)->toContain('- **Never pass the HTTP request into the Action.**');
    expect($skill)->toContain('the entry point keeps both ends of client↔server communication');
});
