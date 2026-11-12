<?php

namespace Livewire\Performance\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Livewire\Performance\Fixtures\Support\Seed;
use Sushi\Sushi;

class FixtureWarehouse extends Model
{
    use Sushi;

    protected $guarded = [];

    public $timestamps = false;

    public function getRows(): array
    {
        $rows = [];

        for ($i = 1; $i <= 8; $i++) {
            $rows[] = [
                "id" => $i,
                "vendor_id" => $i <= 4 ? 1 : 2 + $i,
                "name" => sprintf("Synthetic Warehouse %d", $i),
                "cutoff_time_id" => 1 + Seed::h("ct", $i, 4),
            ];
        }

        return $rows;
    }
}
