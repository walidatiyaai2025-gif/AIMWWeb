window.appTheme = (() => {
    const colorKey = "aiwm-color-theme";
    const modeKey = "aiwm-appearance-mode";
    const sidebarKey = "aiwm-sidebar-collapsed";
    const supportedColors = new Set(["gold", "ocean", "emerald", "violet", "rose", "amber", "cyan", "slate"]);
    const supportedModes = new Set(["dark", "light"]);

    function normalizeColor(value) { return supportedColors.has(value) ? value : "gold"; }
    function normalizeMode(value) { return supportedModes.has(value) ? value : "dark"; }

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
        try { return localStorage.getItem(sidebarKey) === "1"; }
        catch { return false; }
    }

    function setSidebarCollapsed(value) {
        const collapsed = Boolean(value);
        try { localStorage.setItem(sidebarKey, collapsed ? "1" : "0"); } catch { }
        return collapsed;
    }

    applyColor(get());
    applyMode(getMode());
    return { get, set, apply: applyColor, getMode, setMode, toggleMode, getSidebarCollapsed, setSidebarCollapsed };
})();
