<?php

declare(strict_types=1);

use App\Application\Ports\DebtProvider;
use Tests\Support\Fakes\FakeDebtProvider;

beforeEach(function (): void {
    // I8.3 swapped the closure for DebtsController, which resolves the full
    // chain (HTTP-bound providers). Stub it so happy-path requests stay
    // self-contained to the FormRequest + middleware behaviour we care about.
    $this->app->instance(DebtProvider::class, FakeDebtProvider::withDebts([]));
});

it('accepts a request with a valid plate', function (): void {
    $this->postJson('/api/debts/query', ['placa' => 'ABC1234'])
        ->assertOk()
        ->assertJsonPath('placa', 'ABC1234');
});

it('passes lowercase plates through the controller (normalised by Plate VO)', function (): void {
    $this->postJson('/api/debts/query', ['placa' => 'abc1d23'])
        ->assertOk()
        ->assertJsonPath('placa', 'ABC1D23');
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
    // unknown_fields fires BEFORE max_body_size triggers, since the body is
    // well under the limit but contains an extra `noise` key.
    $payload = ['placa' => 'ABC1234', 'noise' => str_repeat('x', 1_000_000)];

    $this->postJson('/api/debts/query', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error', 'unknown_fields');
});
