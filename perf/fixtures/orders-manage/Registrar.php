<?php

namespace Livewire\Performance\Fixtures;

use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

final class Registrar
{
    /**
     * Call once from a service provider / test setUp. Registers view namespace `perf-fixtures`,
     * the two components, and (optionally) a page route. Requires inject_morph_markers=true
     * (the Livewire default) to reproduce the prod comment-marker load.
     */
    public static function register(bool $withRoute = true): void
    {
        require_once __DIR__.'/autoload.php';

        view()->addNamespace('perf-fixtures', __DIR__.'/views');

        Livewire::component('perf-fixtures.orders-manage', OrdersManage::class);
        Livewire::component('perf-fixtures.expanded-row', ExpandedRow::class);

        if ($withRoute) {
            Route::get('/perf/orders-manage', OrdersManage::class)->name('perf.orders-manage');
        }
    }
}
