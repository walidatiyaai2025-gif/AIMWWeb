import React, { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useLocation } from 'react-router-dom';
import { apiRequest, isPathMatch, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const APPLICATION_USER_PASSWORD_RESET_OPERATION_ID = 'AIMW-BILL-3BE40F2E00';

type Member = {
    id: number;
    status: string;
    user: { id: number; name: string; email: string } | null;
};

type MembersResponse = { data: Member[] };
type ResetResponse = {
    membership_id: number;
    user: { id: number; name: string; email: string };
    password_changed: true;
    revoked_sessions: number;
};

export function ApplicationUserPasswordResetControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const location = useLocation();
    const canManage = context.permissions.includes('members.manage');
    const onApplicationUsers = isPathMatch('/admin/users', location.pathname, context.tenant.slug);
    const membersEndpoint = context.api['application-users'];
    const [membershipId, setMembershipId] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [confirmationIdentity, setConfirmationIdentity] = useState('');
    const [success, setSuccess] = useState('');

    const membersQuery = useQuery({
        queryKey: ['application-users-password-reset', context.tenant.slug],
        queryFn: () => apiRequest<MembersResponse>(membersEndpoint),
        enabled: onApplicationUsers && canManage && Boolean(membersEndpoint),
        retry: false,
    });
    const members = membersQuery.data?.data ?? [];
    const selected = useMemo(
        () => members.find((member) => String(member.id) === membershipId) ?? null,
        [members, membershipId],
    );

    const resetMutation = useMutation({
        retry: false,
        mutationFn: async () => {
            if (! selected?.user) throw new Error('Select an application user before resetting a password.');
            const endpoint = `${membersEndpoint}/${encodeURIComponent(String(selected.id))}/reset-password`;
            const result = await apiRequest<ResetResponse>(endpoint, {
                method: 'POST',
                body: JSON.stringify({
                    password,
                    password_confirmation: passwordConfirmation,
                    confirmation_identity: confirmationIdentity,
                }),
            });
            const reread = await apiRequest<MembersResponse>(membersEndpoint);
            const authoritativeMember = reread.data.find((member) => member.id === result.membership_id);
            if (! authoritativeMember?.user || authoritativeMember.user.id !== result.user.id) {
                throw new Error('The password reset completed but the authoritative member reread did not match the target.');
            }
            return result;
        },
        onSuccess: (result) => {
            setSuccess(locale === 'ar'
                ? `تمت إعادة تعيين كلمة المرور وإلغاء ${result.revoked_sessions} جلسة للمستخدم ${result.user.email}.`
                : `Password reset persisted for ${result.user.email}; ${result.revoked_sessions} session(s) were revoked.`);
            void membersQuery.refetch();
        },
        onMutate: () => setSuccess(''),
        onSettled: () => {
            setPassword('');
            setPasswordConfirmation('');
            setConfirmationIdentity('');
        },
    });

    if (! onApplicationUsers || ! canManage || ! membersEndpoint) return null;

    const error = resetMutation.error instanceof Error ? resetMutation.error.message : '';

    return (
        <section className="hero-panel" data-canonical-operation={APPLICATION_USER_PASSWORD_RESET_OPERATION_ID} aria-labelledby="application-user-password-reset-title">
            <div>
                <span className="workspace-kicker">SECURITY</span>
                <h2 id="application-user-password-reset-title">{locale === 'ar' ? 'إعادة تعيين كلمة مرور مستخدم' : 'Reset application user password'}</h2>
                <p>{locale === 'ar'
                    ? 'تتطلب العملية صلاحية إدارة الأعضاء وتأكيد بريد المستخدم. يتم إلغاء جلساته بعد نجاح الحفظ.'
                    : 'This requires member-management permission and the target email confirmation. Existing target sessions are revoked after persistence.'}</p>
            </div>
            <form onSubmit={(event) => {
                event.preventDefault();
                if (! resetMutation.isPending) resetMutation.mutate();
            }}>
                <label>
                    {locale === 'ar' ? 'المستخدم' : 'User'}
                    <select value={membershipId} onChange={(event) => {
                        setMembershipId(event.target.value);
                        setConfirmationIdentity('');
                        setSuccess('');
                    }} disabled={resetMutation.isPending || membersQuery.isLoading} required>
                        <option value="">{locale === 'ar' ? 'اختر مستخدمًا' : 'Select a user'}</option>
                        {members.filter((member) => member.user).map((member) => (
                            <option key={member.id} value={member.id}>{member.user?.name} — {member.user?.email}</option>
                        ))}
                    </select>
                </label>
                <label>
                    {locale === 'ar' ? 'كلمة المرور الجديدة' : 'New password'}
                    <input type="password" autoComplete="new-password" minLength={8} value={password} onChange={(event) => setPassword(event.target.value)} disabled={resetMutation.isPending} required />
                </label>
                <label>
                    {locale === 'ar' ? 'تأكيد كلمة المرور' : 'Confirm password'}
                    <input type="password" autoComplete="new-password" minLength={8} value={passwordConfirmation} onChange={(event) => setPasswordConfirmation(event.target.value)} disabled={resetMutation.isPending} required />
                </label>
                <label>
                    {locale === 'ar' ? 'اكتب بريد المستخدم للتأكيد' : 'Type the target email to confirm'}
                    <input type="email" autoComplete="off" value={confirmationIdentity} onChange={(event) => setConfirmationIdentity(event.target.value)} disabled={resetMutation.isPending} placeholder={selected?.user?.email ?? ''} required />
                </label>
                <button type="submit" className="btn" disabled={resetMutation.isPending || ! selected?.user}>
                    {resetMutation.isPending ? (locale === 'ar' ? 'جارٍ التنفيذ…' : 'Resetting…') : (locale === 'ar' ? 'إعادة تعيين كلمة المرور' : 'Reset password')}
                </button>
                {error ? <p role="alert">{error}</p> : null}
                {success ? <p role="status">{success}</p> : null}
            </form>
        </section>
    );
}
