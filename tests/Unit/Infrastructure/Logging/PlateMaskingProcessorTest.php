<?php

declare(strict_types=1);

use App\Infrastructure\Logging\PlateMaskingProcessor;
use Monolog\Level;
use Monolog\LogRecord;

function recordWith(string $message = 'hi', array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: $message,
        context: $context,
        extra: $extra,
    );
}

beforeEach(function (): void {
    $this->processor = new PlateMaskingProcessor;
});

it('masks a top-level plate value in context', function (): void {
    $record = recordWith(context: ['plate' => 'ABC1234']);

    $masked = ($this->processor)($record);

    expect($masked->context['plate'])->toBe('ABC****');
});

it('masks a plate nested deep inside arrays', function (): void {
    $record = recordWith(context: ['data' => ['nested' => 'XYZ9W87']]);

    $masked = ($this->processor)($record);

    expect($masked->context['data']['nested'])->toBe('XYZ****');
});

it('masks a plate embedded inside a longer string in the message', function (): void {
    $record = recordWith(message: 'Failed for ABC1234 after retries');

    $masked = ($this->processor)($record);

    expect($masked->message)->toBe('Failed for ABC**** after retries');
});

it('masks Mercosul plates too (ABC1D23)', function (): void {
    $record = recordWith(context: ['plate' => 'ABC1D23', 'wat' => 'normal text']);

    $masked = ($this->processor)($record);

    expect($masked->context['plate'])->toBe('ABC****')
        ->and($masked->context['wat'])->toBe('normal text');
});

it('uppercases the prefix when the plate arrived lowercase', function (): void {
    $record = recordWith(context: ['plate' => 'abc1234']);

    $masked = ($this->processor)($record);

    expect($masked->context['plate'])->toBe('ABC****');
});

it('masks multiple plates in the same string', function (): void {
    $record = recordWith(message: 'ABC1234 collided with XYZ9W87 in the chain');

    $masked = ($this->processor)($record);

    expect($masked->message)->toBe('ABC**** collided with XYZ**** in the chain');
});

it('also masks values living in extra (not just context)', function (): void {
    $record = recordWith(extra: ['vehicle' => 'ABC1234']);

    $masked = ($this->processor)($record);

    expect($masked->extra['vehicle'])->toBe('ABC****');
});

it('passes through non-string scalars and objects untouched (int, bool, object)', function (): void {
    $object = (object) ['unrelated' => 'noop'];
    $record = recordWith(context: [
        'count' => 42,
        'active' => true,
        'thing' => $object,
        'maybe' => null,
    ]);

    $masked = ($this->processor)($record);

    expect($masked->context['count'])->toBe(42)
        ->and($masked->context['active'])->toBeTrue()
        ->and($masked->context['thing'])->toBe($object)
        ->and($masked->context['maybe'])->toBeNull();
});

it('does NOT mask strings that just look short or wrong', function (): void {
    $record = recordWith(context: [
        'too_short' => 'ABC123',
        'too_long' => 'ABCD1234',
        'no_letter_prefix' => '1ABC234',
        'normal' => 'A regular sentence with no plate.',
    ]);

    $masked = ($this->processor)($record);

    expect($masked->context['too_short'])->toBe('ABC123')
        ->and($masked->context['too_long'])->toBe('ABCD1234')
        ->and($masked->context['no_letter_prefix'])->toBe('1ABC234')
        ->and($masked->context['normal'])->toBe('A regular sentence with no plate.');
});

it('preserves the original record fields it does not touch (level, channel, datetime)', function (): void {
    $original = recordWith(context: ['plate' => 'ABC1234']);

    $masked = ($this->processor)($original);

    expect($masked->level)->toBe($original->level)
        ->and($masked->channel)->toBe($original->channel)
        ->and($masked->datetime)->toBe($original->datetime);
});
