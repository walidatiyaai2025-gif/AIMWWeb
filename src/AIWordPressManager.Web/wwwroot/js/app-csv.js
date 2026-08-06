window.appCsv = {
    download: function (fileName, headers, rows) {
        const escapeCell = value => {
            const text = value == null ? "" : String(value);
            return `"${text.replaceAll('"', '""')}"`;
        };

        const lines = [];
        if (Array.isArray(headers) && headers.length > 0) {
            lines.push(headers.map(escapeCell).join(','));
        }
        for (const row of rows || []) {
            lines.push((row || []).map(escapeCell).join(','));
        }

        const blob = new Blob(["\uFEFF" + lines.join("\r\n")], { type: "text/csv;charset=utf-8" });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = fileName || "export.csv";
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }
};
