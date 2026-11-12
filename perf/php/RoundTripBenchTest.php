<?php

namespace Livewire\Performance;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

require_once __DIR__.'/Bench.php';

/**
 * Server-side update round trip, shaped like a computed-heavy 200-row table (see CODEMAP section 1c).
 * Run: vendor/bin/phpunit perf/php/RoundTripBenchTest.php   (label results with PERF_LABEL=baseline|fork)
 */
class RoundTripBenchTest extends TestCase
{
    use Bench;

    public function test_update_roundtrip_computed_heavy_table()
    {
        Livewire::component('bench-table', ComputedHeavyTable::class);
        $snapshot = $this->snapshotFor(ComputedHeavyTable::class);

        $this->measure('roundtrip_call_computed_table_200rows', function () use ($snapshot) {
            app('livewire')->update($snapshot, [], [['method' => 'noop', 'params' => []]]);
        }, iterations: 60, warmup: 5);

        $this->assertTrue(true);
    }

    public function test_update_roundtrip_property_update_only()
    {
        Livewire::component('bench-table', ComputedHeavyTable::class);
        $snapshot = $this->snapshotFor(ComputedHeavyTable::class);

        $this->measure('roundtrip_prop_update_computed_table_200rows', function () use ($snapshot) {
            app('livewire')->update($snapshot, ['search' => 'x'], []);
        }, iterations: 60, warmup: 5);

        $this->assertTrue(true);
    }

    public function test_computed_access_cost_in_isolation()
    {
        Livewire::component('bench-table', ComputedHeavyTable::class);
        $component = Livewire::new('bench-table');

        // 30 computeds on the class; read a memoised one 10k times.
        $this->measure('computed_read_x10000', function () use ($component) {
            for ($i = 0; $i < 10000; $i++) { $component->c15; }
        }, iterations: 20, warmup: 3);

        $this->assertTrue(true);
    }

    public function test_simple_counter_update()
    {
        Livewire::component('bench-counter', SimpleCounter::class);
        $snapshot = $this->snapshotFor(SimpleCounter::class);

        // The fixed per-component cost every request pays (hooks, synths, checksum, tiny render)...
        $this->measure('counter_update_fixed_overhead', function () use ($snapshot) {
            app('livewire')->update($snapshot, [], [['method' => 'increment', 'params' => []]]);
        }, iterations: 400, warmup: 20);

        $this->assertTrue(true);
    }

    public function test_mount_only()
    {
        Livewire::component('bench-table', ComputedHeavyTable::class);

        $this->measure('mount_computed_table_200rows', function () {
            app('livewire')->mount(ComputedHeavyTable::class);
        }, iterations: 40, warmup: 3);

        $this->assertTrue(true);
    }
}

class ComputedHeavyTable extends Component
{
    public string $search = '';
    public array $selected = [];
    public array $expanded = [];
    public int $perPage = 200;
    public array $sortBy = ['column' => 'pn', 'direction' => 'asc'];
    public array $sidebar = [];

    public function mount()
    {
        foreach ($this->rows as $row) {
            $this->sidebar['lines'][$row['id']] = array_slice($row, 0, 16, true);
        }
    }

    public function noop() {}

    #[Computed] public function rows(): array
    {
        $rows = [];
        for ($i = 1; $i <= $this->perPage; $i++) {
            $row = ['id' => $i];
            for ($c = 1; $c <= 24; $c++) $row["f$c"] = "PN-".str_pad((string) ($i * $c), 6, '0', STR_PAD_LEFT);
            $rows[] = $row;
        }
        return $rows;
    }

    // 30 trivial computeds, mimicking classes with many #[Computed] properties...
    #[Computed] public function c1() { return true; }
    #[Computed] public function c2() { return 'a'; }
    #[Computed] public function c3() { return 3; }
    #[Computed] public function c4() { return [1, 2]; }
    #[Computed] public function c5() { return false; }
    #[Computed] public function c6() { return 'b'; }
    #[Computed] public function c7() { return 7; }
    #[Computed] public function c8() { return true; }
    #[Computed] public function c9() { return 'c'; }
    #[Computed] public function c10() { return 10; }
    #[Computed] public function c11() { return true; }
    #[Computed] public function c12() { return 'd'; }
    #[Computed] public function c13() { return 13; }
    #[Computed] public function c14() { return [3]; }
    #[Computed] public function c15() { return 15; }
    #[Computed] public function c16() { return true; }
    #[Computed] public function c17() { return 'e'; }
    #[Computed] public function c18() { return 18; }
    #[Computed] public function c19() { return false; }
    #[Computed] public function c20() { return 'f'; }
    #[Computed] public function c21() { return 21; }
    #[Computed] public function c22() { return true; }
    #[Computed] public function c23() { return 'g'; }
    #[Computed] public function c24() { return 24; }
    #[Computed] public function c25() { return true; }
    #[Computed] public function c26() { return 'h'; }
    #[Computed] public function c27() { return 27; }
    #[Computed] public function c28() { return [4]; }
    #[Computed] public function c29() { return true; }
    #[Computed] public function c30() { return 'i'; }

    public function render()
    {
        return <<<'HTML'
        <div>
            <table>
                <tbody>
                @foreach ($this->rows as $row)
                    <tr wire:key="row-{{ $row['id'] }}">
                        @if ($this->c1)<td><input type="checkbox"></td>@endif
                        @if ($this->c11)<td>{{ $row['f1'] }}</td>@endif
                        @if ($this->c22 && $this->c25)<td>{{ $row['f2'] }}</td>@endif
                        @foreach (['f3','f4','f5','f6','f7','f8','f9','f10','f11','f12'] as $col)
                            @if ($this->c29)<td class="{{ $this->c2 }} {{ $this->c6 }}">{{ $row[$col] }}</td>@endif
                        @endforeach
                        <td>{{ $this->c3 + $this->c7 + $this->c10 + $this->c13 + $this->c15 + $this->c18 + $this->c21 + $this->c24 + $this->c27 }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        HTML;
    }
}

class SimpleCounter extends Component
{
    public int $count = 0;
    public string $name = 'x';
    public array $items = ['a', 'b', 'c'];

    public function increment() { $this->count++; }

    public function render() { return '<div><span>{{ $count }}</span><button wire:click="increment">+</button></div>'; }
}
