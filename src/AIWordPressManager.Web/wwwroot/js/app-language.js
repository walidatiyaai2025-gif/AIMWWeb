window.appLanguage = {
  get: () => localStorage.getItem('aiwp-language') || 'en',
  set: (value) => localStorage.setItem('aiwp-language', value),
  apply: (lang, dir) => {
    const culture = lang === 'ar' ? 'ar' : 'en';
    const direction = culture === 'ar' ? 'rtl' : 'ltr';

    document.documentElement.lang = culture;
    document.documentElement.dir = direction;
    document.body.dir = direction;
  }
};
