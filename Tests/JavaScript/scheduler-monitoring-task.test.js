// @vitest-environment jsdom

import {describe, expect, it} from 'vitest';
import {initializeMonitoringTaskForm} from '../../Resources/Public/JavaScript/backend/scheduler-monitoring-task.js';

// The markup MonitoringTaskAdditionalFieldProvider renders on TYPO3 13: one language group per site.
const render = (selectedSite, selectedLanguage) => {
    const container = document.createElement('div');
    const option = (site, value, label, isDefault) =>
        `<option value="${value}" data-site="${site}"${isDefault ? ' data-default="1"' : ''}${site === selectedSite && value === selectedLanguage ? ' selected="selected"' : ''}>${label}</option>`;
    container.innerHTML = `
        <select id="aqg_monitor_site" data-aqg-monitoring-site>
            <option value=""${selectedSite === '' ? ' selected="selected"' : ''}>Choose a site</option>
            <option value="main"${selectedSite === 'main' ? ' selected="selected"' : ''}>Example (main), root page 1</option>
            <option value="shop"${selectedSite === 'shop' ? ' selected="selected"' : ''}>Shop (shop), root page 20</option>
        </select>
        <select id="aqg_monitor_language" data-aqg-monitoring-language data-placeholder="Choose a site first">
            <optgroup label="Example" data-site="main">
                ${option('main', '0', 'English (en-US) – default language', true)}
                ${option('main', '2', 'Deutsch (de-DE)', false)}
            </optgroup>
            <optgroup label="Shop" data-site="shop">
                ${option('shop', '0', 'English (en-GB) – default language', true)}
            </optgroup>
        </select>`;
    initializeMonitoringTaskForm(container);
    return {
        site: container.querySelector('#aqg_monitor_site'),
        language: container.querySelector('#aqg_monitor_language'),
    };
};
const offered = (select) => Array.from(select.options).map((option) => `${option.value}:${option.textContent}`);
const choose = (select, value) => {
    select.value = value;
    select.dispatchEvent(new Event('change'));
};

describe('Monitoring task form (TYPO3 13)', () => {
    it('offers only the languages of the selected site and keeps the stored language', () => {
        const {language} = render('main', '2');

        expect(offered(language)).toEqual(['0:English (en-US) – default language', '2:Deutsch (de-DE)']);
        expect(language.value).toBe('2');
        expect(language.disabled).toBe(false);
    });

    it('switches to the default language of a newly chosen site', () => {
        const {site, language} = render('main', '2');

        choose(site, 'shop');

        expect(offered(language)).toEqual(['0:English (en-GB) – default language']);
        expect(language.value).toBe('0');
    });

    it('asks for a site before offering languages', () => {
        const {site, language} = render('', '0');

        expect(offered(language)).toEqual([':Choose a site first']);
        expect(language.disabled).toBe(true);

        choose(site, 'main');
        expect(language.value).toBe('0');
        expect(language.disabled).toBe(false);
    });
});
