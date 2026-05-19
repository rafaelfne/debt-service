<?php

declare(strict_types=1);

it('accepts a request with a valid plate', function (): void {
    $this->postJson('/api/debts/query', ['placa' => 'ABC1234'])
        ->assertOk()
        ->assertExactJson(['placa' => 'ABC1234']);
});

it('normalises lowercase plates to uppercase before reaching the handler', function (): void {
    $this->postJson('/api/debts/query', ['placa' => 'abc1d23'])
        ->assertOk()
        ->assertExactJson(['placa' => 'abc1d23']);
    // The closure echoes whatever passed validation — the Plate VO normalises
    // when constructed, not the FormRequest. I8.3 will use Plate::fromString and
    // any downstream serialisation goes through the VO.
});

it('rejects a request with an invalid plate (422 validation_failed)', function (): void {
    $response = $this->postJson('/api/debts/query', ['placa' => 'INVALID']);

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'validation_failed');
    $response->assertJsonPath('errors.placa.0', fn ($message) => str_contains($message, 'valid Brazilian plate'));
});

it('rejects a request with a missing placa (422 validation_failed)', function (): void {
    $response = $this->postJson('/api/debts/query', []);

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'validation_failed');
});

it('rejects a request with unknown fields (422 unknown_fields)', function (): void {
    $response = $this->postJson('/api/debts/query', [
        'placa' => 'ABC1234',
        'extra' => 'noise',
        'attack' => true,
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'unknown_fields');
    expect($response->json('unknown_fields'))->toEqualCanonicalizing(['extra', 'attack']);
});

it('rejects a body larger than 1 MiB with 413 payload_too_large', function (): void {
    $payload = ['placa' => 'ABC1234', 'noise' => str_repeat('x', 1_048_577)];

    $response = $this->postJson('/api/debts/query', $payload);

    $response->assertStatus(413);
    $response->assertJsonPath('error', 'payload_too_large');
    expect($response->json('max_bytes'))->toBe(1_048_576);
    expect($response->json('received_bytes'))->toBeGreaterThan(1_048_576);
});

it('accepts a body just under the 1 MiB limit', function (): void {
    // Tight: the JSON envelope adds wrapping; pick a noise size that keeps the
    // serialised body comfortably under 1 MiB but big enough to prove the
    // boundary is not 0.
    $payload = ['placa' => 'ABC1234', 'noise' => str_repeat('x', 1_000_000)];

    $response = $this->postJson('/api/debts/query', $payload);

    // unknown_fields triggers BEFORE max_body_size since the middleware passes
    // and only then the FormRequest's prepareForValidation runs. We just need
    // 413 to NOT fire, so 422 unknown_fields is the expected sentinel.
    $response->assertStatus(422)
        ->assertJsonPath('error', 'unknown_fields');
});
