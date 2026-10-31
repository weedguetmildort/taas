/* Course display, saved selections, ranking and completion declarations. */
(() => {
  'use strict';
  const student = window.TAASStudent = window.TAASStudent || {};
  const MAX_COURSES = 5;
  const PENDING = 1;
  const WITHDRAWN = 4;

  student.createCoursePreferences = ({ form, list, rankings, message }) => {
    const templates = new Map(['header', 'row', 'ranking', 'emptyRankings', 'readOnlyEmptyRankings'].map(name => {
      const template = form.querySelector(`template[data-course-template="${name}"]`);
      if (!template?.content.firstElementChild) throw new Error(`Missing course template: ${name}`);
      return [name, template];
    }));
    // Validate the same root that cloneTemplate uses, before any data is loaded.
    for (const [templateName, controlName, selector] of [
      ['row', 'selectedCourses', 'input[type="checkbox"]'],
      ['ranking', 'courseRank', 'select[required]:not([multiple])'],
      ['ranking', 'courseHistory', 'select[required]:not([multiple])']
    ]) {
      const root = templates.get(templateName).content.firstElementChild;
      const controls = root.querySelectorAll(`[name="${controlName}"]`);
      if (controls.length !== 1 || !controls[0].matches(selector)) {
        throw new Error(`Course template ${templateName} needs exactly one ${controlName} control (${selector})`);
      }
    }

    function cloneTemplate(name, course = {}) {
      const element = templates.get(name).content.firstElementChild.cloneNode(true);
      element.querySelectorAll('[data-course-text]').forEach(node => {
        node.textContent = course[node.dataset.courseText] || 'Not provided';
      });
      return element;
    }

    let coursesById = new Map();
    let readOnly = false;
    let missingChoices = false;
    function getSelectedCourseIds() {
      return [...list.querySelectorAll('[name="selectedCourses"]:checked')]
        .map(input => input.value);
    }

    function showCourseMessage(text, error = false) {
      if (!message) return;
      message.textContent = text;
      message.dataset.state = error ? 'error' : '';
    }

    function updateRankAvailability() {
      const selects = [...rankings.querySelectorAll('[name="courseRank"]')];
      selects.forEach(select => {
        const otherRanks = selects
          .filter(other => other !== select)
          .map(other => other.value)
          .filter(Boolean);
        const used = new Set(otherRanks);
        [...select.options].forEach(option => {
          option.disabled = !!option.value && used.has(option.value) && option.value !== select.value;
        });
        select.setCustomValidity(used.has(select.value) ? 'Each course must have a different rank.' : '');
      });
    }

    function readCurrentChoices() {
      const entries = [...rankings.querySelectorAll('[data-course]')];
      return new Map(entries.map(entry => [entry.dataset.course, {
        rank: entry.querySelector('[name="courseRank"]').value,
        history: entry.querySelector('[name="courseHistory"]').value
      }]));
    }

    function showEmptyRankingsMessage() {
      rankings.append(cloneTemplate(readOnly ? 'readOnlyEmptyRankings' : 'emptyRankings'));
    }

    function createRankingEntry(id, old = {}, selectedCount) {
      const course = coursesById.get(id);
      const entry = cloneTemplate('ranking', course);
      entry.dataset.course = id;
      const rank = entry.querySelector('[name="courseRank"]');
      const history = entry.querySelector('[name="courseHistory"]');
      restoreRankingControls(rank, history, old, selectedCount);
      return entry;
    }

    function restoreRankingControls(rank, history, old, selectedCount) {
      for (let n = 1; n <= selectedCount; n++) {
        const suffix = ['th', 'st', 'nd', 'rd'][n] || 'th';
        rank.append(new Option(`${n}${suffix} choice`, String(n)));
      }
      // Closed applications retain historical ranks even when fewer choices remain.
      if (readOnly && old.rank && ![...rank.options].some(option => option.value === old.rank)) {
        rank.append(new Option(old.rank, old.rank));
      }
      const canKeepRank = readOnly || Number(old.rank) <= selectedCount;
      rank.value = canKeepRank ? old.rank || '' : '';
      history.value = old.history || '';
    }

    function renderRanks(savedChoices) {
      const previous = savedChoices || readCurrentChoices();
      const selectedIds = getSelectedCourseIds();
      rankings.replaceChildren();
      if (!selectedIds.length) showEmptyRankingsMessage();

      selectedIds.forEach(id => {
        rankings.append(createRankingEntry(id, previous.get(id), selectedIds.length));
      });
      updateRankAvailability();
      showCourseMessage(`${selectedIds.length} of ${MAX_COURSES} courses selected.`);
    }

    function createCourseRow(course, isSelected) {
      const row = cloneTemplate('row', course);
      const checkbox = row.querySelector('[name="selectedCourses"]');
      checkbox.value = course.course_id;
      checkbox.checked = isSelected;
      return row;
    }

    function renderCourses(savedChoices) {
      list.replaceChildren();
      if (!coursesById.size) {
        list.textContent = 'No courses are available for this application term.';
        return;
      }
      list.append(cloneTemplate('header'));
      coursesById.forEach(course => {
        list.append(createCourseRow(course, savedChoices.has(course.course_id)));
      });
    }

    // These containers survive rendering, so listeners only need to be attached once.
    list.addEventListener('change', event => {
      const checkbox = event.target;
      if (!checkbox.matches('[name="selectedCourses"]')) return;
      if (getSelectedCourseIds().length > MAX_COURSES) {
        checkbox.checked = false;
        showCourseMessage('Select no more than five courses.', true);
        return;
      }
      renderRanks();
    }, true);

    rankings.addEventListener('change', event => {
      const control = event.target;
      if (control.matches('[name="courseRank"]')) updateRankAvailability();
      if (control.matches('[name="courseHistory"]')) control.setCustomValidity('');
    }, true);

    return {
      hasCourses: () => coursesById.size > 0,
      hasMissingChoices: () => missingChoices,
      prefill(available, preferences = [], canEdit = true) {
        coursesById = new Map(available.map(course => [course.course_id, course]));
        readOnly = !canEdit;
        const savedChoices = new Map();
        missingChoices = false;
        for (const choice of preferences) {
          const isActive = canEdit
            ? choice.match_status === PENDING
            : choice.match_status !== WITHDRAWN;
          if (!isActive) continue;

          if (!coursesById.has(choice.course_id)) missingChoices = true;
          savedChoices.set(choice.course_id, {
            rank: choice.rank === null ? '' : String(choice.rank),
            history: choice.has_completed_course ? 'yes' : 'no'
          });
        }
        renderCourses(savedChoices);
        renderRanks(savedChoices);
      },
      validate() {
        rankings.querySelectorAll('[name="courseHistory"]').forEach(history => {
          // TODO: Finalize equivalent-course declarations and HR review with the team.
          let error = '';
          if (history.value === 'other') {
            error = 'Equivalent-course eligibility needs HR guidance before submission. Please contact HR.';
          } else if (history.value === 'no') {
            error = 'You must have completed each selected course.';
          }
          history.setCustomValidity(error);
        });
        updateRankAvailability();
      },
      selectionError(allowEmpty) {
        const count = getSelectedCourseIds().length;
        if (count <= MAX_COURSES && (allowEmpty || count > 0)) return '';
        return allowEmpty ? 'Select no more than five courses.' : 'Select between one and five courses.';
      },
      collect() {
        return [...rankings.querySelectorAll('[data-course]')].map(entry => ({
          course_id: entry.dataset.course,
          rank: Number(entry.querySelector('[name="courseRank"]').value),
          has_completed_course: true
        }));
      }
    };
  };
})();
