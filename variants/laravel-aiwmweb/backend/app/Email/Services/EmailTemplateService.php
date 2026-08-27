<?php

namespace App\Email\Services;

use App\Models\EmailTemplate;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EmailTemplateService
{
    public const LOCALES = ['en', 'ar'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function all(): array
    {
        $this->seedBuiltins();

        return EmailTemplate::query()->where('active', true)->orderBy('stable_id')->orderBy('locale')->get()
            ->map(fn (EmailTemplate $template) => $this->serialize($template))->all();
    }

    public function save(string $stableId, string $locale, array $input): EmailTemplate
    {
        $locale = $this->locale($locale);
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{1,119}$/', $stableId)) {
            throw ValidationException::withMessages(['stable_id' => 'Invalid stable template ID.']);
        }
        $subjectTemplate = trim((string) ($input['subject_template'] ?? ''));
        $htmlTemplate = (string) ($input['html_template'] ?? '');
        if ($subjectTemplate === '' || trim($htmlTemplate) === '') {
            throw ValidationException::withMessages(['template' => 'Subject and HTML templates are required.']);
        }
        $variables = array_values(array_unique(array_map('strval', (array) ($input['variables'] ?? []))));
        foreach ($variables as $variable) {
            if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,79}$/', $variable)) {
                throw ValidationException::withMessages(['variables' => "Invalid template variable: {$variable}"]);
            }
        }

        $current = EmailTemplate::query()->where('stable_id', $stableId)->where('locale', $locale)
            ->where('active', true)->latest('version')->first();
        $version = ((int) ($current?->version ?? 0)) + 1;
        if ($current) {
            $current->update(['active' => false]);
        }
        $template = EmailTemplate::query()->create([
            'stable_id' => $stableId,
            'locale' => $locale,
            'version' => $version,
            'subject_template' => $subjectTemplate,
            'html_template' => $htmlTemplate,
            'text_template' => $input['text_template'] ?? null,
            'variables' => $variables,
            'active' => true,
            'builtin' => false,
            'updated_by_user_id' => $this->context->membership()->user_id,
        ]);
        $this->audit->record('email.template.changed', [
            'stable_id' => $stableId,
            'locale' => $locale,
            'version' => $version,
        ], EmailTemplate::class, $template->id);

        return $template;
    }

    /** @return array{subject:string,html:string,text:string,locale:string,direction:string} */
    public function render(string $stableId, string $locale, array $variables): array
    {
        $this->seedBuiltins();
        $locale = $this->locale($locale);
        $template = EmailTemplate::query()->where('stable_id', $stableId)->where('locale', $locale)
            ->where('active', true)->latest('version')->firstOrFail();
        $declared = array_values(array_map('strval', $template->variables ?? []));
        $missing = array_values(array_diff($declared, array_keys($variables)));
        $unknown = array_values(array_diff(array_keys($variables), $declared));
        if ($missing !== [] || $unknown !== []) {
            throw ValidationException::withMessages([
                'variables' => 'Template variables mismatch. Missing: '.implode(',', $missing).'; unknown: '.implode(',', $unknown),
            ]);
        }

        $replaceHtml = [];
        $replaceText = [];
        foreach ($variables as $key => $value) {
            $string = is_scalar($value) || $value === null
                ? (string) $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $replaceHtml['{{'.$key.'}}'] = e($string);
            $replaceText['{{'.$key.'}}'] = $string;
        }
        $subject = str_replace(["\r", "\n"], ' ', strtr($template->subject_template, $replaceText));
        $direction = $locale === 'ar' ? 'rtl' : 'ltr';
        $body = strtr($template->html_template, $replaceHtml);
        $html = '<!doctype html><html lang="'.$locale.'" dir="'.$direction.'"><body style="direction:'.$direction.';text-align:'.($direction === 'rtl' ? 'right' : 'left').';font-family:Arial,sans-serif">'.$body.'</body></html>';

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => strtr((string) ($template->text_template ?? Str::of(strip_tags($template->html_template))->squish()), $replaceText),
            'locale' => $locale,
            'direction' => $direction,
        ];
    }

    public function serialize(EmailTemplate $template): array
    {
        return [
            'id' => $template->id,
            'stable_id' => $template->stable_id,
            'locale' => $template->locale,
            'version' => $template->version,
            'subject_template' => $template->subject_template,
            'html_template' => $template->html_template,
            'text_template' => $template->text_template,
            'variables' => $template->variables ?? [],
            'builtin' => (bool) $template->builtin,
        ];
    }

    private function locale(string $locale): string
    {
        $locale = strtolower($locale);

        return in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }

    private function seedBuiltins(): void
    {
        foreach ($this->builtins() as $stableId => $definition) {
            foreach (self::LOCALES as $locale) {
                if (EmailTemplate::query()->where('stable_id', $stableId)->where('locale', $locale)->exists()) {
                    continue;
                }
                EmailTemplate::query()->create([
                    'stable_id' => $stableId,
                    'locale' => $locale,
                    'version' => 1,
                    'subject_template' => $definition[$locale]['subject'],
                    'html_template' => $definition[$locale]['html'],
                    'text_template' => $definition[$locale]['text'],
                    'variables' => $definition['variables'],
                    'active' => true,
                    'builtin' => true,
                ]);
            }
        }
    }

    private function builtins(): array
    {
        $en = ['subject' => '{{title}}', 'html' => '<h2>{{title}}</h2><p>{{message}}</p><small>AIWMWeb notification</small>', 'text' => "{{title}}\n{{message}}\nAIWMWeb notification"];
        $ar = ['subject' => '{{title}}', 'html' => '<h2>{{title}}</h2><p>{{message}}</p><small>إشعار AIWMWeb</small>', 'text' => "{{title}}\n{{message}}\nإشعار AIWMWeb"];

        return [
            'sync.status' => ['variables' => ['title', 'message'], 'en' => $en, 'ar' => $ar],
            'operation.alert' => ['variables' => ['title', 'message'], 'en' => $en, 'ar' => $ar],
            'billing.alert' => ['variables' => ['title', 'message'], 'en' => $en, 'ar' => $ar],
            'security.alert' => ['variables' => ['title', 'message'], 'en' => $en, 'ar' => $ar],
        ];
    }
}
