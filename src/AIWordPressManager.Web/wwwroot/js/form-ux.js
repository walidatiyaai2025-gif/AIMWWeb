(() => {
    let lastFocusedSummary = null;

    function visible(element) {
        if (!(element instanceof HTMLElement) || !element.isConnected) return false;
        const style = getComputedStyle(element);
        return style.display !== "none" && style.visibility !== "hidden" && element.getClientRects().length > 0;
    }

    function invalidControls(root = document) {
        return Array.from(root.querySelectorAll('[aria-invalid="true"], :invalid'))
            .filter(element => element instanceof HTMLElement)
            .filter(element => !element.hasAttribute("disabled"))
            .filter(visible);
    }

    function focusFirstInvalid(root = document) {
        const target = invalidControls(root)[0];
        if (target instanceof HTMLElement) {
            target.focus({ preventScroll: true });
            target.scrollIntoView({ block: "center", behavior: "auto" });
            return true;
        }
        return false;
    }

    function focusValidationSummary(summary) {
        if (!(summary instanceof HTMLElement) || !visible(summary)) return;
        if (summary.dataset.autoFocus !== "true") return;
        if (lastFocusedSummary === summary) return;
        lastFocusedSummary = summary;
        requestAnimationFrame(() => summary.focus({ preventScroll: true }));
    }

    function scan(root = document) {
        root.querySelectorAll?.("[data-form-validation-summary]").forEach(focusValidationSummary);
    }

    document.addEventListener("invalid", event => {
        if (!(event.target instanceof HTMLElement)) return;
        event.target.setAttribute("aria-invalid", "true");
    }, true);

    document.addEventListener("input", event => {
        if (!(event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement || event.target instanceof HTMLSelectElement)) return;
        if (event.target.checkValidity()) event.target.removeAttribute("aria-invalid");
    }, true);

    function start() {
        scan();
        const observer = new MutationObserver(records => {
            for (const record of records) {
                if (record.type === "childList") {
                    record.addedNodes.forEach(node => {
                        if (!(node instanceof HTMLElement)) return;
                        if (node.matches?.("[data-form-validation-summary]")) focusValidationSummary(node);
                        scan(node);
                    });
                }
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start, { once: true });
    else start();

    window.aiwmFormUx = { focusFirstInvalid, invalidControls };
})();
