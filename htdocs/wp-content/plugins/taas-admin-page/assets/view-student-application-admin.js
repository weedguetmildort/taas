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

        // APPROVE MATCH (DEMONSTRATION)
        courseView.addEventListener("click", function (event) {
            const button = event.target.closest(".approve-match-button");
            if (!button || button.disabled) return;

            const row = button.closest("tr");
            const status = row.querySelector(".match-status");

            if (!confirm(
                "Approve this proposed TA match? " +
                "This is a demonstration only."
            )) {
                return;
            }

            status.textContent = "Approved";
            status.classList.remove("proposed");
            status.classList.add("approved");

            button.textContent = "Approved";
            button.disabled = true;

            // Update demo progress counter
            const approvedCount = container.querySelector(
                "#approved-count"
            );

            const totalCount = container.querySelector(
                "#total-applicants"
            );

            const approved =
                Number(approvedCount.textContent) + 1;

            const total =
                Number(totalCount.textContent);

            approvedCount.textContent = approved;

            const percentage =
                total > 0 ? (approved / total) * 100 : 0;

            container.querySelector(
                "#approval-progress-fill"
            ).style.width = percentage + "%";

            container.querySelector(
                "#approval-percentage"
            ).textContent =
                percentage.toFixed(1) + "% Completed";

            container.querySelector(
                "#remaining-count"
            ).textContent =
                Math.max(0, total - approved) + " Remaining";
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

        function reloadRecommendations() {
            request?.abort();
            if (!form.checkValidity()) {
                showCourseMessage(rows, "Select a term and year to see saved recommendations.");
                return;
            }

            request = new AbortController();
            return loadCourseRecommendations(rows, selectedUrl(config.recommendationsEndpoint), config.nonce, request.signal);
        }

        function updateRunButton() {
            run.disabled = controls.disabled || !form.checkValidity();
        }

        form.addEventListener("input", updateRunButton);
        form.addEventListener("change", function () {
            status.textContent = "";
            reloadRecommendations();
        });
        form.addEventListener("submit", async function (event) {
            event.preventDefault();
            if (controls.disabled || !form.reportValidity()) return;

            const url = selectedUrl(config.runEndpoint);
            controls.disabled = true;
            updateRunButton();
            status.textContent = "Running matching…";

            try {
                const { data } = await matchingRequest(url, config.nonce, { method: "POST" });
                await reloadRecommendations();
                status.textContent = `Matching complete. Recommendations saved: ${data.recommendations_made}.`;
            } catch (error) {
                status.textContent = error.message;
            } finally {
                controls.disabled = false;
                updateRunButton();
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
            reloadRecommendations();
        }
    }

    async function matchingRequest(url, nonce, options = {}) {
        const response = await fetch(url, { ...options, headers: { "X-WP-Nonce": nonce } });
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

    async function loadCourseRecommendations(rows, url, nonce, signal) {
        showCourseMessage(rows, "Loading matching recommendations…");
        url.searchParams.set("per_page", "100");

        const courses = [];
        try {
            let pages = 1;
            for (let page = 1; page <= pages; page++) {
                url.searchParams.set("page", String(page));
                const result = await matchingRequest(url, nonce, { signal });
                if (!Array.isArray(result.data)) {
                    throw new Error("Could not load matching recommendations.");
                }
                pages = result.pages;
                courses.push(...result.data);
            }
        } catch (error) {
            if (!signal.aborted) showCourseMessage(rows, error.message);
            return;
        }

        if (signal.aborted) return;

        if (!courses.length) {
            showCourseMessage(rows, "No courses are listed for the selected term.");
            return;
        }

        rows.replaceChildren();
        for (const course of courses) {
            if (!course.recommendations.length) {
                addCourseRow(rows, course, null);
            }
            for (const recommendation of course.recommendations) {
                addCourseRow(rows, course, recommendation);
            }
        }
    }

    function addCourseRow(rows, course, recommendation) {
        const row = rows.insertRow();
        const cells = [
            course.course_name,
            course.course_code,
            course.professor_name ?? "—",
            "",
            course.weekly_hours == null ? "—" : `${course.weekly_hours} hrs/week`,
            course.top_ranked_student ?? "Not Ranked Yet",
            recommendation?.student_name ?? "—",
        ];
        for (const value of cells) {
            row.insertCell().textContent = value;
        }

        const status = document.createElement("span");
        status.className = recommendation ? "match-status proposed" : "match-status unmatched";
        status.textContent = recommendation ? "Proposed Match" : "No Proposed Match";
        row.insertCell().append(status);

        const action = row.insertCell();
        if (recommendation) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "approve-match-button";
            button.textContent = "Approve Match";
            action.append(button);
        } else {
            action.textContent = "—";
        }
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
