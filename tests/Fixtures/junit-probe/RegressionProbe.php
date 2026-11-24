<?php

declare(strict_types = 1);

it('is red', function (): void {
    expect(array_sum([1, 1]))->toBe(3);
});

test('stays green', function (): void {
    expect(array_sum([1, 1]))->toBe(2);
});
