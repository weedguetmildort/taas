/* Course display, saved selections, ranking and completion declarations. */
(() => {
  'use strict';
  const student = window.TAASStudent = window.TAASStudent || {};
  const MAX_COURSES = 5;
  const PENDING = 1;
  const WITHDRAWN = 4;
  const escape = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

  student.createCoursePreferences = ({ form, list, rankings, message, copy: courseCopy }) => {
    let coursesById = new Map();
    let readOnly = false;
    let missingChoices = false;
    const selected = () => [...list.querySelectorAll('[name="selectedCourses"]:checked')].map(input => input.value);
    function courseMessage(text, error = false) {
      const node = message;
      node.textContent = text;
      node.dataset.state = error ? 'error' : '';
    }
    function rankOptions() {
      const selects = [...form.querySelectorAll('[name="courseRank"]')];
      selects.forEach(select => {
        const used = new Set(selects.filter(other => other !== select).map(other => other.value).filter(Boolean));
        [...select.options].forEach(option => { option.disabled = !!option.value && used.has(option.value) && option.value !== select.value; });
        select.setCustomValidity(used.has(select.value) ? 'Each course must have a different rank.' : '');
      });
    }
    function renderRanks(savedChoices) {
      const host = rankings;
      const previous = savedChoices || new Map([...host.querySelectorAll('[data-course]')].map(entry => [entry.dataset.course, {
        rank: entry.querySelector('[name="courseRank"]').value,
        history: entry.querySelector('[name="courseHistory"]').value
      }]));
      const values = selected();
      host.replaceChildren();
      if (!values.length) {
        const empty = document.createElement('p');
        empty.className = 'empty-course-rankings';
        empty.textContent = readOnly
          ? courseCopy.readOnlyEmptyRankings || 'No active course preferences remain for this term.'
          : courseCopy.emptyRankings || 'Select one or more courses to rank them here.';
        host.append(empty);
      }
      values.forEach(id => {
        const course = coursesById.get(id);
        const old = previous.get(id) || {};
        const entry = document.createElement('fieldset');
        entry.className = 'student-app-preference';
        entry.dataset.course = id;
        entry.innerHTML = `<legend>${escape(course.course_code)} — ${escape(course.course_name)}</legend>
          <div class="course-information course-ranking-information">${[
            [courseCopy.instructorLabel || 'Instructor', course.instructor_name],
            [courseCopy.daysLabel || 'Class days', null],
            [courseCopy.timesLabel || 'Class times', null]
          ].map(([label, value]) => `<span class="course-detail"><span class="course-detail-label">${escape(label)}</span><span>${escape(value || 'Not provided')}</span></span>`).join('')}</div>
          <label>${escape(courseCopy.rankLabel || 'Preference rank')} <select name="courseRank" required><option value="">${escape(courseCopy.rankPlaceholder || 'Select rank')}</option></select></label>
          <label>${escape(courseCopy.completionLabel || 'Have you completed this course?')} <select name="courseHistory" required>
            <option value="">${escape(courseCopy.completionPlaceholder || 'Select answer')}</option><option value="yes">${escape(courseCopy.completedLabel || 'Yes, I have completed this course')}</option>
            <option value="other">${escape(courseCopy.equivalentLabel || 'I completed an equivalent course at another institution')}</option>
            <option value="no">${escape(courseCopy.incompleteLabel || 'No, I have not completed this course')}</option></select></label>`;
        const rank = entry.querySelector('[name="courseRank"]');
        for (let n = 1; n <= values.length; n++) rank.append(new Option(`${n}${n === 1 ? 'st' : n === 2 ? 'nd' : n === 3 ? 'rd' : 'th'} choice`, String(n)));
        if (readOnly && old.rank && ![...rank.options].some(option => option.value === old.rank)) {
          rank.append(new Option(old.rank, old.rank));
        }
        const canKeepRank = readOnly || Number(old.rank) <= values.length;
        rank.value = canKeepRank ? old.rank || '' : '';
        const history = entry.querySelector('[name="courseHistory"]');
        history.value = old.history || '';
        history.addEventListener('change', () => history.setCustomValidity(''));
        rank.addEventListener('change', rankOptions);
        host.append(entry);
      });
      rankOptions();
      courseMessage(`${values.length} of ${MAX_COURSES} courses selected.`);
    }
    function renderCourses() {
      const host = list;
      host.replaceChildren();
      if (!coursesById.size) { host.textContent = 'No courses are available for this application term.'; renderRanks(); return; }
      const header = document.createElement('div');
      header.className = 'course-table-header';
      ['', courseCopy.courseNumberLabel || 'Course number', courseCopy.courseNameLabel || 'Course name', courseCopy.instructorLabel || 'Instructor', courseCopy.daysLabel || 'Class days', courseCopy.timesLabel || 'Class times'].forEach(text => {
        const cell = document.createElement('span'); cell.textContent = text; header.append(cell);
      });
      host.append(header);
      coursesById.forEach(course => {
        const row = document.createElement('label');
        row.className = 'available-course-option';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox'; checkbox.name = 'selectedCourses'; checkbox.value = course.course_id;
        row.append(checkbox);
        // The backend has no days/times fields; retain the existing columns with honest placeholders.
        [course.course_code, course.course_name, course.instructor_name, 'Not provided', 'Not provided'].forEach(text => {
          const cell = document.createElement('span'); cell.className = 'course-detail';
          const content = document.createElement('span'); content.textContent = text || 'Not provided'; cell.append(content); row.append(cell);
        });
        checkbox.addEventListener('change', () => {
          if (selected().length > MAX_COURSES) { checkbox.checked = false; courseMessage('Select no more than five courses.', true); return; }
          renderRanks();
        });
        host.append(row);
      });
      renderRanks();
    }


    return {
      hasCourses: () => coursesById.size > 0,
      hasMissingChoices: () => missingChoices,
      prefill(available, preferences = [], canEdit = true) {
        coursesById = new Map(available.map(course => [course.course_id, course]));
        readOnly = !canEdit;
        renderCourses();
        const active = preferences.filter(choice => canEdit ? choice.match_status === PENDING : choice.match_status !== WITHDRAWN);
        const inputs = [...list.querySelectorAll('[name="selectedCourses"]')];
        active.forEach(choice => {
          const input = inputs.find(item => item.value === choice.course_id);
          if (input) input.checked = true;
        });
        missingChoices = active.some(choice => !inputs.some(input => input.value === choice.course_id));
        renderRanks(new Map(active.map(choice => [choice.course_id, {
          rank: choice.rank === null ? '' : String(choice.rank),
          history: choice.has_completed_course ? 'yes' : 'no'
        }])));
      },
      validate() {
        rankings.querySelectorAll('[name="courseHistory"]').forEach(history => {
          // TODO: Finalize equivalent-course declarations and HR review with the team.
          history.setCustomValidity(history.value === 'other'
            ? 'Equivalent-course eligibility needs HR guidance before submission. Please contact HR.'
            : history.value === 'no' ? 'You must have completed each selected course.' : '');
        });
        rankOptions();
      },
      selectionError(allowEmpty) {
        const count = selected().length;
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
