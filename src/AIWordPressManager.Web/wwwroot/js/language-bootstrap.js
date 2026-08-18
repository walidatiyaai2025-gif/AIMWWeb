(() => {
  try {
    const cookieName = 'aiwp-language';
    const prefix = `${cookieName}=`;
    const cookieLanguage = document.cookie
      .split(';')
      .map(value => value.trim())
      .find(value => value.startsWith(prefix))
      ?.substring(prefix.length);
    const storedLanguage = localStorage.getItem(cookieName);
    const language = storedLanguage === 'ar' || (!storedLanguage && cookieLanguage === 'ar') ? 'ar' : 'en';
    const direction = language === 'ar' ? 'rtl' : 'ltr';
    const root = document.documentElement;

    root.lang = language;
    root.dir = direction;
    root.dataset.appLanguage = language;
    root.dataset.appDirection = direction;

    if (cookieLanguage !== language) {
      document.cookie = `${cookieName}=${language};path=/;SameSite=Lax`;
      if (window.location.pathname === '/welcome') {
        window.location.reload();
      }
    }
  } catch {
    // Keep the server-rendered English/LTR defaults when browser storage is unavailable.
  }
})();
