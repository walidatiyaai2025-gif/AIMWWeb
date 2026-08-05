window.aiwmCommandPalette = (() => {
    let registered = false;

    function register() {
        if (registered) return;
        registered = true;
        document.addEventListener("keydown", event => {
            const isShortcut = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k";
            if (!isShortcut) return;
            event.preventDefault();
            document.getElementById("command-palette-trigger")?.click();
        });
    }

    register();
    return { register };
})();
