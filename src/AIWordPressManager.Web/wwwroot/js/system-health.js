window.aiwmSystemHealth = (() => {
    const startedAt = Date.now();

    async function sample() {
        const begin = performance.now();
        let serviceOnline = false;
        let statusCode = 0;
        let error = null;

        try {
            const response = await fetch('/health/live', {
                method: 'GET',
                cache: 'no-store',
                headers: { 'Accept': 'application/json, text/plain, */*' }
            });
            statusCode = response.status;
            serviceOnline = response.ok;
        } catch (ex) {
            error = ex?.message || String(ex);
        }

        const memory = performance.memory ? {
            usedMb: Math.round(performance.memory.usedJSHeapSize / 1048576),
            totalMb: Math.round(performance.memory.totalJSHeapSize / 1048576),
            limitMb: Math.round(performance.memory.jsHeapSizeLimit / 1048576)
        } : null;

        return {
            checkedAt: new Date().toISOString(),
            browserOnline: navigator.onLine,
            serviceOnline,
            statusCode,
            responseMs: Math.max(0, Math.round(performance.now() - begin)),
            uptimeSeconds: Math.max(0, Math.floor((Date.now() - startedAt) / 1000)),
            memory,
            language: navigator.language || '',
            platform: navigator.platform || '',
            userAgent: navigator.userAgent || '',
            connectionType: navigator.connection?.effectiveType || '',
            downlinkMbps: navigator.connection?.downlink || 0,
            error
        };
    }

    return { sample };
})();