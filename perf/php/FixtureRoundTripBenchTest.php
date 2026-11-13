<?php

namespace Livewire\Performance;

use Livewire\Livewire;
use Livewire\Performance\Fixtures\OrdersManage;
use Livewire\Performance\Fixtures\Registrar;
use Tests\TestCase;

require_once __DIR__.'/Bench.php';
require_once __DIR__.'/../fixtures/orders-manage/autoload.php';

/**
 * Update round trips on the synthetic Orders Manage fixture (see perf/fixtures/orders-manage/README.md).
 * Run through perf/ab.sh for A/B against the v4.4.8 baseline.
 */
class FixtureRoundTripBenchTest extends TestCase
{
    use Bench;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        // PERF_MARKERS=0 renders without morph markers, to measure what the markers cost on the server...
        $app['config']->set('livewire.inject_morph_markers', getenv('PERF_MARKERS') !== '0');
    }

    protected function rootAndChildSnapshots(int $expanded): array
    {
        Registrar::register(withRoute: false);

        $c = Livewire::test(OrdersManage::class);
        if ($expanded) $c->set('expanded', range(1, $expanded));

        preg_match_all('/wire:snapshot="([^"]+)"/', $c->html(), $m);
        $snaps = array_map(fn ($s) => json_decode(html_entity_decode($s), true), $m[1]);

        return [$c->snapshot, array_slice($snaps, 1)];
    }

    public function test_root_ping_no_expanded()
    {
        [$root] = $this->rootAndChildSnapshots(0);

        $this->measure('fx_root_ping_200rows', fn () => app('livewire')->update($root, [], [['method' => 'ping', 'params' => []]]), iterations: 30, warmup: 3);
        $this->assertTrue(true);
    }

    public function test_root_property_update_selected()
    {
        [$root] = $this->rootAndChildSnapshots(0);

        $this->measure('fx_root_set_selected_200rows', fn () => app('livewire')->update($root, ['selected' => [1, 2, 3]], []), iterations: 30, warmup: 3);
        $this->assertTrue(true);
    }

    public function test_root_with_10_expanded()
    {
        [$root] = $this->rootAndChildSnapshots(10);

        $this->measure('fx_root_ping_10expanded', fn () => app('livewire')->update($root, [], [['method' => 'ping', 'params' => []]]), iterations: 20, warmup: 3);
        $this->assertTrue(true);
    }

    public function test_child_action()
    {
        [, $children] = $this->rootAndChildSnapshots(3);
        $child = $children[0];

        $this->measure('fx_child_markChanged', fn () => app('livewire')->update($child, [], [['method' => 'markChanged', 'params' => []]]), iterations: 40, warmup: 5);
        $this->assertTrue(true);
    }

    public function test_children_batch()
    {
        [, $children] = $this->rootAndChildSnapshots(40);
        $children = array_slice($children, 0, 30);
        $this->assertGreaterThanOrEqual(30, count($children));

        // What "Expand All" costs on the server per HTTP request: N child components updated in one go...
        $this->measure('fx_children_batch_30', function () use ($children) {
            foreach ($children as $child) app('livewire')->update($child, [], [['method' => 'markChanged', 'params' => []]]);
        }, iterations: 10, warmup: 2);
    }

    public function test_initial_mount_page()
    {
        Registrar::register(withRoute: false);

        $this->measure('fx_mount_root_200rows', fn () => app('livewire')->mount(OrdersManage::class), iterations: 15, warmup: 2);
        $this->assertTrue(true);
    }
}
