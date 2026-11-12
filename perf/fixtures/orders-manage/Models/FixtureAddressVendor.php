<?php

namespace Livewire\Performance\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Livewire\Performance\Fixtures\Support\Seed;
use Sushi\Sushi;

class FixtureAddressVendor extends Model
{
    use Sushi;

    protected $guarded = [];

    public $timestamps = false;

    public function getRows(): array
    {
        $rows = [];

        for ($i = 1; $i <= 60; $i++) {
            $rows[] = [
                "id" => $i,
                "address_id" => 1 + Seed::h("avA", $i, 20),
                "vendor_id" => 1 + Seed::h("avV", $i, 40),
                "account_number" => sprintf("ACCT-%05d", $i),
                "ship_via" => Seed::pick("sv", $i, ["Ground", "2-Day", "Overnight"]),
            ];
        }

        return $rows;
    }
}
