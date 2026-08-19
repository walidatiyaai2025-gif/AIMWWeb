window.aiwmRecentPages = (() => {
    const recentKey = "aiwm-recent-pages";
    const favoriteKey = "aiwm-favorite-pages";
    const maxRecent = 10;
    const maxFavorites = 10;
    let labels = Object.create(null);

    function read(key) {
        try {
            const value = JSON.parse(localStorage.getItem(key) || "[]");
            return Array.isArray(value) ? value : [];
        } catch {
            return [];
        }
    }

    function write(key, value) {
        try { localStorage.setItem(key, JSON.stringify(value)); } catch { }
    }

    function isArabic() {
        return document.documentElement.lang === "ar" || document.documentElement.dir === "rtl";
    }

    function titleFor(path) {
        const meta = labels[path];
        return meta ? (isArabic() ? meta.arabic : meta.english) : path;
    }

    function normalizedPath() {
        const path = location.pathname || "/";
        if (labels[path]) return path;

        const parent = Object.keys(labels)
            .filter(candidate => candidate !== "/" && path.startsWith(`${candidate}/`))
            .sort((a, b) => b.length - a.length)[0];

        return parent || null;
    }

    function track() {
        const path = normalizedPath();
        if (!path) return;
        const recent = read(recentKey).filter(item => item !== path && labels[item]);
        recent.unshift(path);
        write(recentKey, recent.slice(0, maxRecent));
    }

    function toggleFavorite(path) {
        if (!labels[path]) return;
        const favorites = read(favoriteKey).filter(item => labels[item]);
        const index = favorites.indexOf(path);
        if (index >= 0) favorites.splice(index, 1);
        else favorites.unshift(path);
        write(favoriteKey, favorites.slice(0, maxFavorites));
        renderList();
    }

    function item(path, favorites) {
        const meta = labels[path];
        if (!meta) return "";
        const favorite = favorites.includes(path);
        const title = titleFor(path);
        const favoriteLabel = favorite
            ? (isArabic() ? `إزالة ${title} من المفضلة` : `Remove ${title} from favorites`)
            : (isArabic() ? `إضافة ${title} إلى المفضلة` : `Add ${title} to favorites`);

        return `<div class="recent-page-item">
            <a href="${path}" title="${title}"><span aria-hidden="true">${meta.icon}</span><strong>${title}</strong></a>
            <button type="button" data-favorite="${path}" aria-label="${favoriteLabel}" aria-pressed="${favorite}">${favorite ? "★" : "☆"}</button>
        </div>`;
    }

    function applyPanelState(shell, panel) {
        if (!(shell instanceof HTMLElement) || !(panel instanceof HTMLElement)) return;
        const opened = shell.classList.contains("open");

        panel.setAttribute("role", "dialog");
        panel.setAttribute("aria-modal", opened ? "true" : "false");
        panel.setAttribute("aria-hidden", opened ? "false" : "true");

        // A closed off-canvas panel must be removed from keyboard navigation and
        // accessibility focus management. Otherwise the global focus trap can see it
        // as a live modal and steal focus from ordinary form inputs.
        if (opened) panel.removeAttribute("inert");
        else panel.setAttribute("inert", "");
    }

    function renderList() {
        const panel = document.querySelector(".recent-pages-panel");
        const shell = document.querySelector(".recent-pages-shell");
        const trigger = document.querySelector(".recent-pages-trigger");
        if (!panel) return;

        const favorites = read(favoriteKey).filter(path => labels[path]);
        const recent = read(recentKey).filter(path => labels[path] && !favorites.includes(path));
        const titleFavorites = isArabic() ? "المفضلة" : "Favorites";
        const titleRecent = isArabic() ? "الصفحات الأخيرة" : "Recent pages";
        const emptyFavorites = isArabic() ? "لم تضف صفحات للمفضلة بعد." : "No favorite pages yet.";
        const emptyRecent = isArabic() ? "لم تزر صفحات مسجلة بعد." : "No recent pages yet.";
        const panelTitle = isArabic() ? "الوصول السريع" : "Quick access";
        const panelSubtitle = isArabic() ? "المفضلة وآخر مساحات العمل" : "Favorites and recent workspaces";
        const closeLabel = isArabic() ? "إغلاق الوصول السريع" : "Close quick access";
        const triggerLabel = isArabic() ? "فتح المفضلة والصفحات الأخيرة" : "Open favorites and recent pages";

        if (trigger) {
            trigger.title = `${triggerLabel} — Ctrl+Shift+P`;
            trigger.setAttribute("aria-label", triggerLabel);
        }

        panel.setAttribute("aria-label", panelTitle);
        panel.innerHTML = `<header>
            <div><strong>${panelTitle}</strong><small>${panelSubtitle}</small></div>
            <button type="button" data-close aria-label="${closeLabel}">×</button>
        </header>
        <section>
            <h4>${titleFavorites}</h4>
            ${favorites.length ? favorites.map(path => item(path, favorites)).join("") : `<p>${emptyFavorites}</p>`}
            <h4>${titleRecent}</h4>
            ${recent.length ? recent.map(path => item(path, favorites)).join("") : `<p>${emptyRecent}</p>`}
        </section>`;

        applyPanelState(shell, panel);
        panel.querySelector("[data-close]")?.addEventListener("click", close);
        panel.querySelectorAll("[data-favorite]").forEach(button => button.addEventListener("click", event => {
            event.preventDefault();
            event.stopPropagation();
            toggleFavorite(button.dataset.favorite);
        }));
    }

    function open() {
        const shell = document.querySelector(".recent-pages-shell");
        const panel = shell?.querySelector(".recent-pages-panel");
        shell?.classList.add("open");
        shell?.querySelector(".recent-pages-trigger")?.setAttribute("aria-expanded", "true");
        applyPanelState(shell, panel);
        renderList();
        setTimeout(() => shell?.querySelector("[data-close]")?.focus(), 0);
    }

    function close() {
        const shell = document.querySelector(".recent-pages-shell");
        const panel = shell?.querySelector(".recent-pages-panel");
        shell?.classList.remove("open");
        shell?.querySelector(".recent-pages-trigger")?.setAttribute("aria-expanded", "false");
        applyPanelState(shell, panel);
    }

    function setCatalog(items) {
        const next = Object.create(null);
        (Array.isArray(items) ? items : []).forEach(item => {
            if (!item || typeof item.path !== "string" || !item.path.startsWith("/")) return;
            next[item.path] = {
                english: String(item.english || item.path),
                arabic: String(item.arabic || item.english || item.path),
                icon: String(item.icon || "•")
            };
        });
        labels = next;

        write(favoriteKey, read(favoriteKey).filter(path => labels[path]).slice(0, maxFavorites));
        write(recentKey, read(recentKey).filter(path => labels[path]).slice(0, maxRecent));
        track();
        renderList();
    }

    function mount() {
        if (document.querySelector(".recent-pages-shell")) return;
        const shell = document.createElement("div");
        shell.className = "recent-pages-shell";
        shell.innerHTML = `<button type="button" class="recent-pages-trigger" aria-haspopup="dialog" aria-expanded="false">★</button><div class="recent-pages-backdrop"></div><aside class="recent-pages-panel" role="dialog" aria-modal="false" aria-hidden="true" inert></aside>`;
        document.body.appendChild(shell);
        shell.querySelector(".recent-pages-trigger")?.addEventListener("click", open);
        shell.querySelector(".recent-pages-backdrop")?.addEventListener("click", close);
        document.addEventListener("keydown", event => {
            if (event.key === "Escape") close();
            if ((event.ctrlKey || event.metaKey) && event.shiftKey && event.key.toLowerCase() === "p") {
                event.preventDefault();
                open();
            }
        });
    }

    function init() {
        mount();
        let current = location.pathname;
        setInterval(() => {
            if (location.pathname !== current) {
                current = location.pathname;
                track();
            }
        }, 700);
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
    else init();

    return { open, close, track, setCatalog };
})();
