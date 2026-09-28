/**
 * TYPO3 13 Scheduler form of the AQG monitoring task: the language select offers the languages of the
 * selected site only. The server renders one group per site; this keeps the selected site's group, and
 * starts on that site's default language when the site changes. The server validates the pair on save.
 */
export function initializeMonitoringTaskForm(root = document) {
  const site = root.querySelector('[data-aqg-monitoring-site]');
  const language = root.querySelector('[data-aqg-monitoring-language]');
  if (!(site instanceof HTMLSelectElement) || !(language instanceof HTMLSelectElement) || language.dataset.aqgInitialized === '1') {
    return;
  }
  language.dataset.aqgInitialized = '1';

  const optionsBySite = new Map();
  for (const option of Array.from(language.querySelectorAll('option[data-site]'))) {
    const list = optionsBySite.get(option.dataset.site) ?? [];
    list.push({
      value: option.value,
      label: option.textContent ?? '',
      isDefault: option.dataset.default === '1',
      selected: option.selected,
    });
    optionsBySite.set(option.dataset.site, list);
  }

  const render = (keepSelection) => {
    const choices = optionsBySite.get(site.value) ?? [];
    const previous = keepSelection ? choices.find((choice) => choice.selected) : undefined;
    const selected = previous ?? choices.find((choice) => choice.isDefault) ?? choices[0];

    language.replaceChildren();
    if (choices.length === 0) {
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = language.dataset.placeholder ?? '';
      language.append(placeholder);
      language.disabled = true;
      return;
    }

    language.disabled = false;
    for (const choice of choices) {
      const option = document.createElement('option');
      option.value = choice.value;
      option.textContent = choice.label;
      option.selected = choice === selected;
      language.append(option);
    }
  };

  site.addEventListener('change', () => render(false));
  render(true);
}

initializeMonitoringTaskForm();
