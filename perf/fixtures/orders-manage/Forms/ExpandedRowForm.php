<?php

namespace Livewire\Performance\Fixtures\Forms;

use Livewire\Form;

/** ~50 nullable public fields, like ExpandedTableRowContentForm. */
class ExpandedRowForm extends Form
{
    public ?int $category_id = null;

    public ?int $cost_center_id = null;

    public ?int $facility_id = null;

    public ?int $id = null;

    public ?int $manufacturer_id = null;

    public ?int $order_id = null;

    public ?int $order_method_id = null;

    public ?int $ordered_user_id = null;

    public ?int $practitioner_id = null;

    public ?int $previous_status_id = null;

    public ?int $purchase_order_id = null;

    public ?int $product_id = null;

    public ?int $quantity = null;

    public ?int $shipping_address_id = null;

    public ?int $status_id = null;

    public ?int $vendor_id = null;

    public ?int $warehouse_id = null;

    public ?int $lock_version = null;

    public ?bool $is_automatic_order = null;

    public ?bool $is_do_not_sub = null;

    public ?bool $is_for_stock = null;

    public ?bool $is_ground_shipping = null;

    public ?bool $is_warranty_replacement = null;

    public ?string $cost = null;

    public ?string $customer_internal_memo = null;

    public ?string $customer_note = null;

    public ?string $date_expected = null;

    public ?string $date_needed = null;

    public ?string $delivered_at = null;

    public ?string $description = null;

    public ?string $export_notes = null;

    public ?string $job_number = null;

    public ?string $markup = null;

    public ?string $need_more_info_message = null;

    public ?string $part_number = null;

    public ?string $price = null;

    public ?string $purchase_order_note = null;

    public ?string $standard_price = null;

    public ?string $tracking_note = null;

    public ?string $vendor_note = null;

    public ?string $warehouse_note = null;

    public ?string $ship_via = null;

    public ?string $account_number = null;

    public ?array $meta = null;

    public array $patients = [];
}
