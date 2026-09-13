<?php

namespace App\Platform\Localization;

use Illuminate\Http\Request;

final class LanguagePreferenceCookieReader
{
    public const OPERATION_ID = 'AIMW-PLAT-17E3F2B4ED';

    private const COOKIE_NAME = 'AIWM.Language';

    private const DEFAULT_LANGUAGE = 'en';

    public function __construct(
        private readonly Request $request,
    ) {}

    public function getLanguage(): string
    {
        $value = $this->request->cookie(self::COOKIE_NAME);

        return $this->isSupported($value) ? (string) $value : self::DEFAULT_LANGUAGE;
    }

    private function isSupported(mixed $language): bool
    {
        if (! is_string($language)) {
            return false;
        }

        return strcasecmp($language, 'ar') === 0
            || strcasecmp($language, 'en') === 0;
    }
}
