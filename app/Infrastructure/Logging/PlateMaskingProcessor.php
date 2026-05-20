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
    /**
     * Left boundary is either a normal word boundary OR a consumed SGR escape
     * (`\033[...m`). Without the SGR branch, plates embedded in ANSI-colored
     * tracer lines like `\033[95mABC1234\033[0m` leak in clear: the `m` of
     * `\033[95m` is a word character touching `A`, so plain `\b` fails.
     *
     * The branch consumes the SGR (rather than using a lookbehind) because
     * PCRE2 < 10.40 — still common on Linux distros — rejects variable-length
     * lookbehinds (`{0,16}` quantifier) with a compilation error. The
     * consumed prefix is restored verbatim in the callback. Right boundary
     * keeps `\b` because plates end in a digit which naturally borders the
     * next ESC (non-word) or whitespace.
     */
    private const PATTERN = '/(\b|\033\[[\d;]{0,16}m)([A-Za-z]{3})[0-9][A-Za-z0-9][0-9]{2}\b/';

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
            static fn (array $matches): string => $matches[1].strtoupper($matches[2]).'****',
            $value,
        );
    }
}
