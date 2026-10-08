import { describe, expect, it } from 'vitest';
import {
    EMAIL_SCHEDULE_CREATE_OPERATION_ID,
    buildEmailSchedulePayload,
    defaultEmailScheduleDraft,
} from '../email-schedules-create-control';

describe('EmailSchedules CreateClicked parity', () => {
    it('binds the exact canonical operation', () => {
        expect(EMAIL_SCHEDULE_CREATE_OPERATION_ID).toBe('AIMW-BILL-7101AC9489');
    });

    it('does not send caller-owned tenant/user/recipient identity', () => {
        const payload = buildEmailSchedulePayload(defaultEmailScheduleDraft());
        expect(payload.scope).toBe('Account');
        expect(payload.site_id).toBeNull();
        expect(payload).not.toHaveProperty('tenant_id');
        expect(payload).not.toHaveProperty('owner_user_id');
        expect(payload).not.toHaveProperty('recipient');
    });

    it('includes site ownership selector only for site scope', () => {
        const draft = { ...defaultEmailScheduleDraft(), scope: 'Site' as const, site_id: '42', frequency: 'Weekly' as const, weekday: 2 };
        const payload = buildEmailSchedulePayload(draft);
        expect(payload.site_id).toBe(42);
        expect(payload.weekday).toBe(2);
        expect(payload.month_day).toBeNull();
    });
});
