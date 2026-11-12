<?php

namespace Livewire\Performance\Js;

use Illuminate\Support\Facades\Route;
use Livewire\Performance\Fixtures\OrdersManage;
use Livewire\Performance\Fixtures\Registrar;
use Tests\TestCase;

require_once __DIR__.'/../fixtures/orders-manage/autoload.php';

/**
 * Recorder: renders the fixture page through the real route and records the exact JSON bodies that
 * POST /livewire/update would return for a few scenarios. Output: perf/results/js/artifacts/
 *   page.html, ping.json, sort.json, expand10.json, select.json, lazy.json (lazy child loads keyed by memo id)
 * Run through perf/js/run.sh (or: vendor/bin/phpunit perf/js/RecordArtifactsTest.php).
 */
class RecordArtifactsTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('livewire.inject_morph_markers', getenv('PERF_MARKERS') !== '0');
    }

    public function setUp(): void
    {
        parent::setUp();
        Registrar::register(withRoute: false);
        Route::middleware('web')->get('/perf/orders-manage', OrdersManage::class);
    }

    protected function out(string $name): string
    {
        $dir = __DIR__.'/../results/js/artifacts';
        @mkdir($dir, 0777, true);

        return $dir.'/'.$name;
    }

    /** All wire:snapshot attribute values in $html, decoded. Index 0 is the root component. */
    protected function snapshots(string $html): array
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);

        return array_map(fn ($s) => html_entity_decode($s), $m[1]);
    }

    protected function envelope(string $rootSnapshotJson, array $updates, array $calls, array &$lazy): array
    {
        [$snapshot, $effects] = app('livewire')->update(json_decode($rootSnapshotJson, true), $updates, $calls);

        $this->collectLazy($effects['html'] ?? '', $lazy);

        return [
            'components' => [['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'effects' => $effects]],
            'assets' => \Livewire\Features\SupportScriptsAndAssets\SupportScriptsAndAssets::getAssets(),
        ];
    }

    /** For each lazy placeholder in $html, record the response to its __lazyLoad request, keyed by memo id. */
    protected function collectLazy(string $html, array &$lazy): void
    {
        preg_match_all('/<[^>]*x-intersect="\$wire\.__lazyLoad\(&#039;([^&]+)&#039;\)"[^>]*wire:snapshot="([^"]+)"|<[^>]*wire:snapshot="([^"]+)"[^>]*x-intersect="\$wire\.__lazyLoad\(&#039;([^&]+)&#039;\)"/', $html, $mm, PREG_SET_ORDER);

        foreach ($mm as $m) {
            $encoded = $m[1] !== '' ? $m[1] : $m[4];
            $snapJson = html_entity_decode($m[2] !== '' ? $m[2] : $m[3]);
            $snap = json_decode($snapJson, true);
            $id = $snap['memo']['id'];
            if (isset($lazy[$id])) continue;

            [$newSnap, $effects] = app('livewire')->update($snap, [], [['method' => '__lazyLoad', 'params' => [$encoded]]]);
            $lazy[$id] = [
                'components' => [['snapshot' => json_encode($newSnap, JSON_THROW_ON_ERROR), 'effects' => $effects]],
                'assets' => [],
            ];
        }
    }

    public function test_record(): void
    {
        $res = $this->get('/perf/orders-manage');
        $res->assertOk();
        $html = $res->getContent();

        // Point the script at the harness server and set the update URI.
        $html = preg_replace('#<script src="[^"]*livewire(?:\.min)?\.js[^"]*"#', '<script src="/dist/livewire.js"', $html, -1, $n);
        $this->assertSame(1, $n, 'livewire script tag not found/replaced');
        $html = preg_replace('#data-update-uri="[^"]*"#', 'data-update-uri="/livewire/update"', $html, -1, $n2);
        $this->assertSame(1, $n2, 'data-update-uri not found');
        file_put_contents($this->out('page.html'), $html);

        $root = $this->snapshots($html)[0];
        $lazy = [];
        $call = fn (string $m, array $p = []) => [['method' => $m, 'params' => $p]];

        $scenarios = [
            'ping' => [[], $call('ping')],
            'sort' => [[], $call('sort', ['part_number'])],
            'expand10' => [[], array_map(fn ($i) => ['method' => 'toggleExpanded', 'params' => [$i]], $this->firstIds(10))],
            'select' => [['selected' => [1, 2, 3]], []],
        ];

        // The fixture's sort() ties on every row (see its README), so it never reorders. For the `sort`
        // scenario only, a global scope on the fixture model reverses the same 200 rows so the morph has
        // to move every keyed row (the worst realistic case for keyed morphing).
        $pageIds = $this->firstIds(200);
        $reorder = false;
        \Livewire\Performance\Fixtures\Models\FixtureLine::addGlobalScope('perf-reorder', function ($q) use (&$reorder, $pageIds) {
            if ($reorder) $q->whereIn('id', $pageIds)->reorder()->orderBy('id', 'desc');
        });

        foreach ($scenarios as $name => [$updates, $calls]) {
            $reorder = $name === 'sort';
            $env = $this->envelope($root, $updates, $calls, $lazy);
            $reorder = false;
            file_put_contents($this->out($name.'.json'), json_encode($env));
        }

        file_put_contents($this->out('lazy.json'), json_encode((object) $lazy));
        fwrite(STDERR, sprintf("  recorded %d scenarios, %d lazy responses, page %d bytes\n", count($scenarios), count($lazy), strlen($html)));
        $this->assertNotEmpty($lazy, 'expand10 should have produced lazy children');
    }

    protected function firstIds(int $n): array
    {
        $c = \Livewire\Livewire::test(OrdersManage::class);

        return array_slice($c->instance()->pageIds, 0, $n);
    }
}
