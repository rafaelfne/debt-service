<?php

declare(strict_types=1);

it('Provider A mock returns the canonical JSON for ABC1234', function (): void {
    $response = $this->get('/mock/provider-a/debts/ABC1234');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/json');

    $decoded = $response->json();
    expect($decoded['plate'])->toBe('ABC1234')
        ->and($decoded['debts'])->toHaveCount(2)
        ->and($decoded['debts'][0])->toBe(['type' => 'IPVA', 'amount' => '1500.00', 'due_date' => '2024-01-10'])
        ->and($decoded['debts'][1])->toBe(['type' => 'MULTA', 'amount' => '300.50', 'due_date' => '2024-02-15']);
});

it('Provider B mock returns the canonical XML for ABC1234', function (): void {
    $response = $this->get('/mock/provider-b/debts/ABC1234');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/xml');

    $xml = simplexml_load_string($response->getContent());
    expect((string) $xml->plate)->toBe('ABC1234');

    $debts = $xml->debts->debt;
    expect(iterator_count($debts))->toBe(2)
        ->and((string) $debts[0]->type)->toBe('IPVA')
        ->and((string) $debts[0]->amount)->toBe('1500.00')
        ->and((string) $debts[0]->due_date)->toBe('2024-01-10');
});

it('Provider B mock serves the self-closed <debts/> fixture for EMPTY1', function (): void {
    $response = $this->get('/mock/provider-b/debts/EMPTY1');

    $response->assertOk();
    expect($response->getContent())->toContain('<debts/>');
});

it('Provider B mock serves <debts></debts> for EMPTY2', function (): void {
    $response = $this->get('/mock/provider-b/debts/EMPTY2');

    $response->assertOk();
    expect($response->getContent())->toContain('<debts></debts>');
});

it('returns 404 for an unknown plate on Provider A', function (): void {
    $response = $this->get('/mock/provider-a/debts/UNKNOWN');

    $response->assertStatus(404);
});

it('honours ?fail=500 on Provider A', function (): void {
    $response = $this->get('/mock/provider-a/debts/ABC1234?fail=500');

    $response->assertStatus(500);
});

it('honours ?fail=500 on Provider B', function (): void {
    $response = $this->get('/mock/provider-b/debts/ABC1234?fail=500');

    $response->assertStatus(500);
});

it('Provider C mock returns the canonical CSV for ABC1234', function (): void {
    $response = $this->get('/mock/provider-c/debts/ABC1234');

    $response->assertOk();
    // Laravel appends `; charset=utf-8` to text/* responses; just confirm the prefix.
    expect($response->headers->get('Content-Type'))->toStartWith('text/csv');

    $body = $response->getContent();
    expect($body)->toContain('type,amount,due_date')
        ->and($body)->toContain('IPVA,1500.00,2024-01-10')
        ->and($body)->toContain('MULTA,300.50,2024-02-15');
});

it('returns 404 for an unknown plate on Provider C', function (): void {
    $response = $this->get('/mock/provider-c/debts/UNKNOWN');

    $response->assertStatus(404);
});

it('honours ?fail=500 on Provider C', function (): void {
    $response = $this->get('/mock/provider-c/debts/ABC1234?fail=500');

    $response->assertStatus(500);
});
