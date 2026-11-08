/**
 * Disposable visual fixture for #1603, importing the actual Laravel React
 * preview/edit controls and production CSS. No API calls or WordPress writes.
 * The real editor has the same content > workspace-stack > fieldset structure.
 */
import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
    ContentEditorPreviewSurface,
    ContentEditorShowEditControl,
    ContentEditorShowPreviewControl,
    showContentEditorEdit,
    showContentEditorPreview,
    type ContentEditorViewMode,
} from '../../resources/js/content-editor-save-control';
import { LocaleProvider, useLocale } from '../../resources/js/i18n';
import '../../resources/css/app.css';

const longWord = 'ABCDEFGHIJKL'.repeat(60);
const sample = `
<h1>Real HTML draft preview — عنوان المعاينة</h1>
<p>Draft preview must occupy the available content width and wrap text.</p>
<img alt="Wide content fixture" width="2000" height="180" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='2000' height='180'%3E%3Crect width='2000' height='180' fill='%230969da'/%3E%3C/svg%3E">
<table><tbody><tr><td>Very wide table</td><td>${'Wide column '.repeat(30)}</td></tr></tbody></table>
<pre><code>${longWord}</code></pre>
<blockquote><p>Nested HTML block with <strong>inline emphasis</strong>.</p></blockquote>
<script>window.parent.__untrustedScriptRan = true;</script>
<form action="https://example.invalid/collect"><input name="private"></form>
`;

function GeometryFixture() {
    const { locale, setLocale } = useLocale();
    const [viewMode, setViewMode] = useState<ContentEditorViewMode>('preview');
    const [draft, setDraft] = useState(sample);

    return (
        <div className="app-shell" style={{ gridTemplateColumns: 'minmax(0, 1fr)' }}>
            <main className="main-area">
                <header className="topbar">
                    <div className="topbar-title"><h1>{locale === 'ar' ? 'محرر المحتوى' : 'Content editor'}</h1></div>
                    <label>{locale === 'ar' ? 'اللغة' : 'Language'}
                        <select aria-label="Language" value={locale} onChange={e => setLocale(e.target.value as 'ar' | 'en')}>
                            <option value="en">English</option>
                            <option value="ar">العربية</option>
                        </select>
                    </label>
                </header>
                <div className="content">
                    <form className="workspace-stack" onSubmit={event => event.preventDefault()}>
                        <section className="hero-panel">
                            <div><span className="workspace-kicker">CONTENT EDITOR</span>
                                <h2>{locale === 'ar' ? 'مسودة تجريبية غير منشورة' : 'Disposable unsaved draft'}</h2>
                            </div>
                        </section>
                        <fieldset className="panel form-stack">
                            <label><span>{locale === 'ar' ? 'العنوان' : 'Title'}</span>
                                <input data-testid="title-field" defaultValue="Geometry-only fixture" />
                            </label>
                            <label><span>Slug</span><input defaultValue="preview-geometry-test" /></label>
                            <section className="form-stack content-editor-content-card">
                                <div className="editor-card-head">
                                    <div>
                                        <h3>{locale === 'ar' ? 'المحتوى HTML' : 'Content HTML'}</h3>
                                        <p>{locale === 'ar' ? 'معاينة المسودة الحالية.' : 'Preview the current draft.'}</p>
                                    </div>
                                    <div className="editor-view-toggle">
                                        <ContentEditorShowEditControl viewMode={viewMode} onShowEdit={() => setViewMode(showContentEditorEdit())} />
                                        <ContentEditorShowPreviewControl viewMode={viewMode} onShowPreview={() => setViewMode(showContentEditorPreview())} />
                                    </div>
                                </div>
                                {viewMode === 'edit' ? (
                                    <label className="content-editor-html-input">
                                        <span className="sr-only">HTML</span>
                                        <textarea data-testid="html-editor" rows={14} value={draft} onChange={event => setDraft(event.target.value)} />
                                    </label>
                                ) : <ContentEditorPreviewSurface content={draft} />}
                            </section>
                            <label><span>{locale === 'ar' ? 'الملخص' : 'Excerpt'}</span><textarea rows={3} defaultValue="Preview-only fixture" /></label>
                        </fieldset>
                    </form>
                </div>
            </main>
        </div>
    );
}

createRoot(document.getElementById('root')!).render(<LocaleProvider><GeometryFixture /></LocaleProvider>);
