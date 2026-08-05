window.appTheme = (() => {
    const storageKey = "aiwm-color-theme";
    const supported = new Set(["gold", "ocean", "emerald", "violet", "rose", "amber", "cyan", "slate"]);

    function normalize(value) {
        return supported.has(value) ? value : "gold";
    }

    function apply(value) {
        const theme = normalize(value);
        if (theme === "gold") document.documentElement.removeAttribute("data-theme");
        else document.documentElement.setAttribute("data-theme", theme);
        document.documentElement.style.colorScheme = "dark";
        return theme;
    }

    function get() {
        try { return normalize(localStorage.getItem(storageKey)); }
        catch { return "gold"; }
    }

    function set(value) {
        const theme = apply(value);
        try { localStorage.setItem(storageKey, theme); } catch { }
        return theme;
    }

    apply(get());
    return { get, set, apply };
})();
