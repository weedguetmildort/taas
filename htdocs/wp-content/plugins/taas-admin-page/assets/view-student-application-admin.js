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

        // SWITCH BETWEEN COURSE AND STUDENT VIEWS
        viewSelector.addEventListener("change", function () {

            const showCourse = viewSelector.value === "course";

            courseView.hidden = !showCourse;
            studentView.hidden = showCourse;

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
        if (window.TAASMatchingPortal) {
            loadCourseRecommendations(courseView.querySelector("tbody"), window.TAASMatchingPortal);
        }

    }

    async function loadCourseRecommendations(rows, config) {
        const status = rows.querySelector('[role="status"]');
        status.textContent = "Loading matching recommendations…";

        const url = new URL(config.endpoint, window.location.href);
        url.searchParams.set("per_page", "100");

        const courses = [];
        let pages = 1;
        try {
            for (let page = 1; page <= pages; page++) {
                url.searchParams.set("page", String(page));
                const response = await fetch(url, {
                    headers: { "X-WP-Nonce": config.nonce },
                });
                const data = await response.json().catch(() => null);
                if (!response.ok || !Array.isArray(data)) {
                    throw new Error(data?.message || "Could not load matching recommendations.");
                }
                pages = Number(response.headers.get("X-WP-TotalPages") ?? 1);
                courses.push(...data);
            }
        } catch (error) {
            status.textContent = error.message;
            return;
        }

        if (!courses.length) {
            status.textContent = "No courses are listed for the current application cycle.";
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
