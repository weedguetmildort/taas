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

    function initializeCourseUpload() {
        const form = document.getElementById('upload-course-xlsx');
        const fileInput = document.getElementById('course-xlsx-file');
        const uploadButton = document.getElementById('upload-course-xlsx-button');
        const status = document.getElementById('course-upload-status');
        if (!form || !fileInput || !uploadButton || !status) {
            return;
        }

        function showResult(result) {
            const summary = document.createElement('p');
            summary.textContent = `Import complete: ${result.courses} added, ${result.replaced} replaced, ${result.skipped} skipped.`;
            status.replaceChildren(summary);

            if (result.messages.length > 0) {
                const list = document.createElement('ul');
                result.messages.forEach(function (message) {
                    const item = document.createElement('li');
                    item.textContent = `Row ${message.row}: ${message.header} — ${message.message}`;
                    list.appendChild(item);
                });
                status.appendChild(list);
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
            } catch (error) {
                console.error('Unable to upload course listings.', error);
                status.textContent = `Unable to upload course listings: ${error.message}`;
            } finally {
                uploadButton.disabled = false;
            }
        });
    }

    function initializeAdminPage() {
        const config = window.TAASAdminPage;
        if (config) {
            document.querySelectorAll('[data-admin-destination]').forEach(link => setDestination(link, config));
        }

        initializeStudentDownload();
        initializeCourseUpload();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAdminPage, { once: true });
    } else {
        initializeAdminPage();
    }
})();
