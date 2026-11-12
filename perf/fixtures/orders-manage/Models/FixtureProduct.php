<?php

namespace Livewire\Performance\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Livewire\Performance\Fixtures\Support\Seed;
use Sushi\Sushi;

class FixtureProduct extends Model
{
    use Sushi;

    protected $guarded = [];

    public $timestamps = false;

    public function getRows(): array
    {
        $rows = [];

        for ($i = 1; $i <= 150; $i++) {
            $rows[] = [
                "id" => $i,
                "type_id" => 1 + Seed::h("pt", $i, 3),
                "part_number" => sprintf("PN-%06d", 100 + $i * 7),
                "name" => Seed::words("pn", $i, 4),
                "list_price" => Seed::money("lp", $i),
            ];
        }

        return $rows;
    }
}
