<?php

namespace Livewire\Features\SupportSkipUnchanged;

use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Tests\TestCase;

class UnitTest extends TestCase
{
    public function test_script_config_enables_skipping_by_default()
    {
        $this->assertStringContainsString('"skipUnchanged":true', FrontendAssets::scriptConfig());
    }

    public function test_script_config_reflects_the_config_value()
    {
        config()->set('livewire.morph_skip_unchanged', false);

        $this->assertStringContainsString('"skipUnchanged":false', FrontendAssets::scriptConfig());
    }

    public function test_script_tag_has_no_opt_out_attribute_by_default()
    {
        $this->assertStringNotContainsString('data-no-skip-unchanged', FrontendAssets::scripts());
    }

    public function test_script_tag_has_the_opt_out_attribute_when_disabled()
    {
        config()->set('livewire.morph_skip_unchanged', false);

        $this->assertStringContainsString('data-no-skip-unchanged', FrontendAssets::scripts());
    }
}
