window.aiwmRecentPages = (() => {
    const recentKey = "aiwm-recent-pages";
    const favoriteKey = "aiwm-favorite-pages";
    const maxRecent = 8;
    const labels = {
        "/": ["Dashboard", "لوحة التحكم", "⌂"],
        "/sites": ["Sites", "المواقع", "◉"],
        "/module/posts": ["Posts", "المقالات", "▤"],
        "/module/pages": ["Pages", "الصفحات", "▧"],
        "/module/media": ["Media", "الوسائط", "▣"],
        "/module/taxonomy": ["Categories & Tags", "التصنيفات والوسوم", "#"],
        "/module/comments": ["Comments", "التعليقات", "◌"],
        "/module/users": ["Users", "المستخدمون", "◎"],
        "/module/seo-audit": ["SEO Audit", "تدقيق SEO", "◈"],
        "/module/ai-providers": ["AI Providers", "مزودو الذكاء", "✦"],
        "/module/execution": ["Execution Center", "مركز التنفيذ", "▶"],
        "/module/sync": ["Synchronization", "المزامنة", "↻"],
        "/module/reports": ["Reports", "التقارير", "▥"],
        "/system-health": ["System Health", "صحة النظام", "♥"],
        "/settings": ["Settings", "الإعدادات", "⚙"],
        "/about-build": ["Build Information", "معلومات الإصدار", "ⓘ"]
    };

    function read(key) {
        try { return JSON.parse(localStorage.getItem(key) || "[]"); }
        catch { return []; }
    }

    function write(key, value) {
        try { localStorage.setItem(key, JSON.stringify(value)); } catch { }
    }

    function normalizedPath() {
        const path = location.pathname || "/";
        if (path.startsWith("/sites/") && !labels[path]) return "/sites";
        return labels[path] ? path : null;
    }

    function isArabic() {
        return document.documentElement.lang === "ar" || document.documentElement.dir === "rtl";
    }

    function track() {
        const path = normalizedPath();
        if (!path) return;
        const recent = read(recentKey).filter(x => x !== path);
        recent.unshift(path);
        write(recentKey, recent.slice(0, maxRecent));
    }

    function toggleFavorite(path) {
        const favorites = read(favoriteKey);
        const index = favorites.indexOf(path);
        if (index >= 0) favorites.splice(index, 1);
        else favorites.unshift(path);
        write(favoriteKey, favorites.slice(0, 10));
        renderList();
    }

    function item(path, favorites) {
        const meta = labels[path];
        if (!meta) return "";
        const favorite = favorites.includes(path);
        const title = isArabic() ? meta[1] : meta[0];
        return `<div class="recent-page-item"><a href="${path}"><span>${meta[2]}</span><strong>${title}</strong></a><button type="button" data-favorite="${path}" aria-label="Favorite">${favorite ? "★" : "☆"}</button></div>`;
    }

    function renderList() {
        const panel = document.querySelector(".recent-pages-panel");
        if (!panel) return;
        const favorites = read(favoriteKey).filter(x => labels[x]);
        const recent = read(recentKey).filter(x => labels[x] && !favorites.includes(x));
        const titleFavorites = isArabic() ? "المفضلة" : "Favorites";
        const titleRecent = isArabic() ? "الصفحات الأخيرة" : "Recent pages";
        const empty = isArabic() ? "لا توجد صفحات محفوظة بعد." : "No saved pages yet.";
        panel.innerHTML = `<header><div><strong>${isArabic() ? "الوصول السريع" : "Quick access"}</strong><small>${isArabic() ? "المفضلة وآخر الصفحات" : "Favorites and recent pages"}</small></div><button type="button" data-close>×</button></header><section><h4>${titleFavorites}</h4>${favorites.length ? favorites.map(x => item(x, favorites)).join("") : `<p>${empty}</p>`}<h4>${titleRecent}</h4>${recent.length ? recent.map(x => item(x, favorites)).join("") : `<p>${empty}</p>`}</section>`;
        panel.querySelector("[data-close]")?.addEventListener("click", close);
        panel.querySelectorAll("[data-favorite]").forEach(button => button.addEventListener("click", event => {
            event.preventDefault();
            event.stopPropagation();
            toggleFavorite(button.dataset.favorite);
        }));
    }

    function open() {
        document.querySelector(".recent-pages-shell")?.classList.add("open");
        renderList();
    }

    function close() {
        document.querySelector(".recent-pages-shell")?.classList.remove("open");
    }

    function mount() {
        if (document.querySelector(".recent-pages-shell")) return;
        const shell = document.createElement("div");
        shell.className = "recent-pages-shell";
        shell.innerHTML = `<button type="button" class="recent-pages-trigger" title="Recent pages">★</button><div class="recent-pages-backdrop"></div><aside class="recent-pages-panel"></aside>`;
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
        track();
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

    return { open, close, track };
})();