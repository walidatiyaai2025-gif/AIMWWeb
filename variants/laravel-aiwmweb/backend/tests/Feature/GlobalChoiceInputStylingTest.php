<?php

namespace Tests\Feature;

use Tests\TestCase;

final class GlobalChoiceInputStylingTest extends TestCase
{
    public function test_full_width_text_field_rule_excludes_checkbox_and_radio(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            'input:not([type="checkbox"]):not([type="radio"]), textarea, select',
            $css,
        );
        $this->assertStringNotContainsString(
            'input, textarea, select { min-height: var(--touch); width: 100%',
            $css,
        );
    }

    public function test_native_choice_inputs_have_bounded_geometry_and_accessible_states(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/input\[type="checkbox"\], input\[type="radio"\] \{([^}]+)\}/s',
            $css,
        );
        preg_match('/input\[type="checkbox"\], input\[type="radio"\] \{([^}]+)\}/s', $css, $matches);
        $choice = $matches[1];

        foreach ([
            'appearance: auto;',
            'inline-size: 18px;',
            'block-size: 18px;',
            'min-width: 18px;',
            'max-width: 18px;',
            'min-height: 18px;',
            'max-height: 18px;',
            'flex: 0 0 18px;',
            'padding: 0;',
            'accent-color: var(--accent);',
        ] as $contract) {
            $this->assertStringContainsString($contract, $choice);
        }

        $this->assertStringContainsString('input[type="checkbox"] { border-radius: 4px; }', $css);
        $this->assertStringContainsString('input[type="radio"] { border-radius: 50%; }', $css);
        $this->assertStringContainsString('input:is([type="checkbox"], [type="radio"]):disabled', $css);
        $this->assertStringContainsString('input:focus-visible', $css);
    }
}
