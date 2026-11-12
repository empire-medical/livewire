<?php

namespace Livewire\Performance;

use Livewire\Livewire;
use Livewire\Performance\Fixtures\OrdersManage;
use Livewire\Performance\Fixtures\Registrar;
use Tests\TestCase;

require_once __DIR__.'/Bench.php';
require_once __DIR__.'/RoundTripBenchTest.php';
require_once __DIR__.'/../fixtures/orders-manage/autoload.php';

/**
 * Sampling (Excimer) profiles: low overhead, so tiny-call-heavy code is not distorted the way xdebug distorts it.
 * Run under the `profile` service with XDEBUG_MODE=off:
 *   docker compose -f docker/docker-compose.yml run --rm -e XDEBUG_MODE=off profile vendor/bin/phpunit perf/php/SampleProfileTest.php
 * Output: perf/results/sample/<name>.collapsed  (summarise with perf/tools/collapsed_top.py)
 */
class SampleProfileTest extends TestCase
{
    protected function sample(string $name, callable $fn, int $iterations): void
    {
        for ($i = 0; $i < 20; $i++) $fn();

        $p = new \ExcimerProfiler;
        $p->setPeriod(0.0001);
        $p->setEventType(EXCIMER_CPU);
        $p->start();
        for ($i = 0; $i < $iterations; $i++) $fn();
        $p->stop();

        @mkdir(__DIR__.'/../results/sample', 0777, true);
        file_put_contents(__DIR__."/../results/sample/$name.collapsed", $p->getLog()->formatCollapsed());
    }

    public function test_sample_counter()
    {
        Livewire::component('bench-counter', SimpleCounter::class);
        $snapshot = (new class { use Bench; public function s($c) { return $this->snapshotFor($c); } })->s(SimpleCounter::class);

        $this->sample('counter', fn () => app('livewire')->update($snapshot, [], [['method' => 'increment', 'params' => []]]), 6000);
        $this->assertTrue(true);
    }

    public function test_sample_child()
    {
        Registrar::register(withRoute: false);
        $c = Livewire::test(OrdersManage::class, ['perPage' => 5]);
        $c->set('expanded', [1, 2]);
        preg_match_all('/wire:snapshot="([^"]+)"/', $c->html(), $m);
        $child = json_decode(html_entity_decode($m[1][1]), true);

        $this->sample('child', fn () => app('livewire')->update($child, [], [['method' => 'markChanged', 'params' => []]]), 3000);
        $this->assertTrue(true);
    }

    public function test_sample_root()
    {
        Registrar::register(withRoute: false);
        $root = Livewire::test(OrdersManage::class)->snapshot;

        $this->sample('root', fn () => app('livewire')->update($root, [], [['method' => 'ping', 'params' => []]]), 80);
        $this->assertTrue(true);
    }
}
