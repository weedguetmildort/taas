/* Repeatable degrees: display, validation and payload collection. */
(() => {
  'use strict';
  const student = window.TAASStudent = window.TAASStudent || {};
  const DEGREE_TYPES = { AA: 1, BS: 2, MS: 3, PhD: 4 };
  const degreeTypeId = value => {
    const label = Object.keys(DEGREE_TYPES).find(key => key.toUpperCase() === value.trim().toUpperCase());
    return DEGREE_TYPES[label];
  };

  function validateTemplate(template) {
    const entry = template?.content?.firstElementChild;
    if (!entry?.matches('.degree-entry') || template.content.children.length !== 1) {
      throw new Error('Degree template needs one degree-entry root.');
    }
    const required = {
      university: 'input[required], select[required], textarea[required]',
      major: 'input[required], select[required], textarea[required]',
      degree_type: 'input[required], select[required]',
      grad_date: 'input[required]',
      gpa: 'input[type="number"]',
      gpa_unavailable: 'input[type="checkbox"]'
    };
    for (const [name, selector] of Object.entries(required)) {
      const controls = entry.querySelectorAll(`[data-field="${name}"]`);
      if (controls.length !== 1 || !controls[0].matches(selector)) {
        throw new Error(`Degree template needs exactly one valid ${name} control.`);
      }
    }
    const minors = entry.querySelectorAll('[data-field="minor"]');
    if (minors.length > 1 || (minors.length && !minors[0].matches('input, select, textarea'))) {
      throw new Error('Degree template has an invalid or duplicated minor control.');
    }
    if (entry.querySelectorAll('legend').length !== 1
      || entry.querySelectorAll('[data-action="remove-degree"]').length !== 1
      || !entry.querySelector('[data-action="remove-degree"]').matches('button[type="button"]')) {
      throw new Error('Degree template needs one legend and one remove button.');
    }
  }

  student.createAcademicBackground = ({ host, template, addButton }) => {
    validateTemplate(template);
    const getEntries = () => [...host.querySelectorAll('.degree-entry')];
    const field = (entry, name) => entry.querySelector(`[data-field="${name}"]`);

    function updateEntryLabels() {
      const entries = getEntries();
      entries.forEach((entry, index) => {
        entry.querySelector('legend').textContent = `${host.dataset.entryLabel || 'Degree'} ${index + 1}`;
        const remove = entry.querySelector('[data-action="remove-degree"]');
        remove.disabled = entries.length === 1;
        remove.setAttribute('aria-label', `${remove.textContent.trim()} ${index + 1}`);
      });
    }

    function updateGpaAvailability(entry) {
      const unavailable = field(entry, 'gpa_unavailable').checked;
      const gpa = field(entry, 'gpa');
      gpa.disabled = unavailable;
      gpa.required = !unavailable;
    }

    function prefillEntry(entry, data) {
      for (const name of ['university', 'major', 'minor']) {
        const input = field(entry, name);
        if (input) input.value = data[name] || '';
      }
      // Preserve a saved minor when the template has no separate minor control.
      entry.dataset.minor = data.minor || '';
      field(entry, 'degree_type').value = Object.keys(DEGREE_TYPES)
        .find(label => DEGREE_TYPES[label] === data.degree_type) || '';
      if (data.grad_date) {
        const [year, month] = data.grad_date.split('-');
        field(entry, 'grad_date').value = `${month}/${year}`;
      }
      field(entry, 'gpa').value = data.gpa ?? '';
      field(entry, 'gpa_unavailable').checked = Object.hasOwn(data, 'gpa') && data.gpa === null;
      updateGpaAvailability(entry);
    }

    function addDegree(data = {}) {
      const entry = template.content.firstElementChild.cloneNode(true);
      prefillEntry(entry, data);
      const degree = field(entry, 'degree_type');
      degree.addEventListener('input', () => degree.setCustomValidity(''));
      const graduationDate = field(entry, 'grad_date');
      graduationDate.addEventListener('input', () => graduationDate.setCustomValidity(''));
      field(entry, 'gpa_unavailable').addEventListener('change', () => updateGpaAvailability(entry));
      entry.querySelector('[data-action="remove-degree"]').addEventListener('click', () => {
        if (getEntries().length <= 1) return;
        entry.remove();
        updateEntryLabels();
      });
      host.append(entry);
      updateEntryLabels();
      return entry;
    }

    function validate() {
      host.querySelectorAll('[data-field="degree_type"]').forEach(input => {
        input.setCustomValidity(degreeTypeId(input.value) ? '' : 'Use a supported degree type: AA, BS, MS, or PhD.');
      });
      host.querySelectorAll('[data-field="grad_date"]').forEach(input => {
        const valid = /^(0[1-9]|1[0-2])\/[1-9][0-9]{3}$/.test(input.value);
        input.setCustomValidity(valid ? '' : 'Enter a graduation date in MM/YYYY format with a year from 1000 to 9999.');
      });
    }

    function collect() {
      return getEntries().map(entry => {
        const value = name => field(entry, name).value.trim();
        const [month, year] = value('grad_date').split('/');
        return {
          university: value('university'),
          major: value('major'),
          minor: field(entry, 'minor') ? value('minor') : entry.dataset.minor,
          degree_type: degreeTypeId(value('degree_type')),
          grad_date: `${year}-${month}-01`,
          gpa: field(entry, 'gpa_unavailable').checked ? null : Number(value('gpa'))
        };
      });
    }

    addButton.addEventListener('click', () => addDegree().querySelector('input').focus());
    return {
      validate, collect,
      prefill(entries = []) {
        host.replaceChildren();
        (entries.length ? entries : [{}]).forEach(addDegree);
      }
    };
  };
})();
