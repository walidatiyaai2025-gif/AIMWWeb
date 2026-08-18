const appLanguageCookie = 'aiwp-language';

function normalizeAppLanguage(value) {
  return value === 'ar' ? 'ar' : 'en';
}

function readAppLanguageCookie() {
  const prefix = `${appLanguageCookie}=`;
  const match = document.cookie
    .split(';')
    .map(value => value.trim())
    .find(value => value.startsWith(prefix));
  return match ? match.substring(prefix.length) : null;
}

function persistAppLanguage(value) {
  const culture = normalizeAppLanguage(value);
  localStorage.setItem(appLanguageCookie, culture);
  document.cookie = `${appLanguageCookie}=${culture};path=/;SameSite=Lax`;
  return culture;
}

window.appLanguage = {
  get: () => normalizeAppLanguage(localStorage.getItem(appLanguageCookie) || readAppLanguageCookie()),
  set: value => persistAppLanguage(value),
  apply: (lang, dir) => {
    const culture = normalizeAppLanguage(lang);
    const direction = culture === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.lang = culture;
    document.documentElement.dir = direction;
    document.documentElement.dataset.appLanguage = culture;
    document.documentElement.dataset.appDirection = direction;
    if (document.body) {
      document.body.dir = direction;
      document.body.dataset.appLanguage = culture;
      document.body.dataset.appDirection = direction;
    }
    if (window.appBidi?.sync) {
      window.appBidi.sync();
    } else {
      document.dispatchEvent(new CustomEvent('aiwp:directionchange', { detail: { language: culture, direction } }));
    }
  },
  toggleAndReload: () => {
    const current = window.appLanguage.get();
    const next = current === 'ar' ? 'en' : 'ar';
    persistAppLanguage(next);
    window.appLanguage.apply(next);
    window.location.reload();
  }
};
