<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomeLanguageSwitchTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-3EAD5A351E';

    public function test_language_switch_renders_translations_direction_and_opposite_destination(): void
    {
        $this->get('/welcome')->assertOk()->assertSee('lang="en" dir="ltr"', false)
            ->assertSee('Operate WordPress as one system.')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/welcome?lang=ar"', false);
        $this->get('/welcome?lang=ar')->assertOk()->assertSee('lang="ar" dir="rtl"', false)
            ->assertSee('أدر ووردبريس كنظام واحد.')->assertSee('تسجيل الدخول')
            ->assertSee('href="/welcome?lang=en"', false)->assertDontSee('Operate WordPress as one system.');
        $this->get('/welcome')->assertOk()->assertSee('lang="ar" dir="rtl"', false);
        $this->get('/welcome?lang=en')->assertOk()->assertSee('lang="en" dir="ltr"', false);
        $this->get('/welcome')->assertOk()->assertSee('Operate WordPress as one system.');
    }

    public function test_unsupported_and_structured_locale_input_cannot_change_or_inject_content(): void
    {
        $this->get('/welcome?lang=ar')->assertOk();
        foreach (['invalid', '%3Cscript%3Esecret%3C%2Fscript%3E'] as $locale) {
            $this->get('/welcome?lang='.$locale)->assertOk()->assertSee('lang="ar" dir="rtl"', false)
                ->assertDontSee('<script>secret</script>', false);
        }
        $this->get('/welcome?lang[]=en')->assertOk()->assertSee('lang="ar" dir="rtl"', false);
        $this->withSession(['public_welcome_locale' => 'unsupported'])->get('/welcome')
            ->assertOk()->assertSee('lang="en" dir="ltr"', false);
    }

    public function test_language_is_public_identity_neutral_and_never_selects_a_tenant(): void
    {
        $anonymous = $this->get('/welcome?lang=ar')->assertOk()->getContent();
        $user = User::factory()->create(['name' => 'Private Tenant Sentinel', 'email' => 'private@example.test']);
        $authenticated = $this->actingAs($user)->get('/welcome?lang=ar&tenant=foreign&user_id=999')
            ->assertOk()->assertDontSee('Private Tenant Sentinel')->assertDontSee('private@example.test')->getContent();
        $this->assertStringContainsString('lang="ar" dir="rtl"', $anonymous);
        $this->assertStringContainsString('lang="ar" dir="rtl"', $authenticated);
        $this->assertStringNotContainsString('foreign', $authenticated);
        $this->assertStringNotContainsString('999', $authenticated);
        $this->get('/tenants/foreign/welcome?lang=ar')->assertNotFound();
        $this->post('/welcome', ['lang' => 'ar'])->assertStatus(405);
    }
}
