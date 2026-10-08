import { describe, expect, it, vi } from 'vitest';
import {
    CONTENT_PLANNER_RELOAD_OPERATION_ID,
    CONTENT_PLANNER_RELOAD_SOURCE_OPERATION_KEY,
    reloadContentPlanner,
} from '../content-planner-reload-control';

describe('Content Planner ReloadAsync parity', () => {
    it('binds the exact canonical operation', () => {
        expect(CONTENT_PLANNER_RELOAD_OPERATION_ID).toBe('AIMW-BILL-8BE4A39748');
        expect(CONTENT_PLANNER_RELOAD_SOURCE_OPERATION_KEY).toBe(
            'visible:src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor:/content-planner:ReloadAsync:ReloadAsync',
        );
    });

    it('performs one authoritative browser reread without mutation', () => {
        const reload = vi.fn();
        reloadContentPlanner(reload);
        expect(reload).toHaveBeenCalledTimes(1);
    });
});
