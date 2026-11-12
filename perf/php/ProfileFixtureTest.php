<?php

namespace Livewire\Performance;

use Livewire\Livewire;
use Livewire\Performance\Fixtures\OrdersManage;
use Livewire\Performance\Fixtures\Registrar;
use Tests\TestCase;

require_once __DIR__.'/Bench.php';
require_once __DIR__.'/../fixtures/orders-manage/autoload.php';

/** Under the `profile` service: one root update on the fixture (PERF_ROWS rows, default 30). */
class ProfileFixtureTest extends TestCase
{
    public function test_profile_root_ping()
    {
        Registrar::register(withRoute: false);
        $c = Livewire::test(OrdersManage::class, ['perPage' => (int) (getenv('PERF_ROWS') ?: 30)]);
        $root = $c->snapshot;

        app('livewire')->update($root, [], [['method' => 'ping', 'params' => []]]);

        $this->assertTrue(true);
    }
}
