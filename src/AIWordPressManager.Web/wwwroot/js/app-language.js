window.appLanguage = {
  get: () => localStorage.getItem('aiwp-language') || 'en',
  set: (value) => localStorage.setItem('aiwp-language', value === 'ar' ? 'ar' : 'en'),
  apply: (lang, dir) => {
    const culture = lang === 'ar' ? 'ar' : 'en';
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
    const current = localStorage.getItem('aiwp-language') || 'en';
    const next = current === 'ar' ? 'en' : 'ar';
    const direction = next === 'ar' ? 'rtl' : 'ltr';
    localStorage.setItem('aiwp-language', next);
    document.documentElement.lang = next;
    document.documentElement.dir = direction;
    document.documentElement.dataset.appLanguage = next;
    document.documentElement.dataset.appDirection = direction;
    if (document.body) {
      document.body.dir = direction;
      document.body.dataset.appLanguage = next;
      document.body.dataset.appDirection = direction;
    }
    window.location.reload();
  }
};
