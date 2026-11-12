import {describe, expect, it} from 'vitest';
import {EditorAiSession} from '../../Resources/Public/JavaScript/editor-ai/editor-ai-session.js';

// The quiet line under a finding when editor AI is not ready (CKEditor and plain HTML share it): budget_reached has
// its own copy (UX Atlas 02 · E), paused says what the administrator can do. Nobody is claimed to have been notified.
describe('editor AI quiet line', () => {
    it('distinguishes the paused reasons for editors and administrators', () => {
        expect(EditorAiSession.quietLine({state: 'paused', pausedReason: 'not_verified'})[0]).toBe('aiPausedEditor');
        expect(EditorAiSession.quietLine({state: 'paused', pausedReason: 'acknowledgement'})[0]).toBe('aiPausedAckEditor');
        expect(EditorAiSession.quietLine({state: 'paused', pausedReason: 'budget_unenforceable'})[0]).toBe('aiPausedBudgetEditor');
        // Prices are automatic (AQG 3.0): nobody is told to enter one.
        for (const isAdmin of [false, true]) {
            expect(EditorAiSession.quietLine({state: 'paused', pausedReason: 'budget_unenforceable', isAdmin})[1]).not.toMatch(/enter(ing)? a price/i);
        }
        expect(EditorAiSession.quietLine({state: 'paused', pausedReason: 'acknowledgement', isAdmin: true})[0]).toBe('aiPausedAckAdmin');
        expect(EditorAiSession.quietLine({state: 'paused', isAdmin: true})[0]).toBe('aiErrorPausedAdmin');
    });

    it('has a budget line and nothing for a ready site', () => {
        const [key, text] = EditorAiSession.quietLine({state: 'budget_reached'});
        expect(key).toBe('aiBudgetReachedEditor');
        expect(text).toMatch(/^Monthly budget reached\./);
        expect(EditorAiSession.quietLine({state: 'budget_reached', isAdmin: true})[0]).toBe('aiBudgetReachedAdmin');
        expect(EditorAiSession.quietLine({state: 'ready'})).toBeNull();
        for (const state of ['paused', 'budget_reached', 'not_configured']) {
            for (const isAdmin of [true, false]) {
                for (const pausedReason of ['', 'acknowledgement', 'budget_unenforceable']) {
                    expect(EditorAiSession.quietLine({state, isAdmin, pausedReason})[1]).not.toMatch(/notified/);
                }
            }
        }
    });
});
