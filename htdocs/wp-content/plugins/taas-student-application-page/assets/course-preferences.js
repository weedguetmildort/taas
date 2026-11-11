/* Course display, saved selections, ranking and completion declarations. */
(() => {
  'use strict';
  const student = window.TAASStudent = window.TAASStudent || {};
  const MAX_COURSES = 5;
  const PENDING = 1;
  const WITHDRAWN = 4;
  const COURSE_HISTORY = ['no', 'yes', 'other'];

  student.createCoursePreferences = ({ form, list, rankings, message, sortByRank = false }) => {
    const templates = new Map(['header', 'row', 'ranking', 'emptyRankings', 'readOnlyEmptyRankings'].map(name => {
      const template = form.querySelector(`template[data-course-template="${name}"]`);
      if (!template?.content.firstElementChild) throw new Error(`Missing course template: ${name}`);
      return [name, template];
    }));
    // Validate the same root that cloneTemplate uses, before any data is loaded.
    for (const [templateName, controlName, selector] of [
      ['row', 'selectedCourses', 'input[type="checkbox"]'],
      ['ranking', 'courseRank', 'select[required]:not([multiple])'],
      ['ranking', 'courseHistory', 'select[required]:not([multiple])'],
      ['ranking', 'previouslyTA', 'input[type="checkbox"]']
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
    let choicesById = new Map();
    let hiddenUnrankedChoices = new Set();
    let allowUnranked = false;
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
      const counts = new Map();
      for (const { value } of selects) {
        if (value) counts.set(value, (counts.get(value) ?? 0) + 1);
      }
      selects.forEach(select => {
        [...select.options].forEach(option => {
          option.disabled = option.value !== select.value && counts.has(option.value);
        });
        select.setCustomValidity(counts.get(select.value) > 1 ? 'Each course must have a different rank.' : '');
      });
    }

    function rankedCourseCount() {
      return [...rankings.querySelectorAll('[name="courseRank"]')].filter(select => select.value).length;
    }

    function readCurrentChoices() {
      const entries = [...rankings.querySelectorAll('[data-course]')];
      return new Map(entries.map(entry => [entry.dataset.course, {
        rank: entry.querySelector('[name="courseRank"]').value,
        history: entry.querySelector('[name="courseHistory"]').value,
        previouslyTA: entry.querySelector('[name="previouslyTA"]').checked
      }]));
    }

    function showEmptyRankingsMessage() {
      rankings.append(cloneTemplate(readOnly ? 'readOnlyEmptyRankings' : 'emptyRankings'));
    }

    function createRankingEntry(id, old = {}, rankLimit) {
      const course = coursesById.get(id);
      const entry = cloneTemplate('ranking', course);
      entry.dataset.course = id;
      const rank = entry.querySelector('[name="courseRank"]');
      const history = entry.querySelector('[name="courseHistory"]');
      restoreRankingControls(rank, history, old, rankLimit);
      entry.querySelector('[name="previouslyTA"]').checked = old.previouslyTA === true;
      return entry;
    }

    function restoreRankingControls(rank, history, old, rankLimit) {
      rank.required = !allowUnranked;
      if (allowUnranked) rank.options[0].textContent = 'Unranked';
      for (let n = 1; n <= rankLimit; n++) {
        const suffix = ['th', 'st', 'nd', 'rd'][n] || 'th';
        rank.append(new Option(`${n}${suffix} choice`, String(n)));
      }
      // Closed applications retain historical ranks even when fewer choices remain.
      if (readOnly && old.rank && ![...rank.options].some(option => option.value === old.rank)) {
        rank.append(new Option(old.rank, old.rank));
      }
      const canKeepRank = readOnly || Number(old.rank) <= rankLimit;
      rank.value = canKeepRank ? old.rank || '' : '';
      history.value = old.history || '';
    }

    function rememberChoices() {
      for (const [id, choice] of readCurrentChoices()) choicesById.set(id, choice);
    }

    function updateSelectionMessage() {
      if (allowUnranked) {
        showCourseMessage(`${rankedCourseCount()} of ${MAX_COURSES} courses ranked. ${getSelectedCourseIds().length} courses selected.`);
      } else {
        showCourseMessage(`${getSelectedCourseIds().length} of ${MAX_COURSES} courses selected.`);
      }
    }

    function sortRankingCards() {
      if (!sortByRank) return;
      const focused = document.activeElement;
      const rankOf = entry => Number(entry.querySelector('[name="courseRank"]').value) || Number.MAX_SAFE_INTEGER;
      const entries = [...rankings.querySelectorAll('[data-course]')];
      entries.sort((a, b) => rankOf(a) - rankOf(b));
      entries.forEach(entry => rankings.append(entry));
      if (rankings.contains(focused)) focused.focus({ preventScroll: true });
    }

    function renderRanks() {
      const selectedIds = getSelectedCourseIds();
      const rankLimit = allowUnranked ? MAX_COURSES : selectedIds.length;
      rankings.replaceChildren();
      if (!selectedIds.length) showEmptyRankingsMessage();

      selectedIds.forEach(id => {
        rankings.append(createRankingEntry(id, choicesById.get(id), rankLimit));
      });
      sortRankingCards();
      updateRankAvailability();
      updateSelectionMessage();
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
      if (!allowUnranked && getSelectedCourseIds().length > MAX_COURSES) {
        checkbox.checked = false;
        showCourseMessage('Select no more than five courses.', true);
        return;
      }
      rememberChoices();
      renderRanks();
    }, true);

    rankings.addEventListener('change', event => {
      const control = event.target;
      if (control.matches('[name="courseRank"]')) {
        sortRankingCards();
        updateRankAvailability();
        updateSelectionMessage();
      }
    }, true);

    return {
      hasCourses: () => coursesById.size > 0,
      hasMissingChoices: () => missingChoices,
      prefill(available, preferences = [], { canEdit = true, unranked = false, resetPriorTa = false } = {}) {
        coursesById = new Map(available.map(course => [course.course_id, course]));
        choicesById = new Map();
        hiddenUnrankedChoices = new Set();
        allowUnranked = unranked;
        readOnly = !canEdit;
        const activeChoices = new Set();
        missingChoices = false;
        for (const choice of preferences) {
          const isActive = canEdit
            ? choice.match_status === PENDING
            : choice.match_status !== WITHDRAWN;
          if (isActive) {
            if (!coursesById.has(choice.course_id)) missingChoices = true;
            activeChoices.add(choice.course_id);
          }
          choicesById.set(choice.course_id, {
            rank: choice.rank === null || (!isActive && allowUnranked) ? '' : String(choice.rank),
            history: COURSE_HISTORY[choice.course_completion ?? Number(choice.has_completed_course)],
            previouslyTA: !resetPriorTa && choice.has_ta_for_course === true
          });
        }
        renderCourses(activeChoices);
        renderRanks();
      },
      setAllowUnranked(enabled) {
        if (allowUnranked === enabled) return;
        rememberChoices();
        allowUnranked = enabled;
        for (const checkbox of list.querySelectorAll('[name="selectedCourses"]')) {
          if (allowUnranked && hiddenUnrankedChoices.has(checkbox.value)) {
            checkbox.checked = true;
          } else if (!allowUnranked && checkbox.checked && !choicesById.get(checkbox.value)?.rank) {
            hiddenUnrankedChoices.add(checkbox.value);
            checkbox.checked = false;
          }
        }
        if (allowUnranked) hiddenUnrankedChoices.clear();
        renderRanks();
      },
      validate() {
        updateRankAvailability();
      },
      selectionError(isPhd) {
        const count = getSelectedCourseIds().length;
        if (allowUnranked) {
          return rankedCourseCount() === MAX_COURSES ? '' : 'PhD students must rank exactly five courses.';
        }
        if (isPhd) return count === MAX_COURSES ? '' : 'PhD students must select exactly five courses.';
        return count >= 1 && count <= MAX_COURSES ? '' : 'Select between one and five courses.';
      },
      collect() {
        return [...rankings.querySelectorAll('[data-course]')].map(entry => {
          const completion = COURSE_HISTORY.indexOf(entry.querySelector('[name="courseHistory"]').value);
          const rank = entry.querySelector('[name="courseRank"]').value;
          return {
            course_id: entry.dataset.course,
            rank: rank ? Number(rank) : null,
            has_completed_course: completion > 0,
            course_completion: completion,
            has_ta_for_course: entry.querySelector('[name="previouslyTA"]').checked
          };
        });
      }
    };
  };
})();
