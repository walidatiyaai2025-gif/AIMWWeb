<?php

namespace Tests\Feature;

use App\Platform\Localization\LanguagePreferenceCookieReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

class GetLanguagePreferenceTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-PLAT-17E3F2B4ED';

    public function test_exact_canonical_operation_is_get_language(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('platform', $operation['domain']);
        $this->assertSame('service', $operation['kind']);
        $this->assertSame('service:LanguagePreferenceService', $operation['route_screen']);
        $this->assertSame('GetLanguage', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Localization/LanguagePreferenceService.cs',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame(self::OPERATION_ID, LanguagePreferenceCookieReader::OPERATION_ID);
    }

    public function test_supported_language_is_read_from_the_current_request_cookie(): void
    {
        $this->bindRequestWithLanguageCookie('ar');

        $this->assertSame('ar', app(LanguagePreferenceCookieReader::class)->getLanguage());
    }

    public function test_supported_language_check_matches_source_case_insensitive_behavior_without_normalizing_value(): void
    {
        $this->bindRequestWithLanguageCookie('AR');

        $this->assertSame('AR', app(LanguagePreferenceCookieReader::class)->getLanguage());
    }

    public function test_unsupported_language_falls_back_to_english(): void
    {
        $this->bindRequestWithLanguageCookie('fr');

        $this->assertSame('en', app(LanguagePreferenceCookieReader::class)->getLanguage());
    }

    public function test_missing_cookie_falls_back_to_english(): void
    {
        $this->app->instance('request', Request::create('/settings', 'GET'));

        $this->assertSame('en', app(LanguagePreferenceCookieReader::class)->getLanguage());
    }

    public function test_reading_language_does_not_queue_or_mutate_a_cookie(): void
    {
        $this->bindRequestWithLanguageCookie('en');

        $this->assertSame('en', app(LanguagePreferenceCookieReader::class)->getLanguage());
        $this->assertNull(Cookie::queued('AIWM.Language'));
    }

    private function bindRequestWithLanguageCookie(string $language): void
    {
        $request = Request::create(
            '/settings',
            'GET',
            [],
            ['AIWM.Language' => $language],
        );

        $this->app->instance('request', $request);
    }
}
