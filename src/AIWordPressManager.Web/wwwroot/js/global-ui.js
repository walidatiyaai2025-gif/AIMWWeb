window.aiwmUi = (() => {
    const rtl = () => document.documentElement.dir === "rtl" || document.body?.dir === "rtl";
    let toastHost;
    let confirmHost;

    function ensureHosts() {
        if (!toastHost) {
            toastHost = document.createElement("div");
            toastHost.className = "aiwm-toast-host";
            toastHost.setAttribute("aria-live", "polite");
            document.body.appendChild(toastHost);
        }
        if (!confirmHost) {
            confirmHost = document.createElement("div");
            confirmHost.className = "aiwm-confirm-host";
            document.body.appendChild(confirmHost);
        }
    }

    function iconFor(type) {
        switch ((type || "info").toLowerCase()) {
            case "success": return "✓";
            case "warning": return "!";
            case "error": return "×";
            default: return "i";
        }
    }

    function toast(message, options = {}) {
        ensureHosts();
        const type = (options.type || "info").toLowerCase();
        const title = options.title || ({
            success: rtl() ? "تم بنجاح" : "Success",
            warning: rtl() ? "تنبيه" : "Warning",
            error: rtl() ? "حدث خطأ" : "Error",
            info: rtl() ? "معلومة" : "Information"
        }[type] || (rtl() ? "معلومة" : "Information"));
        const duration = Number.isFinite(options.duration) ? options.duration : (type === "error" ? 7000 : 4500);

        const node = document.createElement("section");
        node.className = `aiwm-toast ${type}`;
        node.innerHTML = `
            <span class="aiwm-toast-icon">${iconFor(type)}</span>
            <div class="aiwm-toast-copy"><strong></strong><p></p></div>
            <button type="button" aria-label="${rtl() ? "إغلاق" : "Close"}">×</button>
            <i></i>`;
        node.querySelector("strong").textContent = title;
        node.querySelector("p").textContent = String(message ?? "");
        node.querySelector("button").addEventListener("click", () => dismiss(node));
        toastHost.appendChild(node);
        requestAnimationFrame(() => node.classList.add("show"));

        if (duration > 0) {
            const timer = window.setTimeout(() => dismiss(node), duration);
            node.addEventListener("mouseenter", () => window.clearTimeout(timer), { once: true });
        }
        return node;
    }

    function dismiss(node) {
        if (!node?.isConnected) return;
        node.classList.remove("show");
        node.classList.add("hide");
        window.setTimeout(() => node.remove(), 240);
    }

    function confirm(options = {}) {
        ensureHosts();
        return new Promise(resolve => {
            const title = options.title || (rtl() ? "تأكيد العملية" : "Confirm action");
            const message = options.message || (rtl() ? "هل تريد المتابعة؟" : "Do you want to continue?");
            const confirmText = options.confirmText || (rtl() ? "تأكيد" : "Confirm");
            const cancelText = options.cancelText || (rtl() ? "إلغاء" : "Cancel");
            const tone = options.tone || "danger";

            const overlay = document.createElement("div");
            overlay.className = "aiwm-confirm-overlay";
            overlay.innerHTML = `
                <section class="aiwm-confirm-dialog ${tone}" role="dialog" aria-modal="true">
                    <header><span>${tone === "danger" ? "!" : "?"}</span><div><strong></strong><small></small></div></header>
                    <footer><button type="button" class="cancel"></button><button type="button" class="confirm"></button></footer>
                </section>`;
            overlay.querySelector("strong").textContent = title;
            overlay.querySelector("small").textContent = message;
            overlay.querySelector(".cancel").textContent = cancelText;
            overlay.querySelector(".confirm").textContent = confirmText;

            const finish = value => {
                document.removeEventListener("keydown", onKey);
                overlay.classList.remove("show");
                window.setTimeout(() => overlay.remove(), 180);
                resolve(value);
            };
            const onKey = event => {
                if (event.key === "Escape") finish(false);
                if (event.key === "Enter") finish(true);
            };
            overlay.addEventListener("click", event => { if (event.target === overlay) finish(false); });
            overlay.querySelector(".cancel").addEventListener("click", () => finish(false));
            overlay.querySelector(".confirm").addEventListener("click", () => finish(true));
            document.addEventListener("keydown", onKey);
            confirmHost.appendChild(overlay);
            requestAnimationFrame(() => {
                overlay.classList.add("show");
                overlay.querySelector(".confirm")?.focus();
            });
        });
    }

    document.addEventListener("aiwm:toast", event => {
        const detail = event.detail || {};
        toast(detail.message, detail);
    });

    document.addEventListener("submit", async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const message = form.dataset.confirm;
        if (!message || form.dataset.confirmed === "1") return;
        event.preventDefault();
        const approved = await confirm({
            title: form.dataset.confirmTitle,
            message,
            confirmText: form.dataset.confirmButton,
            tone: form.dataset.confirmTone || "danger"
        });
        if (!approved) return;
        form.dataset.confirmed = "1";
        form.requestSubmit();
    }, true);

    return {
        toast,
        success: (message, title) => toast(message, { type: "success", title }),
        warning: (message, title) => toast(message, { type: "warning", title }),
        error: (message, title) => toast(message, { type: "error", title }),
        info: (message, title) => toast(message, { type: "info", title }),
        confirm
    };
})();
