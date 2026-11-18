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

        // SWITCH BETWEEN COURSE AND STUDENT VIEWS
        viewSelector.addEventListener("change", function () {

            const showCourse = viewSelector.value === "course";

            courseView.hidden = !showCourse;
            studentView.hidden = showCourse;
            matchingForm.hidden = !showCourse || !matchingConfig;
            if (viewTitle) {
                viewTitle.textContent = showCourse ? "Course Recommendations" : "Student Applications";
            }

        });

        // APPLY INITIAL VIEW
        viewSelector.dispatchEvent(new Event("change"));
        if (matchingConfig) {
            initializeMatchingControls(matchingForm, courseView.querySelector("tbody"), matchingConfig);
        }

    }

    async function initializeMatchingControls(form, rows, config) {
        const controls = form.querySelector("fieldset");
        const term = form.querySelector("#admin-matching-term");
        const year = form.querySelector("#admin-matching-year");
        const run = form.querySelector(".run-matching-button");
        const status = form.querySelector("#admin-matching-run-status");
        let request;

        function selectedUrl(endpoint) {
            const url = new URL(endpoint, window.location.href);
            url.searchParams.set("term", term.value);
            url.searchParams.set("year", String(year.valueAsNumber));
            return url;
        }

        function reloadCourseResults() {
            request?.abort();
            if (!form.checkValidity()) {
                showCourseMessage(rows, "Select a term and year to see matching results.");
                return;
            }

            request = new AbortController();
            return loadCourseResults(
                rows,
                selectedUrl(config.recommendationsEndpoint),
                selectedUrl(config.courseMatchesEndpoint),
                config.nonce,
                request.signal
            );
        }

        function updateRunButton() {
            run.disabled = controls.disabled || !form.checkValidity();
        }

        function setSaving(saving) {
            controls.disabled = saving;
            updateRunButton();
            rows.querySelectorAll("button[data-match-status]").forEach(button => {
                button.disabled = saving;
            });
        }

        rows.addEventListener("click", async function (event) {
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
            } catch (error) {
                feedback.textContent = error.message;
            } finally {
                setSaving(false);
            }
        });

        form.addEventListener("input", updateRunButton);
        form.addEventListener("change", function () {
            status.textContent = "";
            reloadCourseResults();
        });
        form.addEventListener("submit", async function (event) {
            event.preventDefault();
            if (controls.disabled || !form.reportValidity()) return;

            const url = selectedUrl(config.runEndpoint);
            setSaving(true);
            status.textContent = "Running matching…";

            try {
                const { data } = await matchingRequest(url, config.nonce, { method: "POST" });
                await reloadCourseResults();
                status.textContent = `Matching complete. Recommendations saved: ${data.recommendations_made}.`;
            } catch (error) {
                status.textContent = error.message;
            } finally {
                setSaving(false);
            }
        });

        showCourseMessage(rows, "Loading the current application cycle…");
        try {
            const { data } = await matchingRequest(config.cycleEndpoint, config.nonce);
            term.value = data.term;
            year.value = data.year;
        } catch (error) {
            status.textContent = error.message;
        } finally {
            controls.disabled = false;
            updateRunButton();
            reloadCourseResults();
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

    function showCourseMessage(rows, text) {
        rows.replaceChildren();
        const cell = rows.insertRow().insertCell();
        cell.colSpan = rows.parentElement.tHead.rows[0].cells.length;
        cell.setAttribute("role", "status");
        cell.textContent = text;
    }

    async function fetchCoursePages(url, nonce, signal) {
        url.searchParams.set("per_page", "100");
        const courses = [];

        let pages = 1;
        for (let page = 1; page <= pages; page++) {
            url.searchParams.set("page", String(page));
            const result = await matchingRequest(url, nonce, { signal });
            if (!Array.isArray(result.data)) {
                throw new Error("Could not load matching results.");
            }
            pages = result.pages;
            courses.push(...result.data);
        }
        return courses;
    }

    async function loadCourseResults(rows, recommendationsUrl, matchesUrl, nonce, signal) {
        showCourseMessage(rows, "Loading matching results…");

        let courses, approvedCourses;
        try {
            [courses, approvedCourses] = await Promise.all([
                fetchCoursePages(recommendationsUrl, nonce, signal),
                fetchCoursePages(matchesUrl, nonce, signal),
            ]);
        } catch (error) {
            if (!signal.aborted) showCourseMessage(rows, error.message);
            return;
        }

        if (signal.aborted) return;

        if (!courses.length) {
            showCourseMessage(rows, "No courses are listed for the selected term.");
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
