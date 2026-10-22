import { describe, expect, it } from 'vitest';
import {
    CONTENT_PLANNER_NEW_ITEM_OPERATION_ID,
    CONTENT_PLANNER_NEW_ITEM_SOURCE_OPERATION_KEY,
    emptyContentPlannerDraft,
    resetContentPlannerDraft,
} from '../content-planner-new-item-control';

describe('Content Planner NewItem parity', () => {
    it('binds the exact canonical operation', () => {
        expect(CONTENT_PLANNER_NEW_ITEM_OPERATION_ID).toBe('AIMW-BILL-3ABDE4E48F');
        expect(CONTENT_PLANNER_NEW_ITEM_SOURCE_OPERATION_KEY).toBe(
            'visible:src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor:/content-planner:NewItem:NewItem',
        );
    });

    it('resets exactly the source NewItem editor state without mutation', () => {
        expect(resetContentPlannerDraft()).toEqual({
            editingId: null,
            selectedSiteId: '',
            title: '',
            idea: '',
            scheduledLocal: '',
        });
        expect(resetContentPlannerDraft()).toEqual(emptyContentPlannerDraft());
    });

    it('returns a fresh state object on each reset', () => {
        expect(resetContentPlannerDraft()).not.toBe(resetContentPlannerDraft());
    });
});
