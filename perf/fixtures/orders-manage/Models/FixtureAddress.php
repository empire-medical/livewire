<?php

namespace Livewire\Performance\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Livewire\Performance\Fixtures\Support\Seed;
use Sushi\Sushi;

class FixtureAddress extends Model
{
    use Sushi;

    protected $guarded = [];

    public $timestamps = false;

    public function getRows(): array
    {
        $rows = [];

        for ($i = 1; $i <= 20; $i++) {
            $rows[] = [
                "id" => $i,
                "name" => sprintf("Synthetic Ship-To %02d", $i),
                "street_address" => sprintf("%d Example Street", 100 + $i),
                "city" => "Testville",
                "state_id" => 1 + Seed::h("st", $i, 50),
                "zipcode" => sprintf("%05d", 10000 + $i),
                "blacklist_all_vendors" => 0,
                "blacklist_all_manufacturers" => 0,
                "status_id" => 1,
                "is_ship_to" => 1,
            ];
        }

        return $rows;
    }
}
