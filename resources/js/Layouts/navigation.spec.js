import { describe, expect, it } from 'vitest';
import { officeMenu } from '@/Layouts/navigation.js';

describe('office menu', () => {
    it('maintenance is a real page, not a later-phase placeholder', () => {
        const item = officeMenu.find((entry) => entry && entry.label === 'nav.maintenance');

        expect(item.route).toBe('office.maintenance.index');
        expect(item.permission).toBe('maintenance.view');
        expect(item.phase2).toBeFalsy();

        const stock = officeMenu.find((entry) => entry && entry.label === 'nav.stock');
        const maintenanceAt = officeMenu.indexOf(item);
        expect(stock.route).toBe('office.stock.index');
        expect(stock.permission).toBe('maintenance.view');
        expect(officeMenu.indexOf(stock)).toBe(maintenanceAt + 1);
    });
});
