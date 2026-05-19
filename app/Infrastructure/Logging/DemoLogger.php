<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Application\Ports\QueryTracer;
use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;

/**
 * Demo-time tracer. Emits one concise line per significant step of a request
 * to a dedicated `demo` log channel that writes to stderr. When `enabled` is
 * false (production), every method is a no-op — zero overhead.
 *
 * Color: each line starts with a `\033[0m` reset to cancel the `<fg=gray>`
 * wrap that Laravel's ServeCommand applies to worker stderr; then key
 * tokens (symbols, names, status) are colored inline with raw ANSI SGR
 * codes that pass through Symfony's tag parser untouched.
 *
 * Plates passed through `info` calls are masked by the global
 * {@see PlateMaskingProcessor} so this remains LGPD-safe.
 */
final class DemoLogger implements QueryTracer
{
    private const RESET = "\033[0m";

    private const BOLD = "\033[1m";

    private const DIM = "\033[2m";

    private const RED = "\033[91m";

    private const GREEN = "\033[92m";

    private const YELLOW = "\033[93m";

    private const BLUE = "\033[94m";

    private const MAGENTA = "\033[95m";

    private const CYAN = "\033[96m";

    private ?LoggerInterface $log;

    public function __construct(bool $enabled, LogManager $manager)
    {
        $this->log = $enabled ? $manager->channel('demo') : null;
    }

    public function requestStarted(string $method, string $path, string $plate): void
    {
        $arrow = self::CYAN.'→'.self::RESET;
        $label = self::BOLD.$method.' /'.$path.self::RESET;
        $placa = self::MAGENTA.$plate.self::RESET;
        $this->emit("{$arrow} {$label}  placa={$placa}");
    }

    public function chainStarted(int $providerCount, float $budgetSeconds): void
    {
        $arrow = self::CYAN.'→'.self::RESET;
        $budget = self::DIM.'budget='.number_format($budgetSeconds, 1).'s'.self::RESET;
        $this->emit("{$arrow} ProviderChain: trying {$providerCount} providers ({$budget})");
    }

    public function providerSucceeded(string $name, int $debtCount, float $elapsedSeconds): void
    {
        $check = self::GREEN.'✓'.self::RESET;
        $named = self::BOLD.$name.self::RESET;
        $elapsed = self::DIM.'elapsed '.number_format($elapsedSeconds, 2).'s'.self::RESET;
        $this->emit("  {$check} {$named} returned {$debtCount} debts ({$elapsed})");
    }

    public function providerFailed(string $name, string $reason, float $elapsedSeconds): void
    {
        $cross = self::RED.'✗'.self::RESET;
        $named = self::BOLD.$name.self::RESET;
        $elapsed = self::DIM.'elapsed '.number_format($elapsedSeconds, 2).'s'.self::RESET;
        $this->emit("  {$cross} {$named} failed: {$reason} ({$elapsed})");
    }

    public function circuitOpen(string $name): void
    {
        $bolt = self::YELLOW.'⚡'.self::RESET;
        $named = self::BOLD.$name.self::RESET;
        $open = self::YELLOW.'OPEN'.self::RESET;
        $this->emit("  {$bolt} circuit-{$named} {$open} — skipping");
    }

    public function budgetExceeded(float $budgetSeconds, int $attempts): void
    {
        $clock = self::YELLOW.'⏱'.self::RESET;
        $budget = self::YELLOW.number_format($budgetSeconds, 1).'s'.self::RESET;
        $this->emit("  {$clock} budget of {$budget} exceeded after {$attempts} attempts");
    }

    public function interestCalculated(int $debtCount): void
    {
        $check = self::GREEN.'✓'.self::RESET;
        $this->emit("{$check} calculated interest for {$debtCount} debts");
    }

    /**
     * @param  list<string>  $optionNames
     */
    public function paymentsSimulated(array $optionNames): void
    {
        $check = self::GREEN.'✓'.self::RESET;
        $count = count($optionNames);
        $names = self::DIM.implode(', ', $optionNames).self::RESET;
        $this->emit("{$check} simulated payments: {$count} options [{$names}]");
    }

    public function requestCompleted(int $statusCode, float $totalSeconds): void
    {
        $color = match (true) {
            $statusCode >= 500 => self::RED,
            $statusCode >= 400 => self::YELLOW,
            $statusCode >= 300 => self::BLUE,
            default => self::GREEN,
        };
        $arrow = $color.'←'.self::RESET;
        $status = self::BOLD.$color.$statusCode.self::RESET;
        $total = self::DIM.'total '.number_format($totalSeconds, 2).'s'.self::RESET;
        $this->emit("{$arrow} {$status} ({$total})");
        $this->emit('');
    }

    /**
     * Prefixes every line with `\033[0m` so Laravel's ServeCommand
     * `<fg=gray>` wrap doesn't tint the parts we don't explicitly color.
     */
    private function emit(string $message): void
    {
        $this->log?->info(self::RESET.$message);
    }
}
