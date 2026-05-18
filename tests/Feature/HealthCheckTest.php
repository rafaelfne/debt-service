<?php

declare(strict_types=1);

it('responds to the api health route', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});
