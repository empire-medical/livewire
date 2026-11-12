<?php

namespace Livewire\Performance\Fixtures;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Performance\Fixtures\Forms\ExpandedRowForm;
use Livewire\Performance\Fixtures\Models\FixtureAddress;
use Livewire\Performance\Fixtures\Models\FixtureAddressVendor;
use Livewire\Performance\Fixtures\Models\FixtureLine;
use Livewire\Performance\Fixtures\Models\FixtureProduct;
use Livewire\Performance\Fixtures\Models\FixtureTrackingNumber;
use Livewire\Performance\Fixtures\Models\FixtureVendor;
use Livewire\Performance\Fixtures\Models\FixtureWarehouse;

/**
 * Stand-in for orders.expanded-table-row-content: #[Lazy], ~9 public Eloquent model props,
 * a ~47-field form, and (like the app) the line itself kept out of the snapshot as a #[Computed].
 */
#[Lazy]
#[On('shipping-label-created')]
class ExpandedRow extends Component
{
    public ExpandedRowForm $form;

    #[Locked]
    public int $lineId;

    public ?array $currentProductExplanation = null;

    public bool $unsavedChanges = false;

    public bool $highlightCost = false;

    public bool $highlightPrice = false;

    public string $purchaseOrderIdWithSuffix = '';

    public ?FixtureAddress $shippingAddress = null;

    public ?FixtureAddressVendor $shippingAddressVendor = null;

    public ?FixtureAddressVendor $shippingAddressManufacturer = null;

    public ?FixtureProduct $product = null;

    public ?FixtureVendor $manufacturer = null;

    public ?FixtureVendor $vendor = null;

    public ?FixtureVendor $shippingEstimateSubject = null;

    public ?FixtureWarehouse $vendorManufacturerWarehouse = null;

    public ?FixtureWarehouse $warehouse = null;

    public ?Collection $trackingNumbers = null;

    public ?Carbon $dateExpected = null;

    public ?string $dateNeededProxy = null;

    public ?string $dateExpectedProxy = null;

    public string $placeholderClass = 'skeleton h-[550px]';

    public function placeholder()
    {
        return '<div class="'.$this->placeholderClass.'">loading</div>';
    }

    #[Computed]
    public function line(): ?FixtureLine
    {
        return FixtureLine::find($this->lineId);
    }

    public function mount(): void
    {
        $line = $this->line;

        if ($line === null) {
            return;
        }

        $this->form->fill(collect($line->toArray())->only([
            'id', 'vendor_id', 'facility_id', 'cost_center_id', 'product_id', 'manufacturer_id', 'warehouse_id',
            'category_id', 'order_method_id', 'status_id', 'purchase_order_id', 'shipping_address_id', 'quantity',
            'part_number', 'description', 'purchase_order_note',
        ])->all());

        $this->form->cost = (string) $line->cost;
        $this->form->price = (string) $line->price;
        $this->form->markup = (string) $line->markup;
        $this->form->date_needed = $line->date_needed?->format('Y-m-d');
        $this->form->date_expected = $line->date_expected?->format('Y-m-d');
        $this->form->patients = [1, 2];
        $this->form->meta = ['source' => 'fixture', 'tags' => ['a', 'b', 'c']];

        $this->dateNeededProxy = $this->form->date_needed;
        $this->dateExpectedProxy = $this->form->date_expected;
        $this->dateExpected = $line->date_expected;
        $this->purchaseOrderIdWithSuffix = $line->purchase_order_id ? $line->purchase_order_id.'-A' : '';

        $this->shippingAddress = FixtureAddress::find($line->shipping_address_id);
        $this->shippingAddressVendor = FixtureAddressVendor::where('vendor_id', $line->vendor_id)->first();
        $this->shippingAddressManufacturer = FixtureAddressVendor::where('vendor_id', $line->manufacturer_id)->first();
        $this->product = FixtureProduct::find($line->product_id);
        $this->manufacturer = FixtureVendor::find($line->manufacturer_id);
        $this->vendor = FixtureVendor::find($line->vendor_id);
        $this->shippingEstimateSubject = $this->vendor;
        $this->warehouse = FixtureWarehouse::find($line->warehouse_id);
        $this->vendorManufacturerWarehouse = $this->warehouse;
        $this->trackingNumbers = FixtureTrackingNumber::where('line_id', $line->id)->get();
        $this->currentProductExplanation = ['summary' => 'Synthetic explanation', 'rows' => [1, 2, 3]];
        $this->highlightCost = $line->cost > 500;
    }

    public function save(): void
    {
        $this->unsavedChanges = false;
        $this->dispatch('Orders.refresh');
    }

    public function markChanged(): void
    {
        $this->unsavedChanges = true;
    }

    public function applyStandardPrice(): void
    {
        $this->form->price = (string) round(((float) $this->form->cost) * ((float) $this->form->markup), 2);
    }

    public function cancel(): void
    {
        $this->unsavedChanges = false;
    }

    public function addNote(): void
    {
        $this->form->purchase_order_note = trim(($this->form->purchase_order_note ?? '').' note');
    }

    public function clearNote(): void
    {
        $this->form->purchase_order_note = null;
    }

    public function refreshTracking(): void
    {
        $this->trackingNumbers = FixtureTrackingNumber::where('line_id', $this->lineId)->get();
    }

    public function render()
    {
        return $this->line === null
            ? '<div>removed</div>'
            : view('perf-fixtures::expanded-row');
    }
}
