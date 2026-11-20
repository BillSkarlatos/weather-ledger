<?php

declare(strict_types=1);

namespace App;

use Closure;
use DateTimeImmutable;
use Throwable;

/** Runs a daily job once its time has passed, retries until it succeeds, and catches up after a restart. */
final class Scheduler
{
    private ?DateTimeImmutable $lastAttempt = null;
    private ?string $completedDate = null;

    /** @param Closure(string $date): bool $job */
    public function __construct(private readonly Closure $job, private readonly string $dailyAt, private readonly int $retryMinutes)
    {
    }

    public function tick(DateTimeImmutable $now): void
    {
        $today = $now->format('Y-m-d');
        $due = $this->completedDate !== $today
            && $now->format('H:i') >= $this->dailyAt
            && ($this->lastAttempt === null || $now >= $this->lastAttempt->modify("+{$this->retryMinutes} minutes"));
        if (!$due) {
            return;
        }

        $this->lastAttempt = $now;
        try {
            if (($this->job)($today)) {
                $this->completedDate = $today;
            }
        } catch (Throwable $e) {
            App::log('error', 'Scheduled run crashed', ['error' => $e->getMessage()]);
        }
    }
}
