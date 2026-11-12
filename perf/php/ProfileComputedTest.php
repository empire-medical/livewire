<?php

namespace Livewire\Performance;

use Livewire\Livewire;
use Tests\TestCase;

require_once __DIR__.'/Bench.php';
require_once __DIR__.'/RoundTripBenchTest.php';

/** Run under the `profile` service to get a cachegrind file for the 200-row computed-heavy update. */
class ProfileComputedTest extends TestCase
{
    use Bench;

    public function test_profile_roundtrip()
    {
        Livewire::component('bench-table', ComputedHeavyTable::class);
        // xdebug writes one record per call, so keep the workload tiny: 1 update, PERF_ROWS rows (default 30)...
        $snapshot = $this->snapshotFor(ComputedHeavyTable::class, ['perPage' => (int) (getenv('PERF_ROWS') ?: 30)]);

        app('livewire')->update($snapshot, [], [['method' => 'noop', 'params' => []]]);

        $this->assertTrue(true);
    }
}
