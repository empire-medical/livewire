<?php

namespace Livewire\Performance;

use Livewire\Livewire;
use Livewire\Performance\Fixtures\OrdersManage;
use Livewire\Performance\Fixtures\Registrar;
use Tests\TestCase;

require_once __DIR__.'/Bench.php';
require_once __DIR__.'/../fixtures/orders-manage/autoload.php';

/** Under the `profile` service: N child-component updates on the fixture expanded row (PERF_N, default 200). */
class ProfileChildTest extends TestCase
{
    public function test_profile_child_update()
    {
        Registrar::register(withRoute: false);
        $c = Livewire::test(OrdersManage::class, ['perPage' => 5]);
        $c->set('expanded', [1, 2]);
        preg_match_all('/wire:snapshot="([^"]+)"/', $c->html(), $m);
        $child = json_decode(html_entity_decode($m[1][1]), true);

        for ($i = 0; $i < (int) (getenv('PERF_N') ?: 200); $i++) {
            app('livewire')->update($child, [], [['method' => 'markChanged', 'params' => []]]);
        }

        $this->assertTrue(true);
    }
}
