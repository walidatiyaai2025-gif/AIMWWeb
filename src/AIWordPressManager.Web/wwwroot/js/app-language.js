window.appLanguage = {
  get: () => localStorage.getItem('aiwp-language') || 'en',
  set: (value) => localStorage.setItem('aiwp-language', value === 'ar' ? 'ar' : 'en'),
  apply: (lang, dir) => {
    const culture = lang === 'ar' ? 'ar' : 'en';
    const direction = culture === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.lang = culture;
    document.documentElement.dir = direction;
    document.body.dir = direction;
  },
  toggleAndReload: () => {
    const current = localStorage.getItem('aiwp-language') || 'en';
    const next = current === 'ar' ? 'en' : 'ar';
    localStorage.setItem('aiwp-language', next);
    document.documentElement.lang = next;
    document.documentElement.dir = next === 'ar' ? 'rtl' : 'ltr';
    document.body.dir = next === 'ar' ? 'rtl' : 'ltr';
    window.location.reload();
  }
};
