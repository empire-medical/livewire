<?php

namespace Livewire\Performance\Fixtures;

require_once __DIR__.'/autoload.php';

use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase;

/** Run: vendor/bin/phpunit perf/fixtures/orders-manage/SmokeTest.php */
class SmokeTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [LivewireServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('livewire.inject_morph_markers', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Registrar::register(withRoute: false);
    }

    public function test_renders_and_reports_shape(): void
    {
        $component = Livewire::test(OrdersManage::class);
        $html = $component->html();

        $rows = substr_count($html, 'data-testid="row-select-');
        $comments = substr_count($html, '<!--[if');
        $xData = substr_count($html, 'x-data');
        $tr = substr_count($html, '<tr');

        fwrite(STDERR, sprintf("\nrows=%d tr=%d x-data=%d morph-comments=%d html-bytes=%d\n", $rows, $tr, $xData, $comments, strlen($html)));

        $this->assertSame(200, $rows);

        $snapshot = json_encode($component->snapshot ?? []);
        fwrite(STDERR, 'snapshot-bytes='.strlen($snapshot)."\n");
    }

    public function test_expanded_rows_mount_lazy_children(): void
    {
        $component = Livewire::test(OrdersManage::class)->call('toggleExpanded', 1)->call('toggleExpanded', 2);
        $this->assertStringContainsString('wire:id', $component->html());
        $component->assertSet('expanded', [1, 2]);
    }
}
