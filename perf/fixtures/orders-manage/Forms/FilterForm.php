<?php

namespace Livewire\Performance\Fixtures\Forms;

use Livewire\Attributes\Url;
use Livewire\Form;

/** 16 #[Url] props, like OrdersManageFilterForm. */
class FilterForm extends Form
{
    #[Url(except: '')]
    public ?string $search = '';

    #[Url(except: '')]
    public ?string $part_number = '';

    #[Url(except: '')]
    public ?string $description = '';

    #[Url(except: '')]
    public ?string $manufacturer_id = '';

    #[Url(except: '')]
    public ?string $warehouse_id = '';

    #[Url(except: '')]
    public ?string $category_id = '';

    #[Url(except: '')]
    public ?string $cost_center_id = '';

    #[Url(except: '')]
    public ?string $facility_id = '';

    #[Url(except: '')]
    public ?string $status_id = '';

    #[Url(except: '')]
    public ?string $order_method_id = '';

    #[Url(except: '')]
    public ?string $date_needed_from = '';

    #[Url(except: '')]
    public ?string $date_needed_to = '';

    #[Url(except: '')]
    public ?string $date_expected_from = '';

    #[Url(except: '')]
    public ?string $date_expected_to = '';

    #[Url(except: '')]
    public ?string $has_purchase_order_note = '';

    #[Url(except: '')]
    public ?string $only_ground = '';
}
