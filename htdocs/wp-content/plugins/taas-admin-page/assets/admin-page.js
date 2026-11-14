(() => {
    'use strict';

    function setDestination(link, config) {
        const destination = config.urls?.[link.dataset.adminDestination];
        try {
            if (typeof destination !== 'string' || !destination) {
                throw new Error('Missing destination');
            }
            const url = new URL(destination, window.location.href);
            if (url.origin !== window.location.origin || !['http:', 'https:'].includes(url.protocol)) {
                throw new Error('Invalid destination');
            }
            link.href = url.href;
        } catch {
            link.removeAttribute('href');
        }
    }

    function initializeStudentDownload() {
        const downloadButton = document.getElementById('download-student-data');
        if (!downloadButton) {
            return;
        }

        const headers = ['UFID', 'Student Name', 'Email'];

        function escapeCSV(value) {
            const text = String(value ?? '');
            return '"' + text.replace(/"/g, '""') + '"';
        }

        async function fetchStudents() {
            const config = window.TAASAdminPage;
            if (!config?.studentsEndpoint || !config.nonce) {
                throw new Error('Student data configuration is unavailable.');
            }

            const students = [];
            let page = 1;
            let totalPages = 1;

            while (page <= totalPages) {
                const url = new URL(config.studentsEndpoint, window.location.origin);
                url.searchParams.set('page', String(page));
                url.searchParams.set('per_page', '100');

                const response = await fetch(url.toString(), {
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': config.nonce }
                });

                if (!response.ok) {
                    let message = 'Student data request failed (' + response.status + ').';
                    try {
                        const error = await response.json();
                        if (error.message) message = error.message;
                    } catch {}
                    throw new Error(message);
                }

                const pageStudents = await response.json();
                if (!Array.isArray(pageStudents)) {
                    throw new Error('The student data response was invalid.');
                }

                const totalPagesHeader = response.headers.get('X-WP-TotalPages');
                const responsePages = Number(totalPagesHeader);
                if (totalPagesHeader === null || !Number.isInteger(responsePages) || responsePages < 0) {
                    throw new Error('The student data pagination response was invalid.');
                }

                totalPages = responsePages;
                students.push(...pageStudents);
                page += 1;
            }

            return students;
        }

        function downloadCSV(students) {
            const rows = students.map(function (student) {
                return [
                    student.student_ufid,
                    student.student_name,
                    student.student_email
                ].map(escapeCSV).join(',');
            });
            const csvContent = [headers.map(escapeCSV).join(','), ...rows].join('\r\n');
            const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');

            link.href = url;
            link.download = 'TA_Student_Applications.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(function () {
                URL.revokeObjectURL(url);
            }, 1000);
        }

        downloadButton.addEventListener('click', async function () {
            downloadButton.disabled = true;
            try {
                downloadCSV(await fetchStudents());
            } catch (error) {
                console.error('Unable to download student data.', error);
                window.alert('Unable to download student data: ' + error.message);
            } finally {
                downloadButton.disabled = false;
            }
        });
    }

    function initializeCourseListings() {
        const listing = document.querySelector('[data-manage-courses]');
        if (!listing) {
            return;
        }

        const heading = listing.querySelector('[data-course-list-term]');
        const status = listing.querySelector('[data-course-list-status]');
        const table = listing.querySelector('.manage-courses-table-wrapper');
        const rows = table.querySelector('tbody');
        const config = window.TAASAdminPage;
        const filter = listing.querySelector('#course-student-type');
        const actionStatus = listing.querySelector('[data-course-action-status]');
        const editor = listing.querySelector('[data-course-editor]');
        const form = editor.querySelector('form');
        const editStatus = editor.querySelector('[data-course-edit-status]');
        const saveButton = form.querySelector('[type="submit"]');
        const cancelButton = form.querySelector('[data-course-edit-cancel]');
        const professorSelect = form.elements.namedItem('professor_id');
        const eligibility = [...form.querySelectorAll('[name="eligible_student_types"]')];
        const studentTypes = [[1, 'Undergraduate'], [2, 'Master’s'], [4, 'PhD']];
        let courses = [];
        let term = '';
        let request;
        let editingCourse;

        function requestOptions() {
            return {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-WP-Nonce': config.nonce }
            };
        }

        async function loadPages(url, options) {
            const records = [];
            url.searchParams.set('per_page', '100');
            let totalPages = 1;
            for (let page = 1; page <= totalPages; page++) {
                url.searchParams.set('page', String(page));
                const response = await fetch(url, options);
                const data = await response.json();
                if (!response.ok || !Array.isArray(data)) {
                    throw new Error('Invalid list response.');
                }
                totalPages = Number(response.headers.get('X-WP-TotalPages') ?? 1);
                records.push(...data);
            }
            return records;
        }

        async function writeCourse(course, method, changes) {
            const url = new URL(config.courseListEndpoint, window.location.href);
            if (url.searchParams.has('rest_route')) {
                url.searchParams.set('rest_route', `${url.searchParams.get('rest_route')}/${course.course_id}`);
            } else {
                url.pathname = `${url.pathname.replace(/\/$/, '')}/${course.course_id}`;
            }
            const options = { ...requestOptions(), method };
            if (changes) {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(changes);
            }
            const response = await fetch(url, options);
            if (!response.ok) {
                const error = await response.json().catch(() => null);
                throw new Error(error?.message || 'The course could not be saved.');
            }
        }

        function setEditorBusy(busy) {
            saveButton.disabled = busy;
            cancelButton.disabled = busy;
        }

        async function openEditor(course) {
            editingCourse = course;
            for (const name of ['course_name', 'course_code', 'max_enrollment', 'enrollment', 'lab_hours', 'course_description']) {
                form.elements.namedItem(name).value = course[name] ?? '';
            }
            form.elements.namedItem('is_online').checked = course.is_online;
            for (const checkbox of eligibility) {
                checkbox.checked = Boolean(course.eligible_student_types & Number(checkbox.value));
            }
            professorSelect.replaceChildren(new Option(course.professor_name, course.professor_id));
            professorSelect.disabled = true;
            editStatus.textContent = 'Loading professors…';
            setEditorBusy(true);
            editor.showModal();
            try {
                const url = new URL(config.professorsEndpoint, window.location.href);
                const professors = await loadPages(url, requestOptions());
                professorSelect.replaceChildren(...professors.map(professor => new Option(
                    `${professor.professor_name} (${professor.professor_email})`, professor.professor_id
                )));
                professorSelect.value = course.professor_id;
                professorSelect.disabled = false;
                editStatus.textContent = '';
                setEditorBusy(false);
            } catch {
                editStatus.textContent = 'Professors could not be loaded. Close the editor and try again.';
                cancelButton.disabled = false;
            }
        }

        cancelButton.addEventListener('click', () => editor.close());
        editor.addEventListener('cancel', event => {
            if (cancelButton.disabled) {
                event.preventDefault();
            }
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const fields = new FormData(form);
            const types = fields.getAll('eligible_student_types').reduce((mask, value) => mask | Number(value), 0);
            if (!types) {
                editStatus.textContent = 'Select at least one eligible student type.';
                return;
            }
            const changes = {
                course_name: fields.get('course_name').trim(),
                course_code: fields.get('course_code').trim(),
                course_description: fields.get('course_description').trim() || null,
                professor_id: Number(fields.get('professor_id')),
                max_enrollment: Number(fields.get('max_enrollment')),
                enrollment: Number(fields.get('enrollment')),
                lab_hours: Number(fields.get('lab_hours')),
                is_online: fields.has('is_online'),
                eligible_student_types: types
            };
            setEditorBusy(true);
            editStatus.textContent = 'Saving course…';
            try {
                await writeCourse(editingCourse, 'PATCH', changes);
                editor.close();
                actionStatus.textContent = 'Course updated.';
                await refresh();
            } catch (error) {
                editStatus.textContent = error.message;
            } finally {
                setEditorBusy(false);
            }
        });

        async function deleteCourse(course, actions) {
            if (!window.confirm(`Delete ${course.course_code} — ${course.course_name}?`)) {
                return;
            }
            for (const button of actions.children) {
                button.disabled = true;
            }
            actionStatus.textContent = 'Deleting course…';
            try {
                await writeCourse(course, 'DELETE');
                actionStatus.textContent = 'Course deleted.';
                await refresh();
            } catch (error) {
                actionStatus.textContent = error.message;
                for (const button of actions.children) {
                    button.disabled = false;
                }
            }
        }

        function render() {
            const fragment = document.createDocumentFragment();
            for (const course of courses) {
                if (filter.value !== 'all' && !(course.eligible_student_types & Number(filter.value))) {
                    continue;
                }
                const row = document.createElement('tr');
                for (const field of [
                    'course_name', 'course_code', 'professor_name', 'professor_email',
                    'max_enrollment', 'enrollment', 'lab_hours'
                ]) {
                    row.insertCell().textContent = course[field] ?? '—';
                }
                row.insertCell().textContent = course.is_online ? 'UFO' : 'In-person';
                const types = studentTypes.filter(([flag]) => course.eligible_student_types & flag);
                row.insertCell().textContent = types.length === studentTypes.length ? 'All Students' : types.map(([, label]) => label).join(', ');
                const actions = document.createElement('div');
                actions.className = 'course-row-actions';
                for (const [label, handler] of [['Edit', () => openEditor(course)], ['Delete', () => deleteCourse(course, actions)]]) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = label === 'Edit' ? 'edit-course-button' : 'delete-course-button';
                    button.textContent = label;
                    button.addEventListener('click', handler);
                    actions.append(button);
                }
                row.insertCell().append(actions);
                fragment.append(row);
            }
            const hasCourses = fragment.hasChildNodes();
            rows.replaceChildren(fragment);
            table.hidden = !hasCourses;
            status.textContent = hasCourses ? '' : filter.value === 'all'
                ? `No courses are listed for ${term}.`
                : `No courses are listed for ${filter.selectedOptions[0].textContent} in ${term}.`;
        }

        filter.disabled = true;
        filter.addEventListener('change', render);

        async function refresh() {
            request?.abort();
            request = new AbortController();
            const { signal } = request;
            filter.disabled = true;
            heading.textContent = 'Current Course Listings';
            status.textContent = 'Loading course listings…';
            table.hidden = true;
            rows.replaceChildren();

            try {
                if (!config?.cycleEndpoint || !config.courseListEndpoint || !config.nonce) {
                    throw new Error('Missing course listing configuration.');
                }
                const options = { ...requestOptions(), signal };

                const cycleResponse = await fetch(config.cycleEndpoint, options);
                const cycle = await cycleResponse.json();
                if (cycleResponse.status === 404 && cycle?.code === 'taas_no_current_cycle') {
                    status.textContent = 'No application cycle has been set.';
                    return;
                }
                if (!cycleResponse.ok || typeof cycle?.term !== 'string' || !cycle.term.trim() || !Number.isInteger(cycle.year)) {
                    throw new Error('Invalid application cycle response.');
                }

                term = `${cycle.term} ${cycle.year}`;
                heading.textContent = `Current Course Listings for ${term}`;
                const url = new URL(config.courseListEndpoint, window.location.href);
                url.searchParams.set('term', cycle.term);
                url.searchParams.set('year', String(cycle.year));
                courses = await loadPages(url, options);
                filter.disabled = false;
                render();
            } catch (error) {
                if (!signal.aborted) {
                    console.error('Unable to load course listings.', error);
                    status.textContent = 'Course listings could not be loaded. Reload the page to try again.';
                }
            }
        }

        refresh();
        return refresh;
    }

    function initializeCourseUpload(refreshListings) {
        const form = document.getElementById('upload-course-xlsx');
        const fileInput = document.getElementById('course-xlsx-file');
        const uploadButton = document.getElementById('upload-course-xlsx-button');
        const status = document.getElementById('course-upload-status');
        if (!form || !fileInput || !uploadButton || !status) {
            return;
        }

        function showResult(result) {
            const outcome = document.createElement('strong');
            if (result.skipped > 0) {
                outcome.textContent = result.courses + result.replaced > 0
                    ? 'Course import finished with skipped rows.'
                    : 'No courses were imported.';
            } else {
                outcome.textContent = result.courses + result.replaced > 0
                    ? 'Course listings imported successfully.'
                    : 'Import complete. The file contained no course rows.';
            }

            const summary = document.createElement('p');
            summary.textContent = `${result.courses} added, ${result.replaced} replaced, ${result.skipped} skipped.`;
            status.replaceChildren(outcome, summary);

            if (result.messages.length > 0) {
                const instructions = document.createElement('p');
                instructions.textContent = 'Download the report below to see which rows used defaults or were skipped.';
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = 'Download detailed import report';
                button.addEventListener('click', () => {
                    const messages = result.messages.map(
                        ({ row, header, message }) => `Row ${row}: ${header} — ${message}`
                    );
                    const report = `Course import report\n\n${outcome.textContent}\n${summary.textContent}\n\n${messages.join('\n')}\n`;
                    const url = URL.createObjectURL(new Blob([report], { type: 'text/plain;charset=utf-8' }));
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = 'course-import-report.txt';
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    setTimeout(() => URL.revokeObjectURL(url), 1000);
                });
                status.append(instructions, button);
            }
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            status.textContent = '';

            const file = fileInput.files?.[0];
            if (!file) {
                status.textContent = 'Choose a course XLSX file to upload.';
                return;
            }
            if (!/\.xlsx$/i.test(file.name)) {
                status.textContent = 'The selected file must be an XLSX spreadsheet.';
                return;
            }

            const config = window.TAASAdminPage;
            if (!config?.coursesEndpoint || !config.nonce) {
                status.textContent = 'Course upload configuration is unavailable.';
                return;
            }

            const formData = new FormData();
            formData.append('file', file);
            uploadButton.disabled = true;
            status.textContent = 'Uploading course listings…';

            try {
                const response = await fetch(config.coursesEndpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'X-WP-Nonce': config.nonce },
                    body: formData
                });

                let result;
                try {
                    result = await response.json();
                } catch {
                    throw new Error('The server returned an unexpected response.');
                }
                if (!response.ok) {
                    const fileError = result?.data?.params?.file;
                    throw new Error(typeof fileError === 'string'
                        ? fileError
                        : typeof result?.message === 'string'
                            ? result.message
                            : `Course upload failed (${response.status}).`);
                }
                if (response.status !== 200) {
                    throw new Error(`Unexpected course import response (${response.status}).`);
                }
                if (!result || typeof result !== 'object'
                    || !Number.isInteger(result.courses) || result.courses < 0
                    || !Number.isInteger(result.replaced) || result.replaced < 0
                    || !Number.isInteger(result.skipped) || result.skipped < 0
                    || !Array.isArray(result.messages)
                    || !result.messages.every(message => message && Number.isInteger(message.row)
                        && typeof message.header === 'string' && typeof message.message === 'string')) {
                    throw new Error('The server returned an unexpected import result.');
                }

                showResult(result);
                form.reset();
                await refreshListings?.();
            } catch (error) {
                console.error('Unable to upload course listings.', error);
                status.textContent = `Unable to upload course listings: ${error.message}`;
            } finally {
                uploadButton.disabled = false;
            }
        });

        if (fileInput.hidden) {
            uploadButton.addEventListener('click', () => fileInput.click());
            fileInput.addEventListener('change', () => {
                if (fileInput.files?.length) {
                    form.requestSubmit();
                    fileInput.value = '';
                }
            });
        }
    }

    function initializeAdminPage() {
        const config = window.TAASAdminPage;
        if (config) {
            document.querySelectorAll('[data-admin-destination]').forEach(link => setDestination(link, config));
        }

        initializeStudentDownload();
        initializeCourseUpload(initializeCourseListings());
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAdminPage, { once: true });
    } else {
        initializeAdminPage();
    }
})();
