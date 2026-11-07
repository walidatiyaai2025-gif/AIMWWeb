// The authenticated Blade prompt editor shares the same locale preference
// and responsive navigation state as the React dashboard. No remote requests.
const current = new URL(window.location.href);
const explicitLocale = current.searchParams.get('lang');

try {
    const savedLocale = window.localStorage.getItem('aiwm.locale');
    if (explicitLocale === 'ar' || explicitLocale === 'en') {
        window.localStorage.setItem('aiwm.locale', explicitLocale);
    } else if (savedLocale === 'ar') {
        current.searchParams.set('lang', 'ar');
        window.location.replace(current.pathname + current.search + current.hash);
    }
} catch {
    // Disabled localStorage must not break server-rendered CSRF forms.
}

const openButton = document.querySelector('[data-ai-prompt-nav-open]');
const closeButtons = document.querySelectorAll('[data-ai-prompt-nav-close]');

function setNavigation(open) {
    document.documentElement.classList.toggle('mobile-nav-open', open);
    openButton?.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
        document.querySelector('[data-ai-prompt-nav-close]')?.focus();
    } else {
        openButton?.focus();
    }
}

openButton?.addEventListener('click', () => setNavigation(true));
closeButtons.forEach((button) => button.addEventListener('click', () => setNavigation(false)));

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.documentElement.classList.contains('mobile-nav-open')) {
        setNavigation(false);
    }
});
