<?php

namespace Livewire\Performance;

use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Form;
use Livewire\Livewire;
use Livewire\Performance\Fixtures\Models\FixtureVendor;
use Livewire\Performance\Fixtures\Registrar;
use Tests\TestCase;

require_once __DIR__.'/Bench.php';
require_once __DIR__.'/../fixtures/orders-manage/autoload.php';

/**
 * Sweep: server round trips for other shapes that the app's usage data points at (not just Orders Manage):
 * a 38-#[Url]-prop form object, a component holding 9 Eloquent model props, per-keystroke #[Validate],
 * a parent with 24 child components, and events. Run through perf/ab.sh.
 */
class SweepBenchTest extends TestCase
{
    use Bench;

    public function setUp(): void
    {
        parent::setUp();
        Registrar::register(withRoute: false);
    }

    public function test_form_object_with_38_url_props()
    {
        Livewire::component('sweep-form', SweepFormComponent::class);
        $snapshot = $this->snapshotFor(SweepFormComponent::class);

        $this->measure('sweep_form38_noop_call', fn () => app('livewire')->update($snapshot, [], [['method' => 'noop', 'params' => []]]), iterations: 200, warmup: 10);
        $this->measure('sweep_form38_set_one_prop', fn () => app('livewire')->update($snapshot, ['form.f7' => 'abc'], []), iterations: 200, warmup: 10);
        $this->measure('sweep_form38_mount', fn () => app('livewire')->mount(SweepFormComponent::class), iterations: 200, warmup: 10);
        $this->assertTrue(true);
    }

    public function test_nine_eloquent_model_props()
    {
        Livewire::component('sweep-models', SweepModelsComponent::class);
        $snapshot = $this->snapshotFor(SweepModelsComponent::class);

        $this->measure('sweep_models9_noop_call', fn () => app('livewire')->update($snapshot, [], [['method' => 'noop', 'params' => []]]), iterations: 150, warmup: 10);
        $this->measure('sweep_models9_mount', fn () => app('livewire')->mount(SweepModelsComponent::class), iterations: 150, warmup: 10);
        $this->assertTrue(true);
    }

    public function test_validate_per_keystroke()
    {
        Livewire::component('sweep-validate', SweepValidateComponent::class);
        $snapshot = $this->snapshotFor(SweepValidateComponent::class);

        $this->measure('sweep_validate_set_prop', fn () => app('livewire')->update($snapshot, ['email' => 'user@example.test'], []), iterations: 200, warmup: 10);
        $this->measure('sweep_validate_set_prop_invalid', fn () => app('livewire')->update($snapshot, ['email' => 'x'], []), iterations: 200, warmup: 10);
        $this->assertTrue(true);
    }

    public function test_parent_with_24_children()
    {
        Livewire::component('sweep-parent', SweepParentComponent::class);
        Livewire::component('sweep-child', SweepChildComponent::class);
        $snapshot = $this->snapshotFor(SweepParentComponent::class);

        $this->measure('sweep_parent24_mount', fn () => app('livewire')->mount(SweepParentComponent::class), iterations: 60, warmup: 5);
        $this->measure('sweep_parent24_noop_call', fn () => app('livewire')->update($snapshot, [], [['method' => 'noop', 'params' => []]]), iterations: 100, warmup: 5);
        $this->assertTrue(true);
    }

    public function test_events()
    {
        Livewire::component('sweep-events', SweepEventsComponent::class);
        $snapshot = $this->snapshotFor(SweepEventsComponent::class);

        $this->measure('sweep_event_dispatch_listener', fn () => app('livewire')->update($snapshot, [], [['method' => '__dispatch', 'params' => ['bump', []]]]), iterations: 300, warmup: 10);
        $this->measure('sweep_event_dispatch_from_action', fn () => app('livewire')->update($snapshot, [], [['method' => 'fire', 'params' => []]]), iterations: 300, warmup: 10);
        $this->assertTrue(true);
    }
}

class SweepFormObject extends Form
{
    #[Url(except: '')]
    public ?string $f1 = '';

    #[Url(except: '')]
    public ?string $f2 = '';

    #[Url(except: '')]
    public ?string $f3 = '';

    #[Url(except: '')]
    public ?string $f4 = '';

    #[Url(except: '')]
    public ?string $f5 = '';

    #[Url(except: '')]
    public ?string $f6 = '';

    #[Url(except: '')]
    public ?string $f7 = '';

    #[Url(except: '')]
    public ?string $f8 = '';

    #[Url(except: '')]
    public ?string $f9 = '';

    #[Url(except: '')]
    public ?string $f10 = '';

    #[Url(except: '')]
    public ?string $f11 = '';

    #[Url(except: '')]
    public ?string $f12 = '';

    #[Url(except: '')]
    public ?string $f13 = '';

    #[Url(except: '')]
    public ?string $f14 = '';

    #[Url(except: '')]
    public ?string $f15 = '';

    #[Url(except: '')]
    public ?string $f16 = '';

    #[Url(except: '')]
    public ?string $f17 = '';

    #[Url(except: '')]
    public ?string $f18 = '';

    #[Url(except: '')]
    public ?string $f19 = '';

    #[Url(except: '')]
    public ?string $f20 = '';

    #[Url(except: '')]
    public ?string $f21 = '';

    #[Url(except: '')]
    public ?string $f22 = '';

    #[Url(except: '')]
    public ?string $f23 = '';

    #[Url(except: '')]
    public ?string $f24 = '';

    #[Url(except: '')]
    public ?string $f25 = '';

    #[Url(except: '')]
    public ?string $f26 = '';

    #[Url(except: '')]
    public ?string $f27 = '';

    #[Url(except: '')]
    public ?string $f28 = '';

    #[Url(except: '')]
    public ?string $f29 = '';

    #[Url(except: '')]
    public ?string $f30 = '';

    #[Url(except: '')]
    public ?string $f31 = '';

    #[Url(except: '')]
    public ?string $f32 = '';

    #[Url(except: '')]
    public ?string $f33 = '';

    #[Url(except: '')]
    public ?string $f34 = '';

    #[Url(except: '')]
    public ?string $f35 = '';

    #[Url(except: '')]
    public ?string $f36 = '';

    #[Url(except: '')]
    public ?string $f37 = '';

    #[Url(except: '')]
    public ?string $f38 = '';
}

class SweepFormComponent extends Component
{
    public SweepFormObject $form;

    public function noop() {}

    public function render() { return '<div><input wire:model.live="form.f1"><span>{{ $form->f1 }}</span></div>'; }
}

class SweepModelsComponent extends Component
{
    public ?FixtureVendor $a = null;
    public ?FixtureVendor $b = null;
    public ?FixtureVendor $c = null;
    public ?FixtureVendor $d = null;
    public ?FixtureVendor $e = null;
    public ?FixtureVendor $f = null;
    public ?FixtureVendor $g = null;
    public ?FixtureVendor $h = null;
    public ?FixtureVendor $i = null;

    public function mount()
    {
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'] as $n => $prop) $this->$prop = FixtureVendor::find($n + 1);
    }

    public function noop() {}

    public function render() { return '<div>{{ $a->name }} {{ $i->name }}</div>'; }
}

class SweepValidateComponent extends Component
{
    #[Validate('required|email|min:3')]
    public $email = '';

    #[Validate('required|min:2')]
    public $name = 'abc';

    #[Validate('nullable|integer')]
    public $qty = 1;

    #[Validate('nullable|string|max:50')]
    public $note = '';

    #[Validate('nullable|string|max:20')]
    public $ref = '';

    public function render() { return '<div><input wire:model.live="email"> @error("email") <span>{{ $message }}</span> @enderror</div>'; }
}

class SweepChildComponent extends Component
{
    public int $n = 0;
    public string $label = 'x';
    public bool $open = false;

    public function render() { return '<div><span>{{ $n }}</span><button wire:click="$toggle(\'open\')">t</button></div>'; }
}

class SweepParentComponent extends Component
{
    public function noop() {}

    public function render()
    {
        return <<<'HTML'
        <div>
            @for ($i = 1; $i <= 24; $i++)
                <livewire:sweep-child :n="$i" :key="'c'.$i" />
            @endfor
        </div>
        HTML;
    }
}

class SweepEventsComponent extends Component
{
    public int $count = 0;

    #[On('bump')]
    public function bump() { $this->count++; }

    public function fire() { $this->dispatch('bump'); }

    public function render() { return '<div>{{ $count }}</div>'; }
}
