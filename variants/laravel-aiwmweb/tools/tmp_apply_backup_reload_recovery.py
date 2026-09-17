from pathlib import Path

pages_path = Path("variants/laravel-aiwmweb/backend/resources/js/pages.tsx")
pages = pages_path.read_text(encoding="utf-8")
old_pages_import = "import { ApplicationUsersClearSearchControl } from './application-users-clear-search-control';\n"
if old_pages_import not in pages or "runAuthoritativeBackupReload" in pages:
    raise SystemExit("pages import precondition failed")
pages = pages.replace(old_pages_import, old_pages_import + "import { runAuthoritativeBackupReload } from './backup-reload-control';\n", 1)

anchor = "    const mutation = useMutation({\n"
refresh_fn = """    const refreshWorkspace = async (): Promise<void> => {
        if (route.key !== 'backups') {
            await query.refetch();
            return;
        }

        await runAuthoritativeBackupReload({
            busy: query.isFetching,
            refetch: () => query.refetch(),
            onSuccess: () => notify(
                locale === 'ar'
                    ? 'تمت إعادة تحميل النسخ الاحتياطية من الحالة الموثوقة على الخادم.'
                    : 'Backups were reloaded from authoritative server state.',
                'success',
            ),
            onFailure: (error) => notify(
                error instanceof Error
                    ? error.message
                    : (locale === 'ar' ? 'تعذر إعادة تحميل النسخ الاحتياطية من الخادم.' : 'Backups could not be reloaded from the server.'),
                'error',
            ),
        });
    };

"""
if anchor not in pages:
    raise SystemExit("pages mutation anchor not found")
pages = pages.replace(anchor, refresh_fn + anchor, 1)

old_button = """                        disabled={route.key === 'ai-center' && query.isFetching}
                        aria-busy={route.key === 'ai-center' && query.isFetching ? 'true' : 'false'}
                        onClick={() => void query.refetch()}
                    >
                        {route.key === 'ai-center'
                            ? (query.isFetching
                                ? (locale === 'ar' ? 'جارٍ تحديث البيانات…' : 'Refreshing data…')
                                : (locale === 'ar' ? 'تحديث البيانات' : 'Refresh data'))
                            : text(commonText.refresh)}
"""
new_button = """                        disabled={(route.key === 'ai-center' || route.key === 'backups') && query.isFetching}
                        aria-busy={(route.key === 'ai-center' || route.key === 'backups') && query.isFetching ? 'true' : 'false'}
                        onClick={() => void refreshWorkspace()}
                    >
                        {route.key === 'ai-center'
                            ? (query.isFetching
                                ? (locale === 'ar' ? 'جارٍ تحديث البيانات…' : 'Refreshing data…')
                                : (locale === 'ar' ? 'تحديث البيانات' : 'Refresh data'))
                            : route.key === 'backups'
                                ? (query.isFetching
                                    ? (locale === 'ar' ? 'جارٍ إعادة تحميل النسخ…' : 'Reloading backups…')
                                    : (locale === 'ar' ? 'إعادة تحميل النسخ' : 'Reload backups'))
                                : text(commonText.refresh)}
"""
if old_button not in pages:
    raise SystemExit("pages refresh button precondition failed")
pages_path.write_text(pages.replace(old_button, new_button, 1), encoding="utf-8")
