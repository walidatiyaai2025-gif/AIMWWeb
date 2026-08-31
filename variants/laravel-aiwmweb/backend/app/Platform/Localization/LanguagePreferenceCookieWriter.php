<?php

namespace App\Platform\Localization;

use Illuminate\Contracts\Cookie\Factory as CookieFactory;
use Illuminate\Http\Request;

final class LanguagePreferenceCookieWriter
{
    public const OPERATION_ID = 'AIMW-PLAT-04D5067C61';

    private const COOKIE_NAME = 'AIWM.Language';

    private const DEFAULT_LANGUAGE = 'en';

    private const ONE_YEAR_MINUTES = 525600;

    public function __construct(
        private readonly CookieFactory $cookies,
        private readonly Request $request,
    ) {
    }

    public function setLanguage(string $language): void
    {
        $normalized = $this->isSupported($language) ? $language : self::DEFAULT_LANGUAGE;

        $this->cookies->queue(
            self::COOKIE_NAME,
            $normalized,
            self::ONE_YEAR_MINUTES,
            '/',
            null,
            $this->request->isSecure(),
            false,
            false,
            'lax',
        );
    }

    private function isSupported(?string $language): bool
    {
        return strcasecmp((string) $language, 'ar') === 0
            || strcasecmp((string) $language, 'en') === 0;
    }
}
