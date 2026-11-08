{{-- Shared responsive dashboard layout for guarded, read-only Laravel Blade workspaces. --}}
<style data-aiwm-workspace-read-shell>
    body { margin: 0; background: #0b0f17; color: #f4f6fa; }
    .workspace-read-shell {
        --bg: #0b0f17; --surface: #151923; --surface2: #1b2130;
        --border: #30394c; --text: #f4f6fa; --muted: #a7b2c1;
        --accent: #c5a45d; --accent-soft: rgba(197,164,93,.16);
        display: grid; grid-template-columns: minmax(220px, 284px) minmax(0, 1fr);
        min-height: 100vh; background: var(--bg); color: var(--text);
    }
    .workspace-read-shell, .workspace-read-shell * { box-sizing: border-box; }
    .workspace-read-shell a { color: #e0bf73; }
    .workspace-read-shell .sidebar {
        display: flex; flex-direction: column; gap: 18px; position: sticky;
        top: 0; height: 100vh; padding: 18px; overflow-y: auto;
        background: var(--surface); border-inline-end: 1px solid var(--border);
    }
    .workspace-read-shell .brand { display: flex; align-items: center; gap: 12px; color: var(--text); text-decoration: none; }
    .workspace-read-shell .brand-mark { background: var(--accent); color: #1b1710; border-radius: 10px; padding: 11px; font-weight: 800; }
    .workspace-read-shell .brand-copy { display: grid; min-width: 0; gap: 3px; }
    .workspace-read-shell .brand-copy small { color: var(--muted); overflow-wrap: anywhere; }
    .workspace-read-shell .main-nav { display: grid; gap: 6px; }
    .workspace-read-shell .main-nav a { display: block; padding: 12px; border-radius: 10px; min-height: 44px; color: var(--text); text-decoration: none; }
    .workspace-read-shell .main-nav a:hover, .workspace-read-shell .main-nav a.active { background: var(--accent-soft); color: #e0bf73; }
    .workspace-read-shell .sidebar-footer { margin-top: auto; border-top: 1px solid var(--border); padding-top: 16px; color: var(--muted); overflow-wrap: anywhere; }
    .workspace-read-shell .main-area { min-width: 0; }
    .workspace-read-shell .topbar {
        display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
        padding: 16px 24px; border-bottom: 1px solid var(--border); background: var(--bg);
    }
    .workspace-read-shell .topbar h1 { font-size: clamp(19px, 2vw, 25px); margin: 6px 0 0; }
    .workspace-read-shell .breadcrumb { display: flex; flex-wrap: wrap; gap: 8px; color: var(--muted); font-size: 12px; }
    .workspace-read-shell .workspace-read-content { max-width: 1180px; margin: 0 auto; padding: 24px; min-width: 0; }
    .workspace-read-shell .workspace-read-content > header,
    .workspace-read-shell .workspace-read-content > section {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: 16px; padding: 20px; margin-bottom: 16px;
    }
    .workspace-read-shell .workspace-read-content .panel,
    .workspace-read-shell .workspace-read-content .row { background: var(--surface2); border-color: var(--border); }
    .workspace-read-shell .workspace-read-content :is(p, small) { line-height: 1.6; }
    .workspace-read-shell .workspace-read-content :is(h1, h2, p, a, strong, span, small) { overflow-wrap: anywhere; }
    .workspace-read-shell .workspace-read-content .hero { align-items: flex-start; }
    .workspace-read-shell :focus-visible { outline: 3px solid #e0bf73; outline-offset: 3px; }
    @media (max-width: 760px) {
        .workspace-read-shell { grid-template-columns: minmax(0, 1fr); }
        .workspace-read-shell .sidebar { position: static; height: auto; overflow: visible; padding: 12px; }
        .workspace-read-shell .main-nav { grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)); }
        .workspace-read-shell .sidebar-footer { display: none; }
        .workspace-read-shell .topbar { padding: 12px 16px; }
        .workspace-read-shell .workspace-read-content { padding: 12px; }
        .workspace-read-shell .workspace-read-content > header,
        .workspace-read-shell .workspace-read-content > section { padding: 16px; }
    }
</style>
