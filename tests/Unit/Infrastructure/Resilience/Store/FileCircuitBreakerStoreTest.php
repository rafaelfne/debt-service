<?php

declare(strict_types=1);

use App\Infrastructure\Resilience\Store\CircuitBreakerState;
use App\Infrastructure\Resilience\Store\FileCircuitBreakerStore;

function tmpStorePath(string $name): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'cb-store-'.uniqid('', true).DIRECTORY_SEPARATOR.$name.'.json';
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'cb-store-*', GLOB_ONLYDIR) ?: [] as $dir) {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

it('returns initial state when no file exists yet', function (): void {
    $store = new FileCircuitBreakerStore(tmpStorePath('provider-a'));

    $state = $store->load();

    expect($state->failureCount)->toBe(0);
    expect($state->openedAt)->toBeNull();
});

it('round-trips failure_count and opened_at through the JSON file', function (): void {
    $path = tmpStorePath('provider-a');
    $store = new FileCircuitBreakerStore($path);

    $store->save(new CircuitBreakerState(failureCount: 4, openedAt: 1716203212.5));

    $reloaded = $store->load();

    expect($reloaded->failureCount)->toBe(4);
    expect($reloaded->openedAt)->toBe(1716203212.5);
});

it('creates the parent directory on first save', function (): void {
    $path = tmpStorePath('provider-a');
    expect(is_dir(dirname($path)))->toBeFalse();

    (new FileCircuitBreakerStore($path))->save(new CircuitBreakerState(failureCount: 1));

    expect(is_file($path))->toBeTrue();
});

it('shares state across separate store instances pointing at the same file (cross-worker simulation)', function (): void {
    $path = tmpStorePath('provider-a');

    $workerOne = new FileCircuitBreakerStore($path);
    $workerOne->save(new CircuitBreakerState(failureCount: 3));

    $workerTwo = new FileCircuitBreakerStore($path);

    expect($workerTwo->load()->failureCount)->toBe(3);
});

it('falls back to initial state when the file is corrupted', function (): void {
    $path = tmpStorePath('provider-a');
    @mkdir(dirname($path), 0o775, true);
    file_put_contents($path, 'not valid json{');

    $store = new FileCircuitBreakerStore($path);

    $state = $store->load();

    expect($state->failureCount)->toBe(0);
    expect($state->openedAt)->toBeNull();
});

it('persists null openedAt distinctly from a numeric one', function (): void {
    $path = tmpStorePath('provider-a');
    $store = new FileCircuitBreakerStore($path);

    $store->save(new CircuitBreakerState(failureCount: 7, openedAt: null));
    expect($store->load()->openedAt)->toBeNull();

    $store->save(new CircuitBreakerState(failureCount: 7, openedAt: 0.0));
    expect($store->load()->openedAt)->toBe(0.0);
});
