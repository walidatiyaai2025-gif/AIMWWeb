<?php

namespace Tests\Feature;

use App\Platform\Localization\LanguagePreferenceCookieWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

class SetLanguagePreferenceTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-PLAT-04D5067C61';

    public function test_exact_canonical_operation_is_set_language(): void
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
        $this->assertSame('SetLanguage', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Localization/LanguagePreferenceService.cs',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame(self::OPERATION_ID, LanguagePreferenceCookieWriter::OPERATION_ID);
    }

    public function test_supported_language_is_queued_for_one_year_with_browser_safe_cookie_contract(): void
    {
        $writer = app(LanguagePreferenceCookieWriter::class);

        $writer->setLanguage('ar');

        $queued = Cookie::queued('AIWM.Language');
        $this->assertNotNull($queued);
        $this->assertSame('ar', $queued->getValue());
        $this->assertSame('/', $queued->getPath());
        $this->assertFalse($queued->isHttpOnly());
        $this->assertFalse($queued->isSecure());
        $this->assertSame('lax', $queued->getSameSite());
        $this->assertGreaterThan(now()->addDays(364)->getTimestamp(), $queued->getExpiresTime());
        $this->assertLessThanOrEqual(now()->addDays(366)->getTimestamp(), $queued->getExpiresTime());
    }

    public function test_unsupported_language_falls_back_to_english(): void
    {
        app(LanguagePreferenceCookieWriter::class)->setLanguage('fr');

        $queued = Cookie::queued('AIWM.Language');
        $this->assertNotNull($queued);
        $this->assertSame('en', $queued->getValue());
    }

    public function test_supported_language_check_matches_source_case_insensitive_behavior(): void
    {
        app(LanguagePreferenceCookieWriter::class)->setLanguage('AR');

        $queued = Cookie::queued('AIWM.Language');
        $this->assertNotNull($queued);
        $this->assertSame('AR', $queued->getValue());
    }

    public function test_https_request_marks_cookie_secure(): void
    {
        $request = Request::create('https://example.test/settings', 'GET');
        $this->app->instance('request', $request);

        app(LanguagePreferenceCookieWriter::class)->setLanguage('en');

        $queued = Cookie::queued('AIWM.Language');
        $this->assertNotNull($queued);
        $this->assertTrue($queued->isSecure());
    }
}
