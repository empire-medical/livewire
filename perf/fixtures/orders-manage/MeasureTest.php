<?php

namespace Livewire\Performance\Fixtures;

require_once __DIR__.'/autoload.php';

use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase;

/** Prints shape numbers for the README. Run: vendor/bin/phpunit perf/fixtures/orders-manage/MeasureTest.php */
class MeasureTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [LivewireServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    public function test_measure(): void
    {
        Registrar::register(withRoute: false);

        foreach ([0, 10, 200] as $expandedCount) {
            $c = Livewire::test(OrdersManage::class);
            if ($expandedCount) {
                $c->set('expanded', range(1, $expandedCount));
            }
            $html = $c->html();
            preg_match('/data-rows="([^"]*)"/', $html, $m);
            $snap = json_encode($c->snapshot);
            fwrite(STDERR, sprintf(
                "expanded=%d html=%d data-rows=%d root-snapshot=%d comments=%d wire:key=%d x-data=%d lazy-children=%d\n",
                $expandedCount, strlen($html), strlen($m[1] ?? ''), strlen($snap),
                substr_count($html, '<!--[if'), substr_count($html, 'wire:key'), substr_count($html, 'x-data'),
                substr_count($html, 'wire:snapshot') - 1
            ));
        }

        $html = Livewire::test(OrdersManage::class)->html(); $a = strpos($html, "<tbody>"); $b = strpos($html, "</tbody>"); $body = substr($html, $a, $b - $a); fwrite(STDERR, sprintf("tbody=%d ws-only-bytes=%d one-row~%d\n", strlen($body), strlen($body) - strlen(preg_replace("/\\s+/", "", $body)), intdiv(strlen($body), 200))); $this->assertTrue(true);
    }
}
