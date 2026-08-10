window.appTheme = (() => {
    const colorKey = "aiwm-color-theme";
    const modeKey = "aiwm-appearance-mode";
    const sidebarKey = "aiwm-sidebar-collapsed";
    const responsiveQuery = window.matchMedia("(max-width: 1024px)");
    const supportedColors = new Set(["gold", "ocean", "emerald", "violet", "rose", "amber", "cyan", "slate"]);
    const supportedModes = new Set(["dark", "light"]);
    let reconcileQueued = false;

    function normalizeColor(value) { return supportedColors.has(value) ? value : "gold"; }
    function normalizeMode(value) { return supportedModes.has(value) ? value : "dark"; }
    function isResponsiveDrawer() { return responsiveQuery.matches; }
    function isArabic() { return document.documentElement.lang === "ar" || document.documentElement.dir === "rtl"; }

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

    function getDesktopSidebarCollapsed() {
        try { return localStorage.getItem(sidebarKey) === "1"; }
        catch { return false; }
    }

    function getSidebarCollapsed() {
        // Responsive navigation is a drawer and always starts closed. Desktop collapse
        // preference remains independent so rotating a tablet or using a phone cannot
        // overwrite the user's desktop workspace preference.
        return isResponsiveDrawer() ? true : getDesktopSidebarCollapsed();
    }

    function syncBodyScroll(collapsed) {
        document.body.classList.toggle("responsive-navigation-open", isResponsiveDrawer() && !collapsed);
    }

    function setSidebarCollapsed(value) {
        const collapsed = Boolean(value);
        if (!isResponsiveDrawer()) {
            try { localStorage.setItem(sidebarKey, collapsed ? "1" : "0"); } catch { }
        }
        syncBodyScroll(collapsed);
        return collapsed;
    }

    function closeResponsiveNavigation() {
        if (!isResponsiveDrawer()) return;
        const shell = document.querySelector(".app-shell");
        if (!shell || shell.classList.contains("sidebar-collapsed")) return;
        document.querySelector(".sidebar-toggle")?.click();
    }

    function localizedLabel(english, arabic) {
        return isArabic() ? arabic : english;
    }

    function mountResponsiveControls() {
        const shell = document.querySelector(".design-system-shell.app-shell");
        const sidebar = shell?.querySelector(".sidebar");
        if (!shell || !sidebar) return;

        let closeButton = sidebar.querySelector(".responsive-sidebar-close");
        if (!closeButton) {
            closeButton = document.createElement("button");
            closeButton.type = "button";
            closeButton.className = "responsive-sidebar-close";
            closeButton.innerHTML = "<span aria-hidden=\"true\">×</span>";
            closeButton.addEventListener("click", closeResponsiveNavigation);
            sidebar.prepend(closeButton);
        }
        closeButton.setAttribute("aria-label", localizedLabel("Close navigation", "إغلاق قائمة التنقل"));

        let backdrop = shell.querySelector(".responsive-nav-backdrop");
        if (!backdrop) {
            backdrop = document.createElement("button");
            backdrop.type = "button";
            backdrop.className = "responsive-nav-backdrop";
            backdrop.addEventListener("click", closeResponsiveNavigation);
            const main = shell.querySelector(".main-area");
            shell.insertBefore(backdrop, main || null);
        }
        backdrop.setAttribute("aria-label", localizedLabel("Close navigation", "إغلاق قائمة التنقل"));
        backdrop.setAttribute("tabindex", "-1");
    }

    function reconcileSidebarForViewport() {
        mountResponsiveControls();
        const shell = document.querySelector(".design-system-shell.app-shell");
        const toggle = shell?.querySelector(".sidebar-toggle");
        if (!shell || !toggle) return;

        const collapsed = shell.classList.contains("sidebar-collapsed");
        if (isResponsiveDrawer()) {
            // Entering tablet/mobile mode must never leave the drawer covering content.
            if (!collapsed) toggle.click();
            else syncBodyScroll(true);
            return;
        }

        const preferred = getDesktopSidebarCollapsed();
        if (collapsed !== preferred) toggle.click();
        else syncBodyScroll(collapsed);
    }

    function queueReconcile() {
        if (reconcileQueued) return;
        reconcileQueued = true;
        requestAnimationFrame(() => {
            reconcileQueued = false;
            reconcileSidebarForViewport();
        });
    }

    document.addEventListener("click", event => {
        if (!isResponsiveDrawer()) return;
        const shell = document.querySelector(".design-system-shell.app-shell");
        if (!shell || shell.classList.contains("sidebar-collapsed")) return;

        const destination = event.target.closest(".sidebar a[href]");
        if (destination) {
            setTimeout(closeResponsiveNavigation, 0);
            return;
        }

        if (event.target.closest(".sidebar") || event.target.closest(".sidebar-toggle")) return;
        closeResponsiveNavigation();
    });

    document.addEventListener("keydown", event => {
        if (event.key === "Escape") closeResponsiveNavigation();
    });

    if (typeof responsiveQuery.addEventListener === "function") {
        responsiveQuery.addEventListener("change", queueReconcile);
    } else if (typeof responsiveQuery.addListener === "function") {
        responsiveQuery.addListener(queueReconcile);
    }

    window.addEventListener("orientationchange", queueReconcile);
    window.addEventListener("resize", queueReconcile, { passive: true });

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => {
            mountResponsiveControls();
            queueReconcile();
        });
    } else {
        mountResponsiveControls();
        queueReconcile();
    }

    applyColor(get());
    applyMode(getMode());
    return {
        get,
        set,
        apply: applyColor,
        getMode,
        setMode,
        toggleMode,
        getSidebarCollapsed,
        setSidebarCollapsed,
        closeMobileNavigation: closeResponsiveNavigation,
        closeResponsiveNavigation,
        isResponsiveDrawer,
        reconcileSidebarForViewport
    };
})();
