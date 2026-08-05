window.aiwmNotifications = (() => {
    async function get(take = 12) {
        const response = await fetch(`/api/notifications?unreadOnly=false&take=${encodeURIComponent(take)}`, {
            credentials: "same-origin",
            headers: { "Accept": "application/json" }
        });
        if (!response.ok) throw new Error(`Notification request failed (${response.status}).`);
        const items = await response.json();
        return Array.isArray(items) ? items : [];
    }

    async function markRead(id) {
        const response = await fetch(`/api/notifications/${encodeURIComponent(id)}/read`, {
            method: "POST",
            credentials: "same-origin",
            headers: { "RequestVerificationToken": "" }
        });
        if (!response.ok && response.status !== 204) {
            throw new Error(`Mark-read request failed (${response.status}).`);
        }
        return true;
    }

    return { get, markRead };
})();
