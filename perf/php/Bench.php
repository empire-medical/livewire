<?php

namespace Livewire\Performance;

// Shared helpers for perf benchmarks. Results are written as JSON to perf/results/<PERF_LABEL>-<name>.json
trait Bench
{
    /** Time $fn over $iterations (after $warmup), return stats in milliseconds + memory. */
    protected function measure(string $name, callable $fn, int $iterations = 100, int $warmup = 10): array
    {
        for ($i = 0; $i < $warmup; $i++) $fn();

        gc_collect_cycles();
        $times = [];
        $peakBefore = memory_get_peak_usage();

        for ($i = 0; $i < $iterations; $i++) {
            $t = hrtime(true);
            $fn();
            $times[] = (hrtime(true) - $t) / 1e6;
        }

        sort($times);
        $n = count($times);
        $stats = [
            'name' => $name,
            'iterations' => $n,
            'median_ms' => round($times[intdiv($n, 2)], 4),
            'mean_ms' => round(array_sum($times) / $n, 4),
            'p95_ms' => round($times[(int) floor($n * 0.95)], 4),
            'min_ms' => round($times[0], 4),
            'peak_mem_delta_kb' => round((memory_get_peak_usage() - $peakBefore) / 1024, 1),
        ];

        $dir = __DIR__.'/../results';
        @mkdir($dir, 0777, true);
        $label = getenv('PERF_LABEL') ?: 'adhoc';
        $file = $dir.'/'.$label.'-'.preg_replace('/[^a-z0-9_-]+/i', '_', $name).'.json';
        file_put_contents($file, json_encode($stats, JSON_PRETTY_PRINT));

        fwrite(STDERR, sprintf("  [%s] %-34s median %8.3f ms  mean %8.3f  p95 %8.3f\n", $label, $name, $stats['median_ms'], $stats['mean_ms'], $stats['p95_ms']));

        return $stats;
    }

    /** Mount a component and return its decoded snapshot (what the browser would send back). */
    protected function snapshotFor(string $class, array $params = []): array
    {
        $html = app('livewire')->mount($class, $params);
        preg_match('/wire:snapshot="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1]), true);
    }
}
