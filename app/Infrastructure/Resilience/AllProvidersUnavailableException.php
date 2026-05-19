<?php

declare(strict_types=1);

namespace App\Infrastructure\Resilience;

use App\Application\Ports\ProviderUnavailableException;
use RuntimeException;

final class AllProvidersUnavailableException extends RuntimeException
{
    /**
     * @param  list<ProviderUnavailableException>  $errors
     */
    public function __construct(
        public readonly array $errors,
        string $message = 'All providers unavailable',
    ) {
        parent::__construct($message);
    }
}
