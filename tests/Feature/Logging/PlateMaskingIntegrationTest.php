<?php

declare(strict_types=1);

use App\Infrastructure\Logging\PlateMaskingProcessor;
use App\Infrastructure\Logging\TapPlateMasking;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

it('masks plates in messages and context end-to-end through the tap + Monolog', function (): void {
    $handler = new TestHandler;
    $logger = new Logger('test', [$handler]);

    (new TapPlateMasking)($logger);

    $logger->info('Failed for ABC1234', ['plate' => 'XYZ9W87']);

    $record = $handler->getRecords()[0];

    expect($record->message)->toBe('Failed for ABC****')
        ->and($record->context['plate'])->toBe('XYZ****');
});

it('masks a Mercosul plate end-to-end', function (): void {
    $handler = new TestHandler;
    $logger = new Logger('test', [$handler]);

    (new TapPlateMasking)($logger);

    $logger->warning('Provider rejected', ['placa' => 'ABC1D23']);

    expect($handler->getRecords()[0]->context['placa'])->toBe('ABC****');
});

it('TapPlateMasking pushes a fresh PlateMaskingProcessor to every handler', function (): void {
    $logger = new Logger('test', [new TestHandler, new TestHandler]);

    (new TapPlateMasking)($logger);

    foreach ($logger->getHandlers() as $handler) {
        // AbstractProcessingHandler doesn't expose getProcessors() publicly in
        // Monolog 3, so we read the inherited `processors` property by reflection.
        $reflection = new ReflectionClass($handler);
        $property = $reflection->getProperty('processors');
        $property->setAccessible(true);
        $processors = $property->getValue($handler);

        expect($processors)->toHaveCount(1);
        expect($processors[0])->toBeInstanceOf(PlateMaskingProcessor::class);
    }
});

it('every leaf channel in config/logging.php declares TapPlateMasking', function (): void {
    foreach (['single', 'daily', 'stderr', 'slack', 'syslog', 'errorlog', 'papertrail'] as $channel) {
        $tap = config("logging.channels.{$channel}.tap", []);

        expect($tap)->toContain(TapPlateMasking::class);
    }
});

it('does not require tap on the `stack` channel — Laravel applies inner channels` taps', function (): void {
    // Sanity-check: the stack composes other channels, so it should NOT declare
    // a tap itself (would double-mask if it did). Test fails if someone adds one.
    expect(config('logging.channels.stack.tap', []))->toBe([]);
});
