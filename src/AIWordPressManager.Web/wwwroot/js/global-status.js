window.aiwmGlobalStatus = (() => {
    let activeRequests = 0;
    let hideTimer;
    let healthTimer;

    function isArabic() {
        return document.documentElement.lang === "ar" || document.documentElement.dir === "rtl";
    }

    function ensureUi() {
        if (document.getElementById("aiwm-global-progress")) return;

        const progress = document.createElement("div");
        progress.id = "aiwm-global-progress";
        progress.setAttribute("aria-hidden", "true");
        progress.innerHTML = '<span></span>';

        const status = document.createElement("div");
        status.id = "aiwm-connection-status";
        status.className = "online";
        status.setAttribute("role", "status");
        status.setAttribute("aria-live", "polite");
        status.innerHTML = '<span class="status-pulse"></span><strong></strong><button type="button"></button>';
        status.querySelector("button").addEventListener("click", () => location.reload());

        document.body.append(progress, status);
        updateStatus(navigator.onLine ? "online" : "offline");
    }

    function begin() {
        ensureUi();
        activeRequests++;
        clearTimeout(hideTimer);
        document.getElementById("aiwm-global-progress")?.classList.add("active");
    }

    function end() {
        activeRequests = Math.max(0, activeRequests - 1);
        if (activeRequests > 0) return;
        hideTimer = setTimeout(() => document.getElementById("aiwm-global-progress")?.classList.remove("active"), 180);
    }

    function updateStatus(mode) {
        ensureUi();
        const host = document.getElementById("aiwm-connection-status");
        if (!host) return;
        const ar = isArabic();
        host.className = mode;
        const label = host.querySelector("strong");
        const button = host.querySelector("button");

        if (mode === "offline") {
            label.textContent = ar ? "لا يوجد اتصال بالإنترنت" : "You are offline";
            button.textContent = ar ? "إعادة المحاولة" : "Retry";
            host.classList.add("visible");
        } else if (mode === "reconnecting") {
            label.textContent = ar ? "جاري استعادة الاتصال..." : "Reconnecting...";
            button.textContent = ar ? "تحديث" : "Refresh";
            host.classList.add("visible");
        } else if (mode === "unavailable") {
            label.textContent = ar ? "تعذر الوصول إلى الخدمة" : "Service is unavailable";
            button.textContent = ar ? "إعادة المحاولة" : "Retry";
            host.classList.add("visible");
        } else {
            label.textContent = ar ? "تم استعادة الاتصال" : "Connection restored";
            button.textContent = ar ? "تحديث" : "Refresh";
            host.classList.add("visible");
            setTimeout(() => host.classList.remove("visible"), 2200);
        }
    }

    async function checkHealth() {
        if (!navigator.onLine) {
            updateStatus("offline");
            return;
        }
        try {
            const response = await fetch("/health/live", { method: "GET", cache: "no-store", headers: { "X-AIWM-Health": "1" } });
            updateStatus(response.ok ? "online" : "unavailable");
        } catch {
            updateStatus("reconnecting");
        }
    }

    function wrapFetch() {
        if (window.__aiwmFetchWrapped) return;
        window.__aiwmFetchWrapped = true;
        const nativeFetch = window.fetch.bind(window);
        window.fetch = async (...args) => {
            const healthRequest = String(args[0] ?? "").includes("/health/live");
            if (!healthRequest) begin();
            try {
                return await nativeFetch(...args);
            } finally {
                if (!healthRequest) end();
            }
        };
    }

    function registerNavigation() {
        document.addEventListener("click", event => {
            const link = event.target.closest?.("a[href]");
            if (!link || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const href = link.getAttribute("href");
            if (!href || href.startsWith("#") || href.startsWith("http") || link.target === "_blank" || link.hasAttribute("download")) return;
            begin();
            setTimeout(end, 1200);
        }, true);

        window.addEventListener("popstate", () => { begin(); setTimeout(end, 900); });
        window.addEventListener("online", () => { updateStatus("online"); checkHealth(); });
        window.addEventListener("offline", () => updateStatus("offline"));
    }

    function register() {
        ensureUi();
        wrapFetch();
        registerNavigation();
        clearInterval(healthTimer);
        healthTimer = setInterval(checkHealth, 30000);
        setTimeout(checkHealth, 1500);
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", register, { once: true });
    else register();

    return { begin, end, checkHealth, updateStatus };
})();
