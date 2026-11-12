<?php

namespace Livewire\Performance\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Livewire\Performance\Fixtures\Support\Seed;
use Sushi\Sushi;

class FixtureVendor extends Model
{
    use Sushi;

    protected $guarded = [];

    public $timestamps = false;

    public function getRows(): array
    {
        $rows = [];

        for ($i = 1; $i <= 40; $i++) {
            $rows[] = [
                "id" => $i,
                "name" => sprintf("Synthetic %s %02d", $i < 20 ? "Vendor" : "Manufacturer", $i),
                "is_manufacturer" => $i >= 20 ? 1 : 0,
                "order_method_id" => 1 + Seed::h("vom", $i, 5),
                "lead_time_days" => Seed::h("lt", $i, 10),
                "notes" => Seed::words("vn", $i, 8),
            ];
        }

        return $rows;
    }
}
