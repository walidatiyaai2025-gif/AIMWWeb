(() => {
    const managedDialogs = new Map();
    let liveRegion = null;
    let lastPageTitle = null;
    let announceTimer = 0;

    function isVisible(element) {
        if (!(element instanceof HTMLElement) || !element.isConnected) return false;
        const style = window.getComputedStyle(element);
        return style.display !== "none" && style.visibility !== "hidden" && element.getClientRects().length > 0;
    }

    function focusableElements(root) {
        if (!(root instanceof HTMLElement)) return [];
        const selector = [
            "a[href]",
            "button:not([disabled])",
            "input:not([disabled]):not([type='hidden'])",
            "select:not([disabled])",
            "textarea:not([disabled])",
            "summary",
            "[tabindex]:not([tabindex='-1'])"
        ].join(",");

        return Array.from(root.querySelectorAll(selector))
            .filter(element => element instanceof HTMLElement)
            .filter(element => element.getAttribute("aria-hidden") !== "true")
            .filter(element => element.getAttribute("aria-disabled") !== "true")
            .filter(isVisible);
    }

    function currentDialogs() {
        return Array.from(document.querySelectorAll('[role="dialog"][aria-modal="true"]'))
            .filter(element => element instanceof HTMLElement)
            .filter(isVisible);
    }

    function topDialog() {
        const dialogs = currentDialogs();
        return dialogs.length ? dialogs[dialogs.length - 1] : null;
    }

    function firstDialogTarget(dialog) {
        if (!(dialog instanceof HTMLElement)) return null;
        const preferred = dialog.querySelector("[autofocus], [data-a11y-autofocus]");
        if (preferred instanceof HTMLElement && isVisible(preferred)) return preferred;
        return focusableElements(dialog)[0] || dialog;
    }

    function manageDialog(dialog) {
        if (!(dialog instanceof HTMLElement) || managedDialogs.has(dialog)) return;
        if (!dialog.hasAttribute("tabindex")) dialog.setAttribute("tabindex", "-1");

        const active = document.activeElement;
        const opener = active instanceof HTMLElement && active !== document.body ? active : null;
        managedDialogs.set(dialog, { opener });
        document.body.classList.add("has-a11y-modal-dialog");

        window.requestAnimationFrame(() => {
            const top = topDialog();
            if (top !== dialog) return;
            const target = firstDialogTarget(dialog);
            target?.focus({ preventScroll: true });
        });
    }

    function releaseDialog(dialog) {
        const state = managedDialogs.get(dialog);
        managedDialogs.delete(dialog);

        if (managedDialogs.size === 0) {
            document.body.classList.remove("has-a11y-modal-dialog");
        }

        if (state?.opener instanceof HTMLElement && state.opener.isConnected && isVisible(state.opener)) {
            window.requestAnimationFrame(() => state.opener.focus({ preventScroll: true }));
        }
    }

    function ensureLiveRegion() {
        if (liveRegion?.isConnected) return liveRegion;
        liveRegion = document.createElement("div");
        liveRegion.className = "a11y-live-region sr-only";
        liveRegion.setAttribute("role", "status");
        liveRegion.setAttribute("aria-live", "polite");
        liveRegion.setAttribute("aria-atomic", "true");
        document.body.append(liveRegion);
        return liveRegion;
    }

    function announce(message, politeness = "polite") {
        if (!message) return;
        const region = ensureLiveRegion();
        region.setAttribute("aria-live", politeness === "assertive" ? "assertive" : "polite");
        region.textContent = "";
        window.clearTimeout(announceTimer);
        announceTimer = window.setTimeout(() => {
            region.textContent = String(message);
        }, 30);
    }

    function focusMain(id = "main-content", announcement = null) {
        const main = document.getElementById(id);
        if (!(main instanceof HTMLElement)) return false;
        if (!main.hasAttribute("tabindex")) main.setAttribute("tabindex", "-1");
        main.focus({ preventScroll: true });
        if (announcement) announce(announcement);
        return true;
    }

    function syncPageContext() {
        const title = document.getElementById("page-title")?.textContent?.trim();
        if (!title) return;
        if (lastPageTitle === null) {
            lastPageTitle = title;
            return;
        }
        if (title === lastPageTitle) return;

        lastPageTitle = title;
        window.requestAnimationFrame(() => focusMain("main-content", title));
    }

    function syncDialogs() {
        const visibleDialogs = new Set(currentDialogs());
        visibleDialogs.forEach(manageDialog);

        for (const dialog of Array.from(managedDialogs.keys())) {
            if (!visibleDialogs.has(dialog)) releaseDialog(dialog);
        }

        syncPageContext();
    }

    document.addEventListener("keydown", event => {
        const dialog = topDialog();
        if (!dialog) return;

        if (event.key === "Escape") {
            const closeButton = dialog.querySelector("[data-a11y-close]");
            if (closeButton instanceof HTMLElement && closeButton.getAttribute("aria-disabled") !== "true") {
                event.preventDefault();
                closeButton.click();
            }
            return;
        }

        if (event.key !== "Tab") return;
        const focusable = focusableElements(dialog);
        if (focusable.length === 0) {
            event.preventDefault();
            dialog.focus({ preventScroll: true });
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        const active = document.activeElement;
        if (event.shiftKey && (active === first || !dialog.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }, true);

    document.addEventListener("focusin", event => {
        const dialog = topDialog();
        if (!dialog || dialog.contains(event.target)) return;
        const target = firstDialogTarget(dialog);
        target?.focus({ preventScroll: true });
    }, true);

    function start() {
        ensureLiveRegion();
        syncDialogs();
        const observer = new MutationObserver(syncDialogs);
        observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start, { once: true });
    } else {
        start();
    }

    window.aiwmAccessibilityRuntime = { announce, focusMain, syncDialogs };
})();
