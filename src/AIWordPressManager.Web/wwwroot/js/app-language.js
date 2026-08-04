window.appLanguage = {
  get: () => localStorage.getItem('aiwp-language') || 'ar',
  set: (value) => localStorage.setItem('aiwp-language', value),
  apply: (lang, dir) => {
    document.documentElement.lang = lang;
    document.documentElement.dir = dir;
    document.body.dir = dir;
  }
};
