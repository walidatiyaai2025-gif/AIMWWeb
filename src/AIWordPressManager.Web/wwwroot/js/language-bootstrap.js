(() => {
  try {
    const language = localStorage.getItem('aiwp-language') === 'ar' ? 'ar' : 'en';
    const direction = language === 'ar' ? 'rtl' : 'ltr';
    const root = document.documentElement;
    root.lang = language;
    root.dir = direction;
    root.dataset.appLanguage = language;
    root.dataset.appDirection = direction;
  } catch {
    // Keep the server-rendered English/LTR defaults when storage is unavailable.
  }
})();
