<?php

namespace Tests\Feature;

use Tests\TestCase;

final class PublicRootLandingTest extends TestCase
{
    public function test_root_uses_the_real_aiwmweb_landing_not_the_laravel_starter(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewIs('ai.welcome')
            ->assertSee('AI WordPress Manager')
            ->assertDontSee("Let's get started");
    }

    public function test_root_preserves_the_existing_arabic_public_landing_flow(): void
    {
        $this->get('/?lang=ar')
            ->assertOk()
            ->assertViewIs('ai.welcome')
            ->assertViewHas('welcomeLocale', 'ar')
            ->assertSee('dir="rtl"', false);
    }

    public function test_root_preserves_english_public_landing_flow(): void
    {
        $this->get('/?lang=en')
            ->assertOk()
            ->assertViewHas('welcomeLocale', 'en')
            ->assertSee('dir="ltr"', false);
    }
}
