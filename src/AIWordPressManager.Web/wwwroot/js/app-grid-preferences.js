window.appGridPreferences = {
    load: function (key) {
        if (!key) return [];
        try {
            const value = window.localStorage.getItem("aimw:grid:" + key);
            if (!value) return [];
            const parsed = JSON.parse(value);
            return Array.isArray(parsed) ? parsed.filter(Number.isInteger) : [];
        } catch {
            return [];
        }
    },
    save: function (key, hiddenColumns) {
        if (!key) return;
        try {
            window.localStorage.setItem("aimw:grid:" + key, JSON.stringify(hiddenColumns || []));
        } catch {
            // Storage may be unavailable in private browsing or restricted environments.
        }
    }
};
