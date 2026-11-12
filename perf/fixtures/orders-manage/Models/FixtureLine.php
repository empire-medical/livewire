<?php

namespace Livewire\Performance\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Livewire\Performance\Fixtures\Support\Seed;
use Sushi\Sushi;

/** Synthetic stand-in for an order line: 26 columns, 300 rows (240 for fixture vendor 1). */
class FixtureLine extends Model
{
    use Sushi;

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'date_needed' => 'date',
        'date_expected' => 'date',
        'is_ground_shipping' => 'boolean',
    ];

    public function getRows(): array
    {
        $rows = [];
        $total = Seed::LINES_FOR_FIXTURE_VENDOR + Seed::OTHER_LINES;

        for ($i = 1; $i <= $total; $i++) {
            $isFixtureVendor = $i <= Seed::LINES_FOR_FIXTURE_VENDOR;
            $qty = Seed::h('qty', $i, 12) + 1;
            $cost = Seed::money('cost', $i);
            $markup = round(1.05 + Seed::h('markup', $i, 400) / 1000, 3);

            $rows[] = [
                'id' => $i,
                'vendor_id' => $isFixtureVendor ? Seed::FIXTURE_VENDOR_ID : 2 + Seed::h('vendor', $i, 11),
                'shipping_address_id' => 1 + Seed::h('ship', $i, 20),
                'facility_id' => 1 + Seed::h('facility', $i, 30),
                'cost_center_id' => 1 + Seed::h('cc', $i, 20),
                'product_id' => 1 + Seed::h('product', $i, 150),
                'manufacturer_id' => 20 + Seed::h('mfr', $i, 15),
                'warehouse_id' => 1 + Seed::h('wh', $i, 8),
                'category_id' => 1 + Seed::h('cat', $i, 12),
                'purchase_order_id' => Seed::h('po', $i, 4) === 0 ? 1000 + $i : null,
                'order_method_id' => 1 + Seed::h('om', $i, 5),
                'status_id' => 3,
                'part_number' => sprintf('PN-%06d', 100 + $i * 7),
                'description' => Seed::words('desc', $i, 5),
                'patients_text' => sprintf('Patient %04d, Patient %04d', Seed::h('pa', $i, 9000), Seed::h('pb', $i, 9000)),
                'quantity' => $qty,
                'cost' => $cost,
                'markup' => $markup,
                'price' => round($cost * $markup, 2),
                'price_after_rebate' => round($cost * $markup * 0.97, 2),
                'date_needed' => Seed::h('dn', $i, 3) === 0 ? null : Seed::date('dn', $i),
                'date_expected' => Seed::date('de', $i),
                'is_ground_shipping' => Seed::h('gs', $i, 5) === 0 ? 1 : 0,
                'purchase_order_note' => Seed::h('pon', $i, 6) === 0 ? 'Synthetic PO note '.$i : null,
                'has_required_notes' => Seed::h('rn', $i, 7) === 0 ? 1 : 0,
                'ordered_at' => Seed::date('oa', $i, 30).' 12:00:00',
            ];
        }

        return $rows;
    }
}
