import React, { useEffect, useState } from 'react';
import { apiRequest } from './core';
import { useLocale } from './i18n';

type Progress = { id: string; completed: number; total: number; state: string; status_url: string };

export function AiBatchProgress() {
    const { locale } = useLocale();
    const [progress, setProgress] = useState<Progress | null>(null);
    const [error, setError] = useState('');
    useEffect(() => {
        const listener = (event: Event) => setProgress((event as CustomEvent<Progress>).detail);
        window.addEventListener('aimw:ai-progress', listener);
        return () => window.removeEventListener('aimw:ai-progress', listener);
    }, []);
    if (!progress) return null;
    const control = async (action: string) => {
        try {
            const result = await apiRequest<{ data: Progress }>(`${progress.status_url}/${action}`, { method: 'POST' });
            setProgress({ ...result.data, status_url: progress.status_url });
            setError('');
        } catch (reason) { setError(reason instanceof Error ? reason.message : String(reason)); }
    };
    const ar = locale === 'ar';
    return <aside className="panel workspace-stack" aria-live="polite">
        <strong>{ar ? 'مهام الذكاء الاصطناعي المحفوظة' : 'Saved AI tasks'}</strong>
        <p>{progress.completed} / {progress.total} — {progress.state}</p>
        <progress max={progress.total} value={progress.completed} />
        {progress.state !== 'completed' && <div className="button-row">
            <button type="button" onClick={() => void control('pause')}>{ar ? 'إيقاف مؤقت' : 'Pause'}</button>
            <button type="button" onClick={() => void control('resume')}>{ar ? 'استئناف' : 'Resume'}</button>
            <button type="button" onClick={() => void control('cancel')}>{ar ? 'إلغاء' : 'Cancel'}</button>
        </div>}
        {error && <p role="alert">{error}</p>}
    </aside>;
}
