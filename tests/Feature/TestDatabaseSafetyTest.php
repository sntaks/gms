<?php

it('runs against the in-memory sqlite database and never a real one', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});

it('ignores the application caches that could override the test environment', function (): void {
    expect(app()->getCachedConfigPath())->toEndWith('config.testing.php')
        ->and(app()->configurationIsCached())->toBeFalse();
});

it('never reaches the Kadi site database from tests', function (): void {
    expect(config('database.connections.kadi_site.driver'))->toBe('sqlite')
        ->and(config('database.connections.kadi_site.database'))->toBe(':memory:');
});
