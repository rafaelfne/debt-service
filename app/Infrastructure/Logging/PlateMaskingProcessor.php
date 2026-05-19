<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masks Brazilian licence plates (old + Mercosul) anywhere in a log record:
 * message text, context values (incl. nested arrays) and extra values.
 *
 * The pattern matches a 7-char plate as a whole word, case-insensitive, and
 * replaces the match with `ABC****` (first 3 letters uppercased + four
 * asterisks). Non-string scalars and objects pass through untouched — the
 * processor is defensive on purpose because Monolog records can carry
 * arbitrary structure.
 *
 * CLAUDE.md §4 #8 / §11 #9 — LGPD by construction.
 */
final class PlateMaskingProcessor implements ProcessorInterface
{
    private const PATTERN = '/\b([A-Za-z]{3})[0-9][A-Za-z0-9][0-9]{2}\b/';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->maskString($record->message),
            context: $this->maskArray($record->context),
            extra: $this->maskArray($record->extra),
        );
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private function maskArray(array $data): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            is_array($value) => $this->maskArray($value),
            is_string($value) => $this->maskString($value),
            default => $value,
        }, $data);
    }

    private function maskString(string $value): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $matches): string => strtoupper($matches[1]).'****',
            $value,
        );
    }
}
