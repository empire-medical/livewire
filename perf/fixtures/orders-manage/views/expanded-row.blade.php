{{-- Synthetic expanded row: 7 wire:model (4 .blur), 7 wire:click, ~18 wire:key sites (incl. 2 in loops), 19 @if, 4 @foreach. --}}
<div class="expanded-row" wire:key="expanded-root-{{ $lineId }}">
    @if ($unsavedChanges)
        <div class="alert" wire:key="unsaved-{{ $lineId }}">Unsaved changes</div>
    @endif
    <section class="grid grid-cols-3 gap-2" wire:key="summary-{{ $lineId }}">
        <div class="field"><label>Vendor</label>@if ($vendor)<span>{{ $vendor?->name }}</span>@endif</div>
        <div class="field"><label>Manufacturer</label>@if ($vendor)<span>{{ $manufacturer?->name }}</span>@endif</div>
        <div class="field"><label>Warehouse</label>@if ($vendor)<span>{{ $warehouse?->name }}</span>@endif</div>
        <div class="field"><label>Ship-to</label>@if ($vendor)<span>{{ $shippingAddress?->name }}</span>@endif</div>
        <div class="field"><label>Product</label>@if ($vendor)<span>{{ $product?->name }}</span>@endif</div>
        <div class="field"><label>PO</label>@if ($vendor)<span>{{ $purchaseOrderIdWithSuffix }}</span>@endif</div>
    </section>
    <section class="grid grid-cols-4 gap-2" wire:key="editable-{{ $lineId }}">
        <div class="field" wire:key="f-cost-{{ $lineId }}">
            <label>Cost</label>
            @if ($highlightCost)<span class="badge">high</span>@endif
            <input type="text" wire:model.blur="form.cost" class="input @if($highlightCost) input-warning @endif">
        </div>
        <div class="field" wire:key="f-markup-{{ $lineId }}">
            <label>Markup</label>
            <input type="text" wire:model.blur="form.markup" class="input">
            @if ($form->markup)<small>{{ $form->markup }}</small>@endif
        </div>
        <div class="field" wire:key="f-price-{{ $lineId }}">
            <label>Price</label>
            @if ($highlightPrice)<span class="badge">changed</span>@endif
            <input type="text" wire:model.blur="form.price" class="input">
            <button type="button" wire:click="applyStandardPrice" class="btn btn-xs">Standard</button>
        </div>
        <div class="field" wire:key="f-qty-{{ $lineId }}">
            <label>Qty</label>
            <input type="number" wire:model.blur="form.quantity" class="input">
        </div>
        <div class="field" wire:key="f-desc-{{ $lineId }}">
            <label>Description</label>
            <input type="text" wire:model="form.description" class="input">
        </div>
        <div class="field" wire:key="f-pn-{{ $lineId }}">
            <label>Part #</label>
            <input type="text" wire:model="form.part_number" class="input">
        </div>
        <div class="field" wire:key="f-dn-{{ $lineId }}">
            <label>Date needed</label>
            <input type="date" wire:model="form.date_needed" class="input">
            @if ($dateNeededProxy)<small>{{ $dateNeededProxy }}</small>@endif
        </div>
    </section>
    <section wire:key="notes-{{ $lineId }}">
        <label>Purchase order note</label>
        @if ($form->purchase_order_note)
            <p>{{ $form->purchase_order_note }}</p>
            <button type="button" wire:click="clearNote" class="btn btn-xs">Clear</button>
        @else
            <button type="button" wire:click="addNote" class="btn btn-xs">Add note</button>
        @endif
    </section>
    <section wire:key="tracking-{{ $lineId }}">
        <h4>Tracking</h4>
        <ul>
            @foreach ($trackingNumbers ?? [] as $t)
                <li wire:key="trk-{{ $t->id }}">
                    @if ($t->carrier)<span>{{ $t->carrier }}</span>@endif
                    <span>{{ $t->number }}</span>
                </li>
            @endforeach
        </ul>
        <button type="button" wire:click="refreshTracking" class="btn btn-xs">Refresh</button>
    </section>
    <section wire:key="explain-{{ $lineId }}">
        @if ($currentProductExplanation)
            <p>{{ $currentProductExplanation['summary'] }}</p>
            <ul>
                @foreach ($currentProductExplanation['rows'] as $r)
                    <li wire:key="expl-{{ $lineId }}-{{ $r }}">Row {{ $r }}</li>
                @endforeach
            </ul>
        @endif
        <dl>
            @foreach ([
                'Ordered via' => $form->order_method_id,
                'Category' => $form->category_id,
                'Cost center' => $form->cost_center_id,
                'Facility' => $form->facility_id,
                'Status' => $form->status_id,
                'Lock version' => $form->lock_version,
            ] as $label => $value)
                @if ($value !== null)
                    <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
                @endif
            @endforeach
        </dl>
    </section>
    <footer class="flex gap-2" wire:key="actions-{{ $lineId }}">
        <button type="button" wire:click="save" class="btn btn-primary">Save</button>
        <button type="button" wire:click="cancel" class="btn">Cancel</button>
        <button type="button" wire:click="markChanged" class="btn btn-ghost">Mark changed</button>
        @if ($shippingAddress && $shippingAddress->blacklist_all_vendors)<span class="badge">blacklisted</span>@endif
        @if ($dateExpected)<span>{{ $dateExpected->format('m/d/Y') }}</span>@endif
    </footer>
</div>
