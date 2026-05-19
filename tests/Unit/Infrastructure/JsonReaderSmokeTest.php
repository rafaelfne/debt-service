<?php

declare(strict_types=1);

use pcrov\JsonReader\JsonReader;

it('preserves float tokens as strings when FLOATS_AS_STRINGS is set', function (): void {
    $reader = new JsonReader(JsonReader::FLOATS_AS_STRINGS);
    $reader->json('{"valor": 1500.33, "taxa": 0.33}');

    $tokens = [];
    while ($reader->read()) {
        if ($reader->type() === JsonReader::NUMBER) {
            $tokens[$reader->name()] = $reader->value();
        }
    }
    $reader->close();

    expect($tokens['valor'])->toBe('1500.33');
    expect($tokens['taxa'])->toBe('0.33');
});

it('returns quoted decimal strings verbatim', function (): void {
    $reader = new JsonReader;
    $reader->json('{"valor": "1500.33"}');

    $reader->read();
    $reader->read();

    expect($reader->type())->toBe(JsonReader::STRING);
    expect($reader->name())->toBe('valor');
    expect($reader->value())->toBe('1500.33');

    $reader->close();
});
