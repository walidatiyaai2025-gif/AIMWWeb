import React, { useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_PLANNER_NEW_ITEM_OPERATION_ID = 'AIMW-BILL-3ABDE4E48F';
export const CONTENT_PLANNER_NEW_ITEM_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor:/content-planner:NewItem:NewItem';

export type ContentPlannerDraftState = {
    editingId: string | null;
    selectedSiteId: string;
    title: string;
    idea: string;
    scheduledLocal: string;
};

export const emptyContentPlannerDraft = (): ContentPlannerDraftState => ({
    editingId: null,
    selectedSiteId: '',
    title: '',
    idea: '',
    scheduledLocal: '',
});

export function resetContentPlannerDraft(): ContentPlannerDraftState {
    return emptyContentPlannerDraft();
}

function hasPlannerAccess(context: FrontendContext): boolean {
    return context.permissions.includes('*') || context.permissions.includes('content.view');
}

export function ContentPlannerNewItemControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [draft, setDraft] = useState<ContentPlannerDraftState>(emptyContentPlannerDraft);

    if (!hasPlannerAccess(context)) return null;

    const reset = () => setDraft(resetContentPlannerDraft());

    return (
        <section
            className="panel"
            data-canonical-operation={CONTENT_PLANNER_NEW_ITEM_OPERATION_ID}
            data-source-operation-key={CONTENT_PLANNER_NEW_ITEM_SOURCE_OPERATION_KEY}
            aria-label={locale === 'ar' ? 'محرر فكرة جديدة' : 'New content idea editor'}
        >
            <div className="panel-header">
                <div>
                    <span className="workspace-kicker">CONTENT PLANNER</span>
                    <strong>{draft.editingId ? (locale === 'ar' ? 'تعديل العنصر' : 'Edit item') : (locale === 'ar' ? 'إنشاء فكرة' : 'Create idea')}</strong>
                </div>
                <button type="button" className="btn primary" onClick={reset}>
                    ＋ {locale === 'ar' ? 'فكرة جديدة' : 'New idea'}
                </button>
            </div>
            <label>
                <span>{locale === 'ar' ? 'الموقع' : 'Site'}</span>
                <input value={draft.selectedSiteId} onChange={(event) => setDraft({ ...draft, selectedSiteId: event.target.value })} />
            </label>
            <label>
                <span>{locale === 'ar' ? 'العنوان' : 'Title'}</span>
                <input value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} />
            </label>
            <label>
                <span>{locale === 'ar' ? 'الفكرة أو الهدف' : 'Idea or objective'}</span>
                <textarea value={draft.idea} onChange={(event) => setDraft({ ...draft, idea: event.target.value })} />
            </label>
            <label>
                <span>{locale === 'ar' ? 'موعد النشر المقترح' : 'Suggested publish time'}</span>
                <input type="datetime-local" value={draft.scheduledLocal} onChange={(event) => setDraft({ ...draft, scheduledLocal: event.target.value })} />
            </label>
        </section>
    );
}
