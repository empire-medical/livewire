<?php

namespace Livewire\Performance\Fixtures;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Performance\Fixtures\Forms\FilterForm;
use Livewire\Performance\Fixtures\Models\FixtureAddress;
use Livewire\Performance\Fixtures\Models\FixtureLine;
use Livewire\Performance\Fixtures\Models\FixtureVendor;
use Livewire\Performance\Fixtures\Models\FixtureWarehouse;
use Livewire\WithPagination;

/**
 * Synthetic stand-in for the app's OrdersManage page. Public property shape mirrors the
 * real component; rows come from a #[Computed] (never in the snapshot).
 */
#[Layout('perf-fixtures::layout')]
class OrdersManage extends Component
{
    use WithPagination;

    public array $sortBy = ['column' => 'vendor.name', 'direction' => 'asc'];

    public ?int $vendorId = 1;

    #[Url(except: '')]
    public ?int $shippingAddressId = null;

    /** @var list<int> */
    public array $selected = [];

    /** @var list<int> */
    public array $expanded = [];

    public int $perPage = 200;

    public int $pageNumber = 1;

    public bool $tablePreferencesModal = false;

    public bool $multipleTablesOnPage = false;

    public array $defaultSortBy = ['column' => 'vendor.name', 'direction' => 'asc'];

    public array $toggleableTableHeaderState = [
        'patients' => true, 'part_number' => true, 'description' => true, 'manufacturer__name' => true,
        'quantity' => true, 'cost' => true, 'price' => true, 'date_needed' => true,
        'date_expected' => true, 'warehouse_name' => true,
    ];

    public FilterForm $searchFilterForm;

    public bool $initialLoadComplete = false;

    public ?string $type = '';

    public bool $showEmpireAccounts = true;

    /** One 16-key assoc array per rendered row under ['lines'], like populateOrdersManageQuickbarData(). */
    public array $ordersManageSidebarData = [
        'lines' => [],
        'vendors' => [],
        'addressVendors' => [],
        'warehouses' => [],
    ];

    public function render()
    {
        return view('perf-fixtures::orders-manage');
    }

    /** Not in the snapshot. Side effect (filling the sidebar prop) mirrors the app's tableResults() hook. */
    #[Computed]
    public function tableRows()
    {
        $query = FixtureLine::query()->where('vendor_id', $this->vendorId);

        if ($this->shippingAddressId) {
            $query->where('shipping_address_id', $this->shippingAddressId);
        }

        // "vendor.name" ties on every row of a vendor page, so the app adds an id tiebreak.
        $query->orderBy('vendor_id', $this->sortBy['direction'] === 'desc' ? 'desc' : 'asc')->orderBy('id');

        $rows = $query->paginate($this->perPage);

        $lines = [];

        foreach ($rows as $row) {
            $lines[$row->id] = [
                'id' => $row->id,
                'cost' => $row->cost,
                'statusId' => $row->status_id,
                'priceAfterRebate' => $row->price_after_rebate,
                'quantity' => $row->quantity,
                'partNumber' => $row->part_number,
                'vendorId' => $row->vendor_id,
                'facilityId' => $row->facility_id,
                'purchaseOrderId' => $row->purchase_order_id,
                'purchaseOrderNote' => $row->purchase_order_note,
                'warehouseId' => $row->warehouse_id,
                'categoryId' => $row->category_id,
                'manufacturerId' => $row->manufacturer_id,
                'dateExpected' => $row->date_expected?->format('Y-m-d'),
                'shippingAddressId' => $row->shipping_address_id,
                'costCenterId' => $row->cost_center_id,
                'hasRequiredNotes' => (bool) $row->has_required_notes,
            ];
        }

        $this->ordersManageSidebarData = [
            'lines' => $lines,
            'vendors' => [],
            'addressVendors' => [],
            'warehouses' => [],
        ];

        $this->selected = array_values(array_intersect($this->selected, array_keys($lines)));

        return $rows;
    }

    // ---- ~22 more computeds, read repeatedly by the view (per row and per header) ----

    #[Computed]
    public function pageTitle(): string
    {
        return 'Orders - Open'.($this->shippingAddressId ? ': '.FixtureAddress::whereKey($this->shippingAddressId)->value('name') : '');
    }

    #[Computed]
    public function tableHeaders(): array
    {
        $all = [
            ['key' => 'actions', 'label' => '', 'class' => 'w-1', 'sortable' => false, 'persistent' => true],
            ['key' => 'id', 'label' => '#', 'persistent' => true],
            ['key' => 'patients', 'label' => 'Patient', 'class' => 'w-[12%]'],
            ['key' => 'part_number', 'label' => 'Part #', 'class' => 'w-[10%]'],
            ['key' => 'description', 'label' => 'Description', 'class' => 'w-[18%]'],
            ['key' => 'manufacturer__name', 'label' => 'Manufacturer', 'class' => 'w-[10%]'],
            ['key' => 'quantity', 'label' => 'Qty', 'class' => 'w-[4%]'],
            ['key' => 'cost', 'label' => 'Cost', 'class' => 'w-[4%]'],
            ['key' => 'price', 'label' => 'Price', 'class' => 'w-[4%]'],
            ['key' => 'date_needed', 'label' => 'Date Needed', 'class' => 'w-[6%]'],
            ['key' => 'date_expected', 'label' => 'Date Expected', 'class' => 'w-[6%]'],
            ['key' => 'warehouse_name', 'label' => 'Warehouse', 'class' => 'w-[9%]'],
        ];

        return array_values(array_filter($all, fn ($h) => ($h['persistent'] ?? false) || ($this->toggleableTableHeaderState[$h['key']] ?? false)));
    }

    #[Computed]
    public function cellDecoration(): array
    {
        return ['part_number' => 'p-0', 'date_needed' => 'p-0', 'date_expected' => 'p-0'];
    }

    #[Computed]
    public function pageIds(): array
    {
        return $this->tableRows->pluck('id')->all();
    }

    #[Computed]
    public function allIds(): array
    {
        return $this->pageIds;
    }

    #[Computed]
    public function vendorNames(): array
    {
        return FixtureVendor::pluck('name', 'id')->all();
    }

    #[Computed]
    public function manufacturerNames(): array
    {
        return $this->vendorNames;
    }

    #[Computed]
    public function warehouseNames(): array
    {
        return FixtureWarehouse::pluck('name', 'id')->all();
    }

    #[Computed]
    public function statusLabels(): array
    {
        return [1 => 'Pending', 2 => 'Review', 3 => 'Open', 4 => 'Ordered'];
    }

    #[Computed]
    public function categoryNames(): array
    {
        return collect(range(1, 12))->mapWithKeys(fn ($i) => [$i => "Synthetic Category $i"])->all();
    }

    #[Computed]
    public function costCenterNames(): array
    {
        return FixtureAddress::pluck('name', 'id')->all();
    }

    #[Computed]
    public function orderMethodNames(): array
    {
        return [1 => 'Email', 2 => 'Portal', 3 => 'Phone', 4 => 'Fax', 5 => 'EDI'];
    }

    #[Computed]
    public function facilityNames(): array
    {
        return collect(range(1, 30))->mapWithKeys(fn ($i) => [$i => "Synthetic Facility $i"])->all();
    }

    #[Computed]
    public function expandedLookup(): array
    {
        return array_flip($this->expanded);
    }

    #[Computed]
    public function selectedLookup(): array
    {
        return array_flip($this->selected);
    }

    #[Computed]
    public function rowClasses(): array
    {
        $map = [];

        foreach ($this->pageIds as $id) {
            $map[$id] = $id % 2 ? 'zebra-odd' : 'zebra-even';
        }

        return $map;
    }

    #[Computed]
    public function alignmentClasses(): array
    {
        return ['quantity' => 'text-right', 'cost' => 'text-right', 'price' => 'text-right'];
    }

    #[Computed]
    public function columnWidths(): array
    {
        return collect($this->tableHeaders)->mapWithKeys(fn ($h) => [$h['key'] => $h['class'] ?? ''])->all();
    }

    #[Computed]
    public function sortableKeys(): array
    {
        return collect($this->tableHeaders)->filter(fn ($h) => $h['sortable'] ?? true)->pluck('key')->all();
    }

    #[Computed]
    public function colspan(): int
    {
        return count($this->tableHeaders) + 2;
    }

    #[Computed]
    public function requiredNoteLineIds(): array
    {
        return collect($this->ordersManageSidebarData['lines'])->where('hasRequiredNotes', true)->keys()->all();
    }

    #[Computed]
    public function groundShippingLineIds(): array
    {
        return $this->tableRows->filter(fn ($r) => $r->is_ground_shipping)->pluck('id')->all();
    }

    public function toggleExpanded(int $id): void
    {
        $this->expanded = in_array($id, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$id]))
            : [...$this->expanded, $id];
    }

    public function expandAll(): void
    {
        $this->expanded = $this->pageIds;
    }

    public function collapseAll(): void
    {
        $this->expanded = [];
    }

    public function sort(string $column): void
    {
        $this->sortBy = ['column' => $column, 'direction' => $this->sortBy['direction'] === 'asc' ? 'desc' : 'asc'];
    }

    /** No-op action: forces a full root round trip + re-render, for benchmarking a bare request. */
    public function ping(): void {}
}
