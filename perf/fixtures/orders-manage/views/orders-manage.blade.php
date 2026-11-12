{{-- Synthetic Orders Manage page. Table markup is inlined (the app's x-table component is not available
     here) and keeps the ORIGINAL shape: real @if/@foreach (so inject_morph_markers emits comments), 2 <tr> per row,
     wire:key x4 per row (data tr, expansion tr, expansion td, expansion content div; none on data tds), no skip hook,
     no data-row-hash. --}}
@php
    $uuid = 'orders-manage-v'.$vendorId;
    $rows = $this->tableRows;
    $headers = $this->tableHeaders;
    $selectable = true;
    $expandable = true;
    $link = false;
    $actions = false;
    $pageIds = $this->pageIds;
@endphp
<div wire:init="ping">
    <h1>{{ $this->pageTitle }}</h1>

    <div wire:loading.flex wire:target="searchFilterForm">Loading...</div>

    <div wire:loading.remove wire:target="searchFilterForm">
        {{-- Top "pagination": plain text; the app's tailwind links view is not needed for the benchmark. --}}
        @if ($rows->hasPages())
            <div class="pagination">Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}</div>
        @endif

        <div x-data="{
                selection: $wire.entangle('selected'),
                expanded: $wire.entangle('expanded', true),
                get pageIds() { return JSON.parse(this.$refs.tableState.dataset.pageIds) },
                get allIds() { return JSON.parse(this.$refs.tableState.dataset.allIds) },
                get rows() { return JSON.parse(this.$refs.tableState.dataset.rows) },
                colspanSize: 0,
                allExpanded: false,
                init() {
                    this.colspanSize = this.$el.querySelector(':scope > table > thead > tr[x-ref=headers]')?.childElementCount ?? 0
                    this.$nextTick(() => { this.colspanSize = this.$refs.headers?.childElementCount ?? 0 })
                    this.handleCheckAll()
                    this.$watch('selection', () => this.handleCheckAll())
                },
                isExpanded(key) { return this.expanded.includes(key) },
                isPageFullSelected() {
                    if (this.pageIds.length === 0) return false
                    return [...this.selection].sort((a, b) => b - a).toString().includes([...this.pageIds].sort((a, b) => b - a).toString())
                },
                toggleCheck(checked, rowId) {
                    this.$dispatch('row-selection', { row: this.rows[rowId], selected: checked })
                    this.handleCheckAll()
                },
                toggleCheckAll(checked) {
                    checked ? this.pushIds() : this.removeIds()
                    this.$dispatch('check-all-toggled', { selected: checked })
                },
                toggleExpand(key) {
                    this.expanded.includes(key)
                        ? this.expanded = this.expanded.filter(i => i !== key)
                        : this.expanded = [...this.expanded, key]
                },
                pushIds() { this.selection.push(...this.pageIds.filter(i => !this.selection.includes(i))) },
                removeIds() { this.selection = this.selection.filter(i => !this.pageIds.includes(i)) },
                handleCheckAll() {
                    this.$nextTick(() => {
                        if (! this.$refs.mainCheckbox) return
                        this.$refs.mainCheckbox.checked = this.isPageFullSelected()
                    })
                },
                toggleExpanded() {
                    this.allExpanded = !this.allExpanded
                    this.expanded = this.allExpanded ? this.allIds : []
                },
            }"
            class="overflow-x-auto"
            wire:key="{{ $uuid }}-table"
        >
            <template x-ref="tableState"
                data-page-ids="{{ json_encode($pageIds) }}"
                data-all-ids="{{ json_encode($pageIds) }}"
                data-rows="{{ json_encode($rows->items()) }}"></template>

            <table class="table w-auto min-w-full table-zebra border-b-2 mb-12">
                <thead class="text-base">
                    <tr x-ref="headers">
                        @if ($selectable)
                            <th class="w-1 text-center" wire:key="{{ $uuid }}-checkall-{{ implode(',', $pageIds) }}">
                                <input type="checkbox" autocomplete="off" class="checkbox checkbox-sm" x-ref="mainCheckbox" @click="toggleCheckAll($el.checked)" />
                            </th>
                        @endif

                        @if ($expandable)
                            <th class="w-1 text-center" wire:key="{{ $uuid }}-expand-all-{{ implode(',', $pageIds) }}">
                                <span class="cursor-pointer p-2" :class="allExpanded || '-rotate-90'" @click="toggleExpanded()">v</span>
                            </th>
                        @endif

                        @foreach ($headers as $header)
                            <th @class(['cursor-pointer' => in_array($header['key'], $this->sortableKeys), $header['class'] ?? ' ', $this->alignmentClasses[$header['key']] ?? ''])
                                style="{{ $this->columnWidths[$header['key']] ? '' : '' }}"
                                @if (in_array($header['key'], $this->sortableKeys))
                                    @click="$wire.set('sortBy', {column: '{{ $header['key'] }}', direction: '{{ $sortBy['direction'] === 'asc' ? 'desc' : 'asc' }}' })"
                                @endif
                            >
                                {{ $header['label'] }}
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @foreach ($rows as $k => $row)
                        <tr wire:key="{{ $uuid }}-{{ $row->id }}" class="hover:bg-base-200/50 {{ $this->rowClasses[$row->id] ?? '' }}">
                            @if ($selectable)
                                <td class="w-1 text-center">
                                    @if ($row->status_id === 3)
                                        <input id="checkbox-{{ $uuid }}-{{ $row->id }}" type="checkbox" autocomplete="off"
                                            class="checkbox checkbox-sm checkbox-primary" value="{{ $row->id }}"
                                            x-model.number="selection"
                                            @click.stop="toggleCheck($el.checked, '{{ $row->id }}')"
                                            data-testid="row-select-{{ $row->id }}" />
                                    @endif
                                </td>
                            @endif

                            @if ($expandable)
                                <td class="w-1 pe-0">
                                    <span class="cursor-pointer p-2 w-8 h-8 bg-base-300 rounded-lg"
                                        :class="isExpanded({{ $row->id }}) || '-rotate-90 !text-current'"
                                        @click="toggleExpand({{ $row->id }});"
                                        data-testid="row-expand-{{ $row->id }}">v</span>
                                </td>
                            @endif

                            @foreach ($headers as $header)
                                @php $key = $header['key']; @endphp

                                {{-- Mirrors table.blade.php: @if(isset(cell slot)) + two @if($link) per cell. --}}
                                @if (in_array($key, ['actions', 'id', 'patients', 'part_number', 'description', 'manufacturer__name', 'quantity', 'cost', 'price', 'date_needed', 'date_expected', 'warehouse_name']))
                                    <td @class([$this->cellDecoration[$key] ?? '', $this->alignmentClasses[$key] ?? '', 'p-0' => $link])>
                                        @if ($link)
                                            <a href="#" class="block py-3 px-4">
                                        @endif

                                        @if ($key === 'actions')
                                            {{-- presence badge: x-data scope #1 --}}
                                            <div x-data="{ count: 0, classes: '', title: '' }" class="join" x-bind:title="title" @click="$dispatch('show-expanded', { id: {{ $row->id }} })">
                                                <span class="btn btn-xs btn-neutral join-item">o</span>
                                                <span class="btn btn-xs join-item" x-bind:class="classes" x-text="count">0</span>
                                            </div>
                                        @elseif ($key === 'id')
                                            {{ $row->id }}
                                        @elseif ($key === 'patients')
                                            <div class="cursor-pointer click-to-copy w-12/12 flex" title="{{ $row->patients_text }}" data-line-copy="orders-manage-patients-{{ $row->id }}">
                                                <div class="min-w-0 max-w-sm overflow-hidden whitespace-nowrap text-ellipsis" id="orders-manage-patients-{{ $row->id }}">{{ $row->patients_text }}</div>
                                            </div>
                                        @elseif ($key === 'part_number')
                                            {{-- x-data scope #2 + custom-directive stand-in #1 (x-empire-line-header:<id>) --}}
                                            <div x-data="{ partNumber: @js($row->part_number) }"
                                                x-on:line-header-updated.window="if ($event.detail.id == {{ $row->id }}) partNumber = $event.detail.part_number"
                                                @class(['h-full px-4 flex items-center', 'bg-accent text-white font-bold' => $this->vendorNames[$row->manufacturer_id] === 'x'])
                                                x-text="partNumber"></div>
                                        @elseif ($key === 'description')
                                            <div class="cursor-pointer click-to-copy w-12/12 flex" title="{{ $row->description }}" data-line-copy="orders-manage-description-{{ $row->id }}">
                                                <div class="min-w-0 max-w-sm overflow-hidden whitespace-nowrap text-ellipsis" id="orders-manage-description-{{ $row->id }}" data-line-id="{{ $row->id }}" data-line-field="description">{{ $row->description }}</div>
                                            </div>
                                        @elseif ($key === 'manufacturer__name')
                                            <span data-line-id="{{ $row->id }}" data-line-field="manufacturer">{{ $this->manufacturerNames[$row->manufacturer_id] ?? '' }}</span>
                                        @elseif ($key === 'quantity')
                                            <span data-line-id="{{ $row->id }}" data-line-field="quantity">{{ $row->quantity }}</span>
                                        @elseif ($key === 'cost')
                                            <span data-line-id="{{ $row->id }}" data-line-field="cost">${{ number_format($row->cost, 2) }}</span>
                                        @elseif ($key === 'price')
                                            <span data-line-id="{{ $row->id }}" data-line-field="price">${{ number_format($row->price, 2) }}</span>
                                        @elseif ($key === 'date_needed')
                                            {{-- x-data scope #3 + custom-directive stand-in #2 --}}
                                            @php $ground = (bool) $row->is_ground_shipping; @endphp
                                            <div @class(['h-full px-4 flex items-center'])
                                                x-data="{ date: @js($row->date_needed?->format('m/d/Y') ?? ''), ground: @js($ground) }"
                                                x-on:line-header-updated.window="if ($event.detail.id == {{ $row->id }}) { date = $event.detail.date_needed; ground = @js(true) && $event.detail.is_ground_shipping }"
                                                :class="{ 'bg-warning text-black font-bold': @js(true) && typeof date !== 'undefined' && date !== '' }"
                                                :title="@js(true) && typeof date !== 'undefined' && date !== '' ? 'Has a Need By date.' : null">
                                                <span x-text="typeof ground !== 'undefined' && ground ? 'Ground' : (typeof date !== 'undefined' ? date : '')">
                                                    @if ($row->date_needed)
                                                        {{ $row->date_needed->format('m/d/Y') }}
                                                    @elseif ($ground)
                                                        Ground
                                                    @endif
                                                </span>
                                            </div>
                                        @elseif ($key === 'date_expected')
                                            {{-- x-data scope #4 + custom-directive stand-in #3 --}}
                                            <div @class(['h-full px-4 flex items-center', 'bg-info text-white font-bold' => (bool) $row->purchase_order_note])
                                                @if ($row->purchase_order_note) title="Line includes a Purchase Order Note" @endif
                                                x-data="{ date: @js($row->date_expected?->format('m/d/Y') ?? '') }"
                                                x-on:line-header-updated.window="if ($event.detail.id == {{ $row->id }}) date = $event.detail.date_expected">
                                                <span x-text="typeof date !== 'undefined' ? date : ''">
                                                    @if ($row->date_expected)
                                                        {{ $row->date_expected->format('m/d/Y') }}
                                                    @endif
                                                </span>
                                            </div>
                                        @elseif ($key === 'warehouse_name')
                                            <span data-line-id="{{ $row->id }}" data-line-field="warehouse_name">{{ $this->warehouseNames[$row->warehouse_id] ?? '' }}</span>
                                        @endif

                                        @if ($link)
                                            </a>
                                        @endif
                                    </td>
                                @else
                                    <td>{{ data_get($row, $key) }}</td>
                                @endif
                            @endforeach

                            @if ($actions)
                                <td class="text-right py-0" @click="event.stopPropagation()"></td>
                            @endif
                        </tr>

                        <tr wire:key="{{ $uuid }}-{{ $row->id }}--expand" wire:ignore.self class="!bg-inherit hidden" :class="{ 'hidden': !isExpanded({{ $row->id }}) }">
                            <td :colspan="colspanSize" wire:key="{{ $uuid }}-{{ $row->id }}--expand-td">
                                @if ($expandable)
                                    <div class="w-0 min-w-full overflow-x-auto" wire:key="orders-manage-expanded-{{ $row->id }}{{ $row->vendor_id }}-content">
                                        @if (in_array($row->id, $expanded))
                                            <livewire:perf-fixtures.expanded-row
                                                key="orders-manage-expanded-line-{{ $row->id }}{{ $row->vendor_id }}"
                                                :line-id="$row->id"
                                            />
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if (count($rows) === 0)
                <div class="text-center py-4">No results</div>
            @endif
        </div>
    </div>
</div>
