(function () {
    "use strict";

    async function loadCycle(term, status) {
        const { cycleEndpoint, nonce } = window.TAASHrPage || {};
        try {
            if (!cycleEndpoint || !nonce) {
                throw new Error("Missing cycle configuration.");
            }

            const response = await fetch(cycleEndpoint, {
                credentials: "same-origin",
                cache: "no-store",
                headers: { "X-WP-Nonce": nonce }
            });
            const cycle = await response.json();
            if (response.status === 404 && cycle?.code === "taas_no_current_cycle") {
                status.textContent = "No application cycle has been set.";
                return;
            }
            if (!response.ok || typeof cycle?.term !== "string" || !cycle.term.trim() || !Number.isInteger(cycle.year) ||
                (cycle.stage !== null && typeof cycle.stage !== "string")) {
                throw new Error("Invalid cycle response.");
            }

            term.textContent = `${cycle.term} ${cycle.year}`;
            status.textContent = cycle.stage === "hr_review" ? "HR review is open." : "HR review is closed.";
        } catch {
            status.textContent = "The current application cycle could not be loaded. Reload the page to try again.";
        }
    }

    function initializeHRPortal() {

        const container = document.querySelector(
            ".hr-portal-container"
        );

        if (!container) {
            console.error("HR portal container not found.");
            return;
        }

        // Prevent duplicate initialization
        if (container.dataset.initialized === "true") {
            return;
        }

        const cycleTerm = container.querySelector("[data-hr-cycle-term]");
        const cycleStatus = container.querySelector("[data-hr-cycle]");
        if (cycleTerm && cycleStatus) {
            loadCycle(cycleTerm, cycleStatus);
        }

        const table = container.querySelector(
            ".hr-applicant-table"
        );

        const filter = container.querySelector(
            "#student-type-filter"
        );

        const saveButton = container.querySelector(
            ".save-button"
        );

        const saveMessage = container.querySelector(
            "#hr-save-message"
        );

        const reviewedElement = container.querySelector(
            "#hr-reviewed-count"
        );

        const totalElement = container.querySelector(
            "#hr-total-applicants"
        );

        const remainingElement = container.querySelector(
            "#hr-remaining-count"
        );

        const percentageElement = container.querySelector(
            "#hr-progress-percentage"
        );

        const progressFill = container.querySelector(
            "#hr-progress-fill"
        );

        if (
            !table ||
            !filter ||
            !saveButton ||
            !saveMessage ||
            !reviewedElement ||
            !totalElement ||
            !remainingElement ||
            !percentageElement ||
            !progressFill
        ) {
            console.error(
                "One or more HR portal elements are missing."
            );
            return;
        }

        container.dataset.initialized = "true";

        const studentRows = table.querySelectorAll(
            ".student-row"
        );

        const detailRows = table.querySelectorAll(
            ".student-details"
        );

        const viewButtons = table.querySelectorAll(
            ".view-button"
        );

        // Track reviews during this page session
        const reviewedStudents = new Set();


        /* =====================================
           FILTER STUDENTS AND COLUMNS
        ===================================== */

        function filterStudents() {

            const selectedType = filter.value;

            // Show only matching students
            studentRows.forEach(function (row) {

                const matches = row.classList.contains(
                    selectedType
                );

                row.style.display = matches
                    ? "table-row"
                    : "none";

                row.classList.remove("selected");
            });

            // Close all application details
            detailRows.forEach(function (row) {
                row.style.display = "none";
            });

            // Advisor column only for PhD
            table.querySelectorAll(".phd-only")
                .forEach(function (cell) {

                    cell.style.display =
                        selectedType === "phd"
                            ? ""
                            : "none";
                });

            // Count visible table headers
            const visibleColumns = Array.from(
                table.querySelectorAll("thead th")
            ).filter(function (th) {

                return window.getComputedStyle(th)
                    .display !== "none";

            }).length;

            // Adjust detail row width
            detailRows.forEach(function (row) {

                const cell = row.querySelector("td");

                if (cell) {
                    cell.colSpan = visibleColumns;
                }
            });

            // Reset View buttons
            viewButtons.forEach(function (button) {
                button.textContent = "View";
            });

        }

        filter.addEventListener(
            "change",
            filterStudents
        );


        /* =====================================
           VIEW APPLICATION DETAILS
        ===================================== */

        viewButtons.forEach(function (button) {

            button.addEventListener(
                "click",
                function () {

                    const row = button.closest(
                        ".student-row"
                    );

                    if (!row) return;

                    const details = row.nextElementSibling;

                    if (
                        !details ||
                        !details.classList.contains(
                            "student-details"
                        )
                    ) {
                        return;
                    }

                    const wasOpen =
                        details.style.display === "table-row";

                    // Close other details
                    detailRows.forEach(function (item) {
                        item.style.display = "none";
                    });

                    // Remove row highlights
                    studentRows.forEach(function (item) {
                        item.classList.remove("selected");
                    });

                    // Reset button text
                    viewButtons.forEach(function (item) {
                        item.textContent = "View";
                    });

                    // Open selected application
                    if (!wasOpen) {

                        details.style.display = "table-row";

                        row.classList.add("selected");

                        button.textContent = "Hide";
                    }
                }
            );
        });


        /* =====================================
           UPDATE PROGRESS BAR
        ===================================== */

        function updateProgress() {

            const total = Number(
                totalElement.textContent
            );

            const reviewed = Number(
                reviewedElement.textContent
            );

            const remaining = Math.max(
                0,
                total - reviewed
            );

            const percentage = total > 0
                ? Math.min(
                    100,
                    (reviewed / total) * 100
                )
                : 0;

            remainingElement.textContent =
                remaining + " Remaining";

            percentageElement.textContent =
                percentage.toFixed(1) +
                "% Completed";

            progressFill.style.width =
                percentage + "%";
        }


        /* =====================================
           SAVE HR DECISIONS (DEMO)
        ===================================== */

        saveButton.addEventListener(
            "click",
            function () {

                let newReviews = 0;

                studentRows.forEach(function (row, index) {

                    const selectedDecision =
                        row.querySelector(
                            'input[type="radio"]:checked'
                        );

                    if (
                        selectedDecision &&
                        !reviewedStudents.has(index)
                    ) {

                        reviewedStudents.add(index);

                        newReviews++;
                    }
                });

                if (newReviews > 0) {

                    const currentReviewed = Number(
                        reviewedElement.textContent
                    );

                    const total = Number(
                        totalElement.textContent
                    );

                    reviewedElement.textContent =
                        Math.min(
                            currentReviewed + newReviews,
                            total
                        );

                    updateProgress();

                    saveMessage.textContent =
                        newReviews +
                        " application decision(s) recorded " +
                        "for this demonstration.";

                    saveMessage.style.color = "#2271b1";

                } else {

                    saveMessage.textContent =
                        "No new application decisions to record.";

                    saveMessage.style.color = "#666";
                }
            }
        );


        /* =====================================
           INITIALIZE PAGE
        ===================================== */

        filterStudents();

        updateProgress();

        console.log(
            "HR portal initialized successfully."
        );
    }


    // Initialize once DOM is ready
    if (document.readyState === "loading") {

        document.addEventListener(
            "DOMContentLoaded",
            initializeHRPortal,
            { once: true }
        );

    } else {

        initializeHRPortal();
    }

})();
