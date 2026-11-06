@php
    $isEditing = is_array($provider ?? null);
    $key = $isEditing ? $provider['provider_key'] : 'ollama';
    $model = $isEditing ? ($provider['default_model'] ?: '') : '';
@endphp
<form method="post" action="{{ route('tenant.settings.ai-providers.save', ['tenant' => $tenant->slug]) }}" class="provider-editor" autocomplete="off">
    @csrf
    <div class="provider-fields">
        <label>معرّف المزود / Provider key
            <input name="provider_key" value="{{ $key }}" required pattern="[a-z][a-z0-9_-]{0,62}" {{ $isEditing ? 'readonly' : '' }}>
        </label>
        <label>الاسم / Display name
            <input name="display_name" value="{{ $isEditing ? $provider['display_name'] : 'Ollama (Private AI)' }}" maxlength="100" required>
        </label>
        <label>رابط OpenAI-compatible / Endpoint HTTPS
            <input name="endpoint" type="url" inputmode="url" value="{{ $isEditing ? $provider['endpoint'] : 'https://ai.walidatiya.com/v1' }}" required maxlength="2048" placeholder="https://ai.walidatiya.com/v1" spellcheck="false">
        </label>
        <label>النموذج الموجود على السيرفر / Model name
            <input name="model_key" value="{{ $model }}" required maxlength="150" placeholder="Exact model from Ollama">
        </label>
        <label>API Key (يُحفظ مشفّرًا على السيرفر / Encrypted server-side)
            <input name="api_key" type="password" maxlength="4096" autocomplete="new-password" placeholder="{{ $isEditing && $provider['has_api_key'] ? 'Leave blank to keep the existing key' : 'Enter proxy access key' }}">
        </label>
        <label>الحد الأقصى للإخراج / Max output tokens
            <input name="max_output_tokens" type="number" min="64" max="2048" value="{{ $isEditing ? ($provider['models'][0]['max_output_tokens'] ?? 512) : 512 }}">
        </label>
        <label class="provider-toggle">
            <input name="enabled" value="1" type="checkbox" {{ $isEditing && $provider['enabled'] ? 'checked' : '' }}>
            مفعّل / Enable this provider
        </label>
    </div>
    <p>يتم حفظ المفتاح دون إظهاره مرة أخرى. أثناء التشغيل: محاولة واحدة، بدون تحويل تلقائي إلى مزود آخر، وتوقيت اتصال محدود. استخدام AI يحتاج أيضًا نجاح فحص الاستضافة وتشغيل Cron الآمن.</p>
    @if ($errors->any())
        <div role="alert" class="validation-errors">
            @foreach ($errors->all() as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif
    <button class="provider-save" type="submit">حفظ إعدادات المزود / Save provider settings</button>
</form>
