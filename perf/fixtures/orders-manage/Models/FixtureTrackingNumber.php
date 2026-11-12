<?php

namespace Livewire\Performance\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Livewire\Performance\Fixtures\Support\Seed;
use Sushi\Sushi;

class FixtureTrackingNumber extends Model
{
    use Sushi;

    protected $guarded = [];

    public $timestamps = false;

    public function getRows(): array
    {
        $rows = [];

        for ($i = 1; $i <= 120; $i++) {
            $rows[] = [
                "id" => $i,
                "line_id" => 1 + Seed::h("tl", $i, 240),
                "carrier" => Seed::pick("tc", $i, ["CarrierA", "CarrierB"]),
                "number" => sprintf("TRK%010d", $i * 7919),
            ];
        }

        return $rows;
    }
}
