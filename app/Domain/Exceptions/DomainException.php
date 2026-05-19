<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Base class for every domain-level exception. Lets the central HTTP handler
 * (bootstrap/app.php) catch one stable type and dispatch on concrete subclass
 * to map each to a status code without sprinkling try/catch in controllers.
 */
abstract class DomainException extends RuntimeException {}
