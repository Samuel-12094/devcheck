<?php

declare(strict_types=1);

namespace DevCheck;

/**
 * Collecte et agrege les resultats de tous les tests.
 */
final class Report
{
    /** @var list<Result> */
    private array $results = [];

    /** Duree en ms par nom de suite. */
    private array $timings = [];

    private float $startedAt;
    private float $durationMs = 0.0;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public function add(Result ...$results): void
    {
        foreach ($results as $r) {
            $this->results[] = $r;
        }
    }

    public function seal(): void
    {
        $this->durationMs = (microtime(true) - $this->startedAt) * 1000;
    }

    public function addTiming(string $suite, float $ms): void
    {
        $this->timings[$suite] = round($ms, 1);
    }

    /** @return array<string,float> */
    public function timings(): array
    {
        return $this->timings;
    }

    /** @return list<Result> */
    public function results(): array
    {
        return $this->results;
    }

    /** @return list<Result> */
    public function failures(): array
    {
        return array_values(array_filter($this->results, static fn (Result $r) => $r->isFailure()));
    }

    /** @return array<string,list<Result>> */
    public function byCategory(): array
    {
        $out = [];
        foreach ($this->results as $r) {
            $out[$r->category][] = $r;
        }
        foreach ($out as &$items) {
            usort($items, static fn (Result $a, Result $b) => $a->severity() <=> $b->severity());
        }
        unset($items);
        return $out;
    }

    /** @return array{ok:int,warn:int,ko:int,skip:int,info:int,total:int} */
    public function summary(): array
    {
        $s = ['ok' => 0, 'warn' => 0, 'ko' => 0, 'skip' => 0, 'info' => 0, 'total' => count($this->results)];
        foreach ($this->results as $r) {
            $s[$r->status] = ($s[$r->status] ?? 0) + 1;
        }
        return $s;
    }

    /** Score de sante 0-100 : les echecs ponderent plus que les avertissements. */
    public function score(): int
    {
        $s = $this->summary();
        $den = $s['ok'] + $s['warn'] + $s['ko'];
        if ($den === 0) {
            return 0;
        }
        return (int) round((($s['ok'] + $s['warn'] * 0.5) / $den) * 100);
    }

    public function durationMs(): float
    {
        return $this->durationMs;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'generated_at' => gmdate('c'),
            'duration_ms'  => round($this->durationMs, 1),
            'score'        => $this->score(),
            'summary'      => $this->summary(),
            'results'      => array_map(static fn (Result $r) => $r->toArray(), $this->results),
        ];
    }
}
