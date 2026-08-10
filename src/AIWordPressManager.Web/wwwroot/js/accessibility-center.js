window.aiwmAccessibility = (() => {
    const keys = {
        font: "aiwm-accessibility-font",
        density: "aiwm-accessibility-density",
        contrast: "aiwm-accessibility-contrast",
        motion: "aiwm-accessibility-motion"
    };

    const panelId = "aiwm-accessibility-panel";
    const panelTitleId = "aiwm-accessibility-title";
    const state = {
        font: localStorage.getItem(keys.font) || "normal",
        density: localStorage.getItem(keys.density) || "default",
        contrast: localStorage.getItem(keys.contrast) === "high",
        motion: localStorage.getItem(keys.motion) === "reduced"
    };

    function isArabic() {
        return (document.documentElement.lang || "").toLowerCase().startsWith("ar") || document.documentElement.dir === "rtl";
    }

    function apply() {
        document.documentElement.setAttribute("data-font-scale", state.font);
        document.documentElement.setAttribute("data-density", state.density);
        document.documentElement.setAttribute("data-contrast", state.contrast ? "high" : "normal");
        document.documentElement.setAttribute("data-reduced-motion", state.motion ? "true" : "false");
    }

    function save(focusKey = null) {
        localStorage.setItem(keys.font, state.font);
        localStorage.setItem(keys.density, state.density);
        localStorage.setItem(keys.contrast, state.contrast ? "high" : "normal");
        localStorage.setItem(keys.motion, state.motion ? "reduced" : "normal");
        apply();
        renderPanel(focusKey);
    }

    function close(restoreFocus = true) {
        document.querySelector(".aiwm-accessibility-panel")?.remove();
        const trigger = document.querySelector(".aiwm-accessibility-trigger");
        trigger?.setAttribute("aria-expanded", "false");
        if (restoreFocus && trigger instanceof HTMLElement) trigger.focus({ preventScroll: true });
    }

    function optionButton(label, value, current, handler, focusKey) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = `aiwm-accessibility-option${value === current ? " active" : ""}`;
        button.textContent = label;
        button.dataset.focusKey = focusKey;
        button.setAttribute("aria-pressed", String(value === current));
        button.addEventListener("click", handler);
        return button;
    }

    function toggleRow(title, description, active, handler, focusKey) {
        const row = document.createElement("div");
        row.className = "aiwm-accessibility-toggle";
        const copy = document.createElement("span");
        const strong = document.createElement("strong");
        strong.textContent = title;
        const small = document.createElement("small");
        small.textContent = description;
        copy.append(strong, small);
        const button = document.createElement("button");
        button.type = "button";
        button.className = `aiwm-switch${active ? " active" : ""}`;
        button.dataset.focusKey = focusKey;
        button.setAttribute("aria-label", title);
        button.setAttribute("aria-pressed", String(active));
        button.addEventListener("click", handler);
        row.append(copy, button);
        return row;
    }

    function renderPanel(focusKey = null) {
        const old = document.querySelector(".aiwm-accessibility-panel");
        if (!old) return;
        const ar = isArabic();
        old.innerHTML = "";

        const header = document.createElement("header");
        const titleWrap = document.createElement("div");
        const title = document.createElement("strong");
        title.id = panelTitleId;
        title.textContent = ar ? "سهولة الوصول والعرض" : "Accessibility & display";
        const subtitle = document.createElement("small");
        subtitle.textContent = ar ? "خصص شكل النظام بما يناسبك" : "Customize the interface for your needs";
        titleWrap.append(title, subtitle);
        const closeButton = document.createElement("button");
        closeButton.type = "button";
        closeButton.textContent = "×";
        closeButton.dataset.focusKey = "close";
        closeButton.setAttribute("aria-label", ar ? "إغلاق إعدادات سهولة الوصول" : "Close accessibility settings");
        closeButton.addEventListener("click", () => close(true));
        header.append(titleWrap, closeButton);

        const body = document.createElement("div");
        body.className = "aiwm-accessibility-body";

        const fontSection = document.createElement("section");
        fontSection.className = "aiwm-accessibility-section";
        const fontTitle = document.createElement("strong");
        fontTitle.textContent = ar ? "حجم الخط" : "Text size";
        const fontOptions = document.createElement("div");
        fontOptions.className = "aiwm-accessibility-options";
        fontOptions.append(
            optionButton(ar ? "صغير" : "Small", "small", state.font, () => { state.font = "small"; save("font-small"); }, "font-small"),
            optionButton(ar ? "عادي" : "Normal", "normal", state.font, () => { state.font = "normal"; save("font-normal"); }, "font-normal"),
            optionButton(ar ? "كبير" : "Large", "large", state.font, () => { state.font = "large"; save("font-large"); }, "font-large")
        );
        fontSection.append(fontTitle, fontOptions);

        const densitySection = document.createElement("section");
        densitySection.className = "aiwm-accessibility-section";
        const densityTitle = document.createElement("strong");
        densityTitle.textContent = ar ? "كثافة العرض" : "Display density";
        const densityOptions = document.createElement("div");
        densityOptions.className = "aiwm-accessibility-options";
        densityOptions.append(
            optionButton(ar ? "مضغوط" : "Compact", "compact", state.density, () => { state.density = "compact"; save("density-compact"); }, "density-compact"),
            optionButton(ar ? "افتراضي" : "Default", "default", state.density, () => { state.density = "default"; save("density-default"); }, "density-default"),
            optionButton(ar ? "مريح" : "Comfortable", "comfortable", state.density, () => { state.density = "comfortable"; save("density-comfortable"); }, "density-comfortable")
        );
        densitySection.append(densityTitle, densityOptions);

        const behaviorSection = document.createElement("section");
        behaviorSection.className = "aiwm-accessibility-section";
        behaviorSection.append(
            toggleRow(ar ? "تباين عالٍ" : "High contrast", ar ? "زيادة وضوح الحدود والنصوص" : "Improve borders and text visibility", state.contrast, () => { state.contrast = !state.contrast; save("contrast"); }, "contrast"),
            toggleRow(ar ? "تقليل الحركة" : "Reduce motion", ar ? "إيقاف الحركات والانتقالات" : "Minimize animations and transitions", state.motion, () => { state.motion = !state.motion; save("motion"); }, "motion")
        );

        body.append(fontSection, densitySection, behaviorSection);

        const footer = document.createElement("footer");
        footer.className = "aiwm-accessibility-footer";
        const reset = document.createElement("button");
        reset.type = "button";
        reset.className = "aiwm-accessibility-reset";
        reset.dataset.focusKey = "reset";
        reset.textContent = ar ? "استعادة الإعدادات الافتراضية" : "Reset to defaults";
        reset.addEventListener("click", () => {
            state.font = "normal";
            state.density = "default";
            state.contrast = false;
            state.motion = false;
            save("reset");
            window.aiwmUi?.info?.(ar ? "تمت استعادة إعدادات العرض" : "Display settings restored");
            window.aiwmAccessibilityRuntime?.announce?.(ar ? "تمت استعادة إعدادات العرض" : "Display settings restored");
        });
        footer.append(reset);
        old.append(header, body, footer);

        if (focusKey) {
            window.requestAnimationFrame(() => old.querySelector(`[data-focus-key="${focusKey}"]`)?.focus());
        }
    }

    function togglePanel() {
        let panel = document.querySelector(".aiwm-accessibility-panel");
        const trigger = document.querySelector(".aiwm-accessibility-trigger");
        if (panel) {
            close(true);
            return;
        }
        panel = document.createElement("section");
        panel.id = panelId;
        panel.className = "aiwm-accessibility-panel";
        panel.setAttribute("role", "dialog");
        panel.setAttribute("aria-modal", "false");
        panel.setAttribute("aria-labelledby", panelTitleId);
        document.body.append(panel);
        trigger?.setAttribute("aria-expanded", "true");
        renderPanel("close");
    }

    function mount() {
        if (document.querySelector(".aiwm-accessibility-trigger")) return;
        const trigger = document.createElement("button");
        trigger.type = "button";
        trigger.className = "aiwm-accessibility-trigger";
        trigger.setAttribute("aria-expanded", "false");
        trigger.setAttribute("aria-haspopup", "dialog");
        trigger.setAttribute("aria-controls", panelId);
        trigger.setAttribute("aria-keyshortcuts", "Control+Alt+A");
        trigger.setAttribute("aria-label", isArabic() ? "إعدادات سهولة الوصول" : "Accessibility settings");
        trigger.textContent = "Aa";
        trigger.addEventListener("click", togglePanel);
        document.body.append(trigger);
    }

    document.addEventListener("click", event => {
        const panel = document.querySelector(".aiwm-accessibility-panel");
        if (!panel) return;
        if (panel.contains(event.target) || document.querySelector(".aiwm-accessibility-trigger")?.contains(event.target)) return;
        close(false);
    });

    document.addEventListener("keydown", event => {
        if (event.key === "Escape" && document.querySelector(".aiwm-accessibility-panel")) {
            event.preventDefault();
            close(true);
        }
        if ((event.ctrlKey || event.metaKey) && event.altKey && event.key.toLowerCase() === "a") {
            event.preventDefault();
            togglePanel();
        }
    });

    apply();
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mount);
    else mount();

    return {
        apply,
        open: togglePanel,
        reset: () => {
            state.font = "normal";
            state.density = "default";
            state.contrast = false;
            state.motion = false;
            save();
        }
    };
})();
