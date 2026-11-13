// @vitest-environment jsdom
import {afterEach, describe, expect, it, vi} from 'vitest';
import MediaAiContextMenuActions from '@priebera/a11y-quality-gate/media-ai/context-menu-actions.js';

afterEach(() => {
    delete window.TYPO3;
    delete window.list_frame;
    vi.restoreAllMocks();
});

describe('Context menu "Suggest metadata with AI"', () => {
    it('opens TYPO3\'s metadata form of the file with the way back, and sends nothing', () => {
        const setUrl = vi.fn();
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        window.TYPO3 = {Backend: {ContentContainer: {setUrl}}};
        window.list_frame = {document: {location: {pathname: '/typo3/module/file/list', search: '?id=1:/images/'}}};

        MediaAiContextMenuActions.openMetadataForm('sys_file', '1:/images/chart.png', {editUrl: '/typo3/record/edit?token=t&edit%5Bsys_file_metadata%5D%5B50%5D=edit', fileUid: '5'});

        expect(setUrl).toHaveBeenCalledWith('/typo3/record/edit?token=t&edit%5Bsys_file_metadata%5D%5B50%5D=edit&returnUrl=%2Ftypo3%2Fmodule%2Ffile%2Flist%3Fid%3D1%3A%2Fimages%2F');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('does nothing without a backend form URL', () => {
        const setUrl = vi.fn();
        window.TYPO3 = {Backend: {ContentContainer: {setUrl}}};

        MediaAiContextMenuActions.openMetadataForm('sys_file', 'x', {editUrl: ''});
        MediaAiContextMenuActions.openMetadataForm('sys_file', 'x', {editUrl: 'javascript:alert(1)'});
        MediaAiContextMenuActions.openMetadataForm('sys_file', 'x', {});

        expect(setUrl).not.toHaveBeenCalled();
    });

    it('has no dialog or suggest-and-save callback any more', () => {
        expect(typeof MediaAiContextMenuActions.suggestMetadata).toBe('undefined');
    });
});
