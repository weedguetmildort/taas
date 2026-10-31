/* Repeatable degrees: display, validation and payload collection. */
(() => {
  'use strict';
  const student = window.TAASStudent = window.TAASStudent || {};
  const degreeTypes = { AA: 1, BS: 2, MS: 3, PHD: 4 };

  student.createAcademicBackground = ({ host, template, addButton }) => {
    function numberDegrees() {
      const entries = [...host.querySelectorAll('.degree-entry')];
      entries.forEach((entry, index) => {
        entry.querySelector('legend').textContent = `${host.dataset.entryLabel || 'Degree'} ${index + 1}`;
        const remove = entry.querySelector('[data-action="remove-degree"]');
        remove.disabled = entries.length === 1;
        remove.setAttribute('aria-label', `${remove.textContent.trim()} ${index + 1}`);
      });
    }

    function addDegree(data = {}) {
      const entry = template.content.firstElementChild.cloneNode(true);
      ['university', 'major', 'minor'].forEach(key => {
        const input = entry.querySelector(`[data-field="${key}"]`);
        if (input) input.value = data[key] || '';
      });
      // Keep a saved minor even though there is no separate minor control yet.
      entry.dataset.minor = data.minor || '';
      const degree = entry.querySelector('[data-field="degree_type"]');
      degree.value = ({ 1: 'AA', 2: 'BS', 3: 'MS', 4: 'PhD' })[data.degree_type] || '';
      degree.addEventListener('input', () => degree.setCustomValidity(''));
      if (data.grad_date) {
        const [year, month] = data.grad_date.split('-');
        entry.querySelector('[data-field="grad_date"]').value = `${month}/${year}`;
      }
      const gpa = entry.querySelector('[data-field="gpa"]');
      const unavailable = entry.querySelector('[data-field="gpa_unavailable"]');
      gpa.value = data.gpa ?? '';
      unavailable.checked = Object.hasOwn(data, 'gpa') && data.gpa === null;
      const updateGpa = () => { gpa.disabled = unavailable.checked; gpa.required = !unavailable.checked; };
      unavailable.addEventListener('change', updateGpa);
      updateGpa();
      entry.querySelector('[data-action="remove-degree"]').addEventListener('click', () => {
        if (host.children.length > 1) { entry.remove(); numberDegrees(); }
      });
      host.append(entry);
      numberDegrees();
      return entry;
    }


    function validate() {
      host.querySelectorAll('[data-field="degree_type"]').forEach(input => {
        input.setCustomValidity(degreeTypes[input.value.trim().toUpperCase()] ? '' : 'Use a supported degree type: AA, BS, MS, or PhD.');
      });
    }

    function collect() {
      return [...host.querySelectorAll('.degree-entry')].map(entry => {
        const get = key => entry.querySelector(`[data-field="${key}"]`);
        const [month, year] = get('grad_date').value.split('/');
        return {
          university: get('university').value.trim(), major: get('major').value.trim(), minor: entry.dataset.minor,
          degree_type: degreeTypes[get('degree_type').value.trim().toUpperCase()], grad_date: `${year}-${month}-01`,
          gpa: get('gpa_unavailable').checked ? null : Number(get('gpa').value)
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
