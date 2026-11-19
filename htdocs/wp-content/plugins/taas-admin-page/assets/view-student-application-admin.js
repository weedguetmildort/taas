(function () {

    function initializeAdminMatching() {

        const container = document.querySelector(
            ".admin-applications-container"
        );

        if (!container) return;

        const viewSelector = container.querySelector(
            "#admin-view-type"
        );

        const courseView = container.querySelector(
            "#admin-course-view"
        );

        const studentView = container.querySelector(
            "#admin-student-view"
        );
        const matchingForm = container.querySelector("#admin-matching-form");
        const matchingConfig = window.TAASMatchingPortal;
        const viewTitle = container.querySelector("#admin-matching-view-title");
        const runButton = matchingForm.querySelector(".run-matching-button");
        const runNote = matchingForm.querySelector("#admin-matching-run-note");

        // SWITCH BETWEEN COURSE AND STUDENT VIEWS
        viewSelector.addEventListener("change", function () {

            const showCourse = viewSelector.value === "course";

            courseView.hidden = !showCourse;
            studentView.hidden = showCourse;
            matchingForm.hidden = !matchingConfig;
            runButton.hidden = !showCourse;
            runNote.hidden = !showCourse;
            if (viewTitle) {
                viewTitle.textContent = showCourse ? "Course Recommendations" : "Student Applications";
            }

        });

        // APPLY INITIAL VIEW
        viewSelector.dispatchEvent(new Event("change"));
        if (matchingConfig) {
            initializeMatchingControls(matchingForm, viewSelector, {
                course: courseView.querySelector("tbody"),
                student: studentView.querySelector("tbody"),
            }, matchingConfig, container.querySelector(".ta-approval-progress"));
        }

    }

    async function initializeMatchingControls(form, viewSelector, tables, config, progress) {
        const controls = form.querySelector("fieldset");
        const term = form.querySelector("#admin-matching-term");
        const year = form.querySelector("#admin-matching-year");
        const run = form.querySelector(".run-matching-button");
        const status = form.querySelector("#admin-matching-run-status");
        let cycleLoaded = false;
        let request;
        let progressRequest;

        function selectedUrl(endpoint) {
            const url = new URL(endpoint, window.location.href);
            url.searchParams.set("term", term.value);
            url.searchParams.set("year", String(year.valueAsNumber));
            return url;
        }

        function reloadResults() {
            request?.abort();
            const byStudent = viewSelector.value === "student";
            const rows = byStudent ? tables.student : tables.course;
            if (!cycleLoaded) {
                showTableMessage(rows, "Loading the current application cycle…");
                return;
            }
            if (!form.checkValidity()) {
                showTableMessage(rows, "Select a term and year to see matching results.");
                return;
            }

            request = new AbortController();
            return loadMatchingResults(
                rows,
                selectedUrl(config.recommendationsEndpoint),
                selectedUrl(byStudent ? config.studentMatchesEndpoint : config.courseMatchesEndpoint),
                config.nonce,
                request.signal,
                byStudent ? renderStudentResults : renderCourseResults
            );
        }

        function reloadProgress() {
            if (!progress?.querySelector("#approval-progress-metrics")) return;
            progressRequest?.abort();

            const termLabel = progress.querySelector("#approval-progress-term");
            if (!cycleLoaded || !form.checkValidity()) {
                termLabel.textContent = "";
                showProgressMessage(progress, cycleLoaded ? "Select a term and year to see review progress." : "Loading review progress…");
                return;
            }

            termLabel.textContent = `${term.value} ${year.valueAsNumber}`;
            progressRequest = new AbortController();
            return loadReviewProgress(progress, selectedUrl(config.progressEndpoint), config.nonce, progressRequest.signal);
        }

        function updateRunButton() {
            run.disabled = controls.disabled || run.hidden || !form.checkValidity();
        }

        function setSaving(saving) {
            controls.disabled = saving;
            viewSelector.disabled = saving;
            updateRunButton();
            tables.course.querySelectorAll("button[data-match-status]").forEach(button => {
                button.disabled = saving;
            });
        }

        tables.course.addEventListener("click", async function (event) {
            const button = event.target.closest("button[data-match-status]");
            if (!button || controls.disabled) return;

            const row = button.closest("tr");
            const feedback = row.querySelector(".admin-match-feedback");
            const url = config.applicationEndpoint.replace("__application_id__", row.dataset.applicationId);
            const decision = { match_status: button.dataset.matchStatus };
            if (decision.match_status === "Matched") {
                decision.assigned_hours = Number(row.dataset.recommendedHours);
            }

            setSaving(true);
            feedback.textContent = "Saving decision…";
            try {
                const { data } = await matchingRequest(url, config.nonce, {
                    method: "PATCH",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(decision),
                });
                updateMatchStatus(row.querySelector(".match-status"), data.match_status);
                row.querySelector(".admin-match-actions").remove();
                feedback.textContent = "Decision saved.";
                reloadProgress();
            } catch (error) {
                feedback.textContent = error.message;
            } finally {
                setSaving(false);
            }
        });

        viewSelector.addEventListener("change", function () {
            updateRunButton();
            reloadResults();
        });
        form.addEventListener("input", updateRunButton);
        form.addEventListener("change", function () {
            status.textContent = "";
            reloadResults();
            reloadProgress();
        });
        form.addEventListener("submit", async function (event) {
            event.preventDefault();
            if (controls.disabled || run.hidden || !form.reportValidity()) return;

            const url = selectedUrl(config.runEndpoint);
            setSaving(true);
            status.textContent = "Running matching…";

            try {
                const { data } = await matchingRequest(url, config.nonce, { method: "POST" });
                await reloadResults();
                status.textContent = `Matching complete. Recommendations saved: ${data.recommendations_made}.`;
            } catch (error) {
                status.textContent = error.message;
            } finally {
                setSaving(false);
            }
        });

        reloadResults();
        reloadProgress();
        try {
            const { data } = await matchingRequest(config.cycleEndpoint, config.nonce);
            term.value = data.term;
            year.value = data.year;
        } catch (error) {
            status.textContent = error.message;
        } finally {
            cycleLoaded = true;
            controls.disabled = false;
            updateRunButton();
            reloadResults();
            reloadProgress();
        }
    }

    function showProgressMessage(panel, text) {
        panel.querySelector("#approval-progress-metrics").hidden = true;
        panel.querySelector("#approval-progress-message").textContent = text;
    }

    async function loadReviewProgress(panel, url, nonce, signal) {
        showProgressMessage(panel, "Loading review progress…");

        try {
            const { data } = await matchingRequest(url, nonce, { signal });
            if (signal.aborted) return;

            const { matches_made: approved, applications_declined: declined, applications_pending: pending } = data;
            if (![approved, declined, pending].every(value => Number.isSafeInteger(value) && value >= 0)) {
                throw new Error("The review totals are unavailable.");
            }

            const reviewed = approved + declined;
            const total = reviewed + pending;
            if (!total) {
                showProgressMessage(panel, "No applications to review for the selected term.");
                return;
            }

            const bar = panel.querySelector('[role="progressbar"]');
            bar.setAttribute("aria-valuemax", String(total));
            bar.setAttribute("aria-valuenow", String(reviewed));
            bar.setAttribute("aria-valuetext", `${reviewed} of ${total} applications reviewed`);
            panel.querySelector("#approval-progress-fill").style.width = `${reviewed / total * 100}%`;
            panel.querySelector("#reviewed-count").textContent = reviewed;
            panel.querySelector("#total-applications").textContent = total;
            panel.querySelector("#approval-percentage").textContent = `${Math.floor(reviewed * 1000 / total) / 10}% Reviewed`;
            panel.querySelector("#approval-decisions").textContent = `${approved} Approved · ${declined} Declined`;
            panel.querySelector("#remaining-count").textContent = `${pending} Remaining`;
            panel.querySelector("#approval-progress-message").textContent = "";
            panel.querySelector("#approval-progress-metrics").hidden = false;
        } catch (error) {
            if (!signal.aborted) showProgressMessage(panel, `Could not load review progress. ${error.message}`);
        }
    }

    async function matchingRequest(url, nonce, options = {}) {
        const response = await fetch(url, { ...options, headers: { ...options.headers, "X-WP-Nonce": nonce } });
        const data = await response.json().catch(() => null);
        if (!response.ok || data === null) {
            throw new Error(data?.message || "The matching request failed. Try again.");
        }
        return { data, pages: Number(response.headers.get("X-WP-TotalPages") ?? 1) };
    }

    function showTableMessage(rows, text) {
        rows.replaceChildren();
        const cell = rows.insertRow().insertCell();
        cell.colSpan = rows.parentElement.tHead.rows[0].cells.length;
        cell.setAttribute("role", "status");
        cell.textContent = text;
    }

    async function fetchMatchingPages(url, nonce, signal) {
        url.searchParams.set("per_page", "100");
        const items = [];

        let pages = 1;
        for (let page = 1; page <= pages; page++) {
            url.searchParams.set("page", String(page));
            const result = await matchingRequest(url, nonce, { signal });
            if (!Array.isArray(result.data)) {
                throw new Error("Could not load matching results.");
            }
            pages = result.pages;
            items.push(...result.data);
        }
        return items;
    }

    async function loadMatchingResults(rows, recommendationsUrl, matchesUrl, nonce, signal, render) {
        showTableMessage(rows, "Loading matching results…");

        let recommendations, matches;
        try {
            [recommendations, matches] = await Promise.all([
                fetchMatchingPages(recommendationsUrl, nonce, signal),
                fetchMatchingPages(matchesUrl, nonce, signal),
            ]);
        } catch (error) {
            if (!signal.aborted) showTableMessage(rows, error.message);
            return;
        }

        if (!signal.aborted) render(rows, recommendations, matches);
    }

    function renderCourseResults(rows, courses, approvedCourses) {
        if (!courses.length) {
            showTableMessage(rows, "No courses are listed for the selected term.");
            return;
        }

        const approvedByCourse = new Map(approvedCourses.map(course => [course.course_id, course.matches]));
        rows.replaceChildren();
        for (const course of courses) {
            const applications = new Map(course.recommendations.map(match => [match.application_id, match]));
            for (const match of approvedByCourse.get(course.course_id) ?? []) {
                applications.set(match.application_id, { ...match, match_status: "Matched" });
            }

            if (!applications.size) {
                addCourseRow(rows, course, null);
            }
            for (const application of applications.values()) {
                addCourseRow(rows, course, application);
            }
        }
    }

    function renderStudentResults(rows, courses, students) {
        if (!students.length) {
            showTableMessage(rows, "No student applications are listed for the selected term.");
            return;
        }

        const recommendationsByStudent = new Map();
        for (const course of courses) {
            for (const recommendation of course.recommendations) {
                if (recommendation.match_status === "Withdrawn") continue;
                const ufid = recommendation.student_ufid;
                if (!recommendationsByStudent.has(ufid)) {
                    recommendationsByStudent.set(ufid, new Map());
                }
                recommendationsByStudent.get(ufid).set(recommendation.application_id, {
                    course_code: course.course_code,
                    match_status: recommendation.match_status,
                });
            }
        }

        rows.replaceChildren();
        for (const student of students) {
            const applications = recommendationsByStudent.get(student.student_ufid) ?? new Map();
            for (const match of student.matches) {
                applications.set(match.application_id, { ...match, match_status: "Matched" });
            }
            for (const declined of student.declined_applications) {
                applications.set(declined.application_id, { ...declined, match_status: "Rejected" });
            }

            if (!applications.size) addStudentRow(rows, student, null);
            for (const application of applications.values()) {
                addStudentRow(rows, student, application);
            }
        }
    }

    function addStudentRow(rows, student, application) {
        const row = rows.insertRow();
        row.insertCell().textContent = student.student_name;
        row.insertCell().textContent = student.student_ufid;

        const emailCell = row.insertCell();
        if (student.student_email) {
            const link = document.createElement("a");
            link.href = "mailto:" + encodeURIComponent(student.student_email).replace("%40", "@");
            link.textContent = student.student_email;
            emailCell.append(link);
        } else {
            emailCell.textContent = "—";
        }

        row.insertCell().textContent = student.top_ranked_course ?? "Not Ranked Yet";
        row.insertCell().textContent = application?.course_code ?? "—";
        const badge = document.createElement("span");
        updateMatchStatus(badge, application?.match_status);
        row.insertCell().append(badge);
    }

    function addCourseRow(rows, course, application) {
        const row = rows.insertRow();
        const cells = [
            course.course_name,
            course.course_code,
            course.professor_name ?? "—",
            "",
            course.weekly_hours == null ? "—" : `${course.weekly_hours} hrs/week`,
            course.top_ranked_student ?? "Not Ranked Yet",
            application?.student_name ?? "—",
        ];
        for (const value of cells) {
            row.insertCell().textContent = value;
        }

        const status = document.createElement("span");
        updateMatchStatus(status, application?.match_status);
        row.insertCell().append(status);

        const action = row.insertCell();
        if (application?.match_status !== "Pending") {
            action.textContent = "—";
            return;
        }

        row.dataset.applicationId = application.application_id;
        row.dataset.recommendedHours = application.hours;
        const buttons = document.createElement("div");
        buttons.className = "admin-match-actions";
        for (const [label, decision, className] of [
            ["Approve", "Matched", "approve-match-button"],
            ["Decline", "Rejected", "decline-match-button"],
        ]) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = className;
            button.textContent = label;
            button.dataset.matchStatus = decision;
            button.setAttribute("aria-label", `${label} ${application.student_name} for ${course.course_code}`);
            buttons.append(button);
        }
        const feedback = document.createElement("p");
        feedback.className = "admin-match-feedback";
        feedback.setAttribute("role", "status");
        action.append(buttons, feedback);
    }

    function updateMatchStatus(badge, decision) {
        const [label, variant] = {
            Pending: ["Proposed Match", "proposed"],
            Matched: ["Approved", "approved"],
            Rejected: ["Declined", "unmatched"],
            Withdrawn: ["Withdrawn", "unmatched"],
        }[decision] ?? ["No Proposed Match", "unmatched"];
        badge.className = `match-status ${variant}`;
        badge.textContent = label;
    }

    if (document.readyState === "loading") {
        document.addEventListener(
            "DOMContentLoaded",
            initializeAdminMatching
        );
    } else {
        initializeAdminMatching();
    }

})();
