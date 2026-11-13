<?php

namespace Livewire\Features\SupportSkipUnchanged;

use Tests\BrowserTestCase;
use Livewire\Component;
use Livewire\Livewire;

class BrowserTest extends BrowserTestCase
{
    protected function makeComponent()
    {
        return new class extends Component {
            public $tick = 0;
            public $changed = 'before';

            public function bump() { $this->tick++; }
            public function change() { $this->changed = 'after'; }

            public function render()
            {
                return <<<'HTML'
                    <div>
                        <button wire:click="bump" dusk="bump">bump</button>
                        <button wire:click="change" dusk="change">change</button>

                        <ul>
                            <li wire:key="static" dusk="static">same</li>
                            <li wire:key="dynamic" dusk="dynamic">{{ $changed }}</li>
                        </ul>

                        <span dusk="tick">{{ $tick }}</span>
                    </div>
                HTML;
            }
        };
    }

    public function test_keyed_elements_with_unchanged_server_html_are_not_morphed_again()
    {
        Livewire::visit($this->makeComponent())
            // Something outside of Livewire/Alpine changes the keyed elements on the client...
            ->tap(fn ($b) => $b->script("document.querySelector('[dusk=\"static\"]').setAttribute('data-client', 'yes'); document.querySelector('[dusk=\"dynamic\"]').setAttribute('data-client', 'yes')"))
            // A request where neither keyed element's server HTML changes: the client edits survive...
            ->waitForLivewire()->click('@bump')
            ->assertSeeIn('@tick', '1')
            ->assertAttribute('@static', 'data-client', 'yes')
            ->assertAttribute('@dynamic', 'data-client', 'yes')
            // A request where one keyed element's server HTML changes: it is morphed (and the edit reset), the other is skipped...
            ->waitForLivewire()->click('@change')
            ->assertSeeIn('@dynamic', 'after')
            ->assertAttributeMissing('@dynamic', 'data-client')
            ->assertAttribute('@static', 'data-client', 'yes')
        ;
    }

    public function test_keyed_elements_added_by_a_morph_are_skipped_on_the_next_one()
    {
        Livewire::visit(new class extends Component {
            public $items = [];

            public function add() { $this->items[] = count($this->items) + 1; }
            public function noop() {}

            public function render()
            {
                return <<<'HTML'
                    <div>
                        <button wire:click="add" dusk="add">add</button>
                        <button wire:click="noop" dusk="noop">noop</button>
                        <ul>
                            @foreach ($items as $item)
                                <li wire:key="item-{{ $item }}" dusk="item-{{ $item }}">{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                HTML;
            }
        })
            ->waitForLivewire()->click('@add')
            ->waitFor('@item-1')
            ->tap(fn ($b) => $b->script("document.querySelector('[dusk=\"item-1\"]').setAttribute('data-client', 'yes')"))
            ->waitForLivewire()->click('@noop')
            ->assertAttribute('@item-1', 'data-client', 'yes')
        ;
    }

    public function test_skipping_can_be_disabled_with_config()
    {
        config()->set('livewire.morph_skip_unchanged', false);

        Livewire::visit($this->makeComponent())
            ->tap(fn ($b) => $b->script("document.querySelector('[dusk=\"static\"]').setAttribute('data-client', 'yes')"))
            ->waitForLivewire()->click('@bump')
            ->assertSeeIn('@tick', '1')
            ->assertAttributeMissing('@static', 'data-client')
        ;
    }

    public function test_a_keyed_element_that_stops_being_a_leaf_does_not_keep_a_stale_signature()
    {
        // "content" is a leaf (no keyed descendants) when closed, then a container while open, then a leaf again.
        // The server HTML for the closed state must not be matched against the signature remembered
        // from before it was opened, or closing would be skipped and the inner element would stay...
        Livewire::visit(new class extends Component {
            public $open = false;

            public function toggle() { $this->open = ! $this->open; }

            public function render()
            {
                return <<<'HTML'
                    <div>
                        <button wire:click="toggle" dusk="toggle">toggle</button>

                        <div wire:key="row">
                            <div wire:key="content" dusk="content">@if ($open)<span wire:key="inner" dusk="inner">inner</span>@endif</div>
                        </div>
                    </div>
                HTML;
            }
        })
            ->assertNotPresent('@inner')
            ->waitForLivewire()->click('@toggle')
            ->assertPresent('@inner')
            ->waitForLivewire()->click('@toggle')
            ->assertNotPresent('@inner')
            ->waitForLivewire()->click('@toggle')
            ->assertPresent('@inner')
            ->waitForLivewire()->click('@toggle')
            ->assertNotPresent('@inner')
        ;
    }
}
