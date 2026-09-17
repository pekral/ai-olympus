<?php

declare(strict_types = 1);

test('the PHP standards require private visibility for a method only its own class calls', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $standards = (string) file_get_contents($packageDir . '/rules/php/core-standards.md');

    expect($standards)->toContain('**A method called only from inside its own class is `private`.**');
    expect($standards)->toContain('A test is never a caller for this decision');
    expect($standards)->toContain('**The method keeps a wider visibility when something outside the project\'s own calls needs it:**');

    $structureStart = (int) strpos($standards, '## Structure');
    $codeStyleStart = (int) strpos($standards, '## Code Style');
    $rulePosition = (int) strpos($standards, '**A method called only from inside its own class is `private`.**');

    expect($rulePosition)->toBeGreaterThan($structureStart);
    expect($rulePosition)->toBeLessThan($codeStyleStart);
});

test('the PHP standards forbid widening visibility for a test and name the alternatives', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $standards = (string) file_get_contents($packageDir . '/rules/php/core-standards.md');
    $testingRules = (string) file_get_contents($packageDir . '/rules/code-testing/general.md');

    expect($standards)->toContain('**Never widen visibility for a test.**');
    expect($standards)->toContain('Test the behaviour through the public method that calls the private one');
    expect($standards)->toContain('Extract it into a new class with its own public method, inject that class, and test the new class directly.');
    expect($standards)->toContain('Never change the visibility to make the test pass.');
    expect($standards)->toContain('- Mark as **Critical**: a visibility widened for a test');

    expect($testingRules)->toContain('Never change the visibility of production code so a test can reach it');
});

test('the PHP standards forbid a local variable that adds nothing and list the variables that stay', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $standards = (string) file_get_contents($packageDir . '/rules/php/core-standards.md');

    expect($standards)->toContain('**Do not create a local variable that adds nothing.**');
    expect($standards)->toContain('a variable assigned and then only returned (`$result = $this->calculate($order); return $result;`);');
    expect($standards)->toContain('**A variable stays when it does one of these jobs:**');
    expect($standards)->toContain('Inlining never moves an expensive call above a guard that used to skip it.');
});

test('the code review walks the three rules at their declared severities with gating', function (): void {
    $crRule = codeReviewRuleContents();

    expect($crRule)->toContain('- **Method visibility wider than its callers need:**');
    expect($crRule)->toContain('A call from a test does not count as a caller.');
    expect($crRule)->toContain('the **Visibility widened for a test** bullet below owns it and this bullet raises nothing.');

    expect($crRule)->toContain('- **Visibility widened for a test:**');
    expect($crRule)->toContain('Never propose a different visibility or a reflection-based test as the fix.');

    expect($crRule)->toContain('- **Unnecessary local variable:**');
    expect($crRule)->toContain('**Simplicity First** never raises a single variable.');
});
