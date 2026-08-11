(() => {
  const root = document.documentElement;

  function normalizeLanguage(value) {
    return String(value || '').toLowerCase().startsWith('ar') ? 'ar' : 'en';
  }

  function normalizeDirection(value, language) {
    return value === 'rtl' || language === 'ar' ? 'rtl' : 'ltr';
  }

  function sync() {
    const language = normalizeLanguage(root.lang || localStorage.getItem('aiwp-language'));
    const direction = normalizeDirection(root.dir, language);
    root.lang = language;
    root.dir = direction;
    root.dataset.appLanguage = language;
    root.dataset.appDirection = direction;
    if (document.body) {
      document.body.dir = direction;
      document.body.dataset.appLanguage = language;
      document.body.dataset.appDirection = direction;
    }
    document.dispatchEvent(new CustomEvent('aiwp:directionchange', { detail: { language, direction } }));
    return { language, direction };
  }

  function isRtl() {
    return sync().direction === 'rtl';
  }

  function markTechnical(element) {
    if (!element) return;
    element.setAttribute('dir', 'ltr');
    element.setAttribute('data-bidi', 'technical');
  }

  function markNumber(element) {
    if (!element) return;
    element.setAttribute('dir', 'ltr');
    element.setAttribute('data-bidi', 'number');
  }

  const observer = new MutationObserver((records) => {
    if (records.some(record => record.attributeName === 'dir' || record.attributeName === 'lang')) sync();
  });

  observer.observe(root, { attributes: true, attributeFilter: ['dir', 'lang'] });
  document.addEventListener('DOMContentLoaded', sync, { once: true });

  window.appBidi = {
    sync,
    isRtl,
    direction: () => sync().direction,
    language: () => sync().language,
    inlineStart: () => isRtl() ? 'right' : 'left',
    inlineEnd: () => isRtl() ? 'left' : 'right',
    markTechnical,
    markNumber
  };

  sync();
})();
