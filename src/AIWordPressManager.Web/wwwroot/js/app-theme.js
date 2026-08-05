window.appTheme = (() => {
    const colorKey = "aiwm-color-theme";
    const modeKey = "aiwm-appearance-mode";
    const sidebarKey = "aiwm-sidebar-collapsed";
    const supportedColors = new Set(["gold", "ocean", "emerald", "violet", "rose", "amber", "cyan", "slate"]);
    const supportedModes = new Set(["dark", "light"]);

    function normalizeColor(value) { return supportedColors.has(value) ? value : "gold"; }
    function normalizeMode(value) { return supportedModes.has(value) ? value : "dark"; }
    function isMobile() { return window.matchMedia("(max-width: 700px)").matches; }

    function applyColor(value) {
        const theme = normalizeColor(value);
        if (theme === "gold") document.documentElement.removeAttribute("data-theme");
        else document.documentElement.setAttribute("data-theme", theme);
        return theme;
    }

    function applyMode(value) {
        const mode = normalizeMode(value);
        document.documentElement.setAttribute("data-mode", mode);
        document.documentElement.style.colorScheme = mode;
        return mode;
    }

    function get() {
        try { return normalizeColor(localStorage.getItem(colorKey)); }
        catch { return "gold"; }
    }

    function getMode() {
        try { return normalizeMode(localStorage.getItem(modeKey)); }
        catch { return "dark"; }
    }

    function set(value) {
        const theme = applyColor(value);
        try { localStorage.setItem(colorKey, theme); } catch { }
        return theme;
    }

    function setMode(value) {
        const mode = applyMode(value);
        try { localStorage.setItem(modeKey, mode); } catch { }
        return mode;
    }

    function toggleMode() { return setMode(getMode() === "dark" ? "light" : "dark"); }

    function getSidebarCollapsed() {
        try {
            const stored = localStorage.getItem(sidebarKey);
            if (stored === null) return isMobile();
            return stored === "1";
        }
        catch { return isMobile(); }
    }

    function syncBodyScroll(collapsed) {
        document.body.classList.toggle("mobile-navigation-open", isMobile() && !collapsed);
    }

    function setSidebarCollapsed(value) {
        const collapsed = Boolean(value);
        try { localStorage.setItem(sidebarKey, collapsed ? "1" : "0"); } catch { }
        syncBodyScroll(collapsed);
        return collapsed;
    }

    function closeMobileNavigation() {
        if (!isMobile()) return;
        const shell = document.querySelector(".app-shell");
        if (!shell || shell.classList.contains("sidebar-collapsed")) return;
        document.querySelector(".sidebar-toggle")?.click();
    }

    document.addEventListener("click", event => {
        if (!isMobile()) return;
        const shell = document.querySelector(".app-shell");
        if (!shell || shell.classList.contains("sidebar-collapsed")) return;
        if (event.target.closest(".sidebar") || event.target.closest(".sidebar-toggle")) return;
        closeMobileNavigation();
    });

    document.addEventListener("keydown", event => {
        if (event.key === "Escape") closeMobileNavigation();
    });

    window.addEventListener("resize", () => {
        const shell = document.querySelector(".app-shell");
        syncBodyScroll(shell?.classList.contains("sidebar-collapsed") ?? true);
    });

    applyColor(get());
    applyMode(getMode());
    return { get, set, apply: applyColor, getMode, setMode, toggleMode, getSidebarCollapsed, setSidebarCollapsed, closeMobileNavigation };
})();
