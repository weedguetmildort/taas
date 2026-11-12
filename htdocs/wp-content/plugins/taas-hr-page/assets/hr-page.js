(function () {
    "use strict";

    async function request(endpoint) {
        const nonce = window.TAASHrPage?.nonce;
        if (!endpoint || !nonce) {
            throw new Error("Missing HR request configuration.");
        }

        const response = await fetch(endpoint, {
            credentials: "same-origin",
            cache: "no-store",
            headers: { "X-WP-Nonce": nonce }
        });
        return { response, data: await response.json() };
    }

    function validTerm(data) {
        return typeof data?.term === "string" && data.term.trim() && Number.isInteger(data.year);
    }

    async function loadCycle(term, status) {
        try {
            const { response, data: cycle } = await request(window.TAASHrPage?.cycleEndpoint);
            if (response.status === 404 && cycle?.code === "taas_no_current_cycle") {
                status.textContent = "No application cycle has been set.";
                return false;
            }
            if (!response.ok || !validTerm(cycle) ||
                (cycle.stage !== null && typeof cycle.stage !== "string")) {
                throw new Error("Invalid cycle response.");
            }

            term.textContent = `${cycle.term} ${cycle.year}`;
            const isOpen = cycle.stage === "hr_review";
            status.textContent = isOpen ? "HR review is open." : "HR review is closed.";
            return isOpen;
        } catch {
            status.textContent = "The current application cycle could not be loaded. Reload the page to try again.";
            return false;
        }
    }

    async function initializeHRPortal() {

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

        container.dataset.initialized = "true";

        const review = container.querySelector("[data-hr-review]");
        const cycleTerm = container.querySelector("[data-hr-cycle-term]");
        const cycleStatus = container.querySelector("[data-hr-cycle]");
        if (!review || !cycleTerm || !cycleStatus) {
            return;
        }

        if (await loadCycle(cycleTerm, cycleStatus)) {
            await loadApplicants(review, cycleTerm, cycleStatus);
        }
    }

    async function loadApplicants(review, cycleTerm, cycleStatus) {
        const filter = review.querySelector("#student-type-filter");
        const status = review.querySelector("[data-hr-applicants-status]");
        const table = review.querySelector("[data-hr-applicants-table]");
        const rows = table?.querySelector("tbody");
        const template = review.querySelector("[data-hr-applicant-row]");
        if (!filter || !status || !rows || !template) {
            return;
        }

        review.hidden = false;

        try {
            const { response, data } = await request(window.TAASHrPage.applicantsEndpoint);
            if (response.status === 403 && data?.code === "taas_stage_closed") {
                review.hidden = true;
                cycleStatus.textContent = "HR review is closed.";
                return;
            }
            if (!response.ok || !validTerm(data) || !Array.isArray(data.applicants)) {
                throw new Error("Invalid applicant list response.");
            }
            cycleTerm.textContent = `${data.term} ${data.year}`;
            if (data.applicants.length === 0) {
                status.textContent = "No applications have been submitted for this cycle.";
                return;
            }

            const types = { undergrad: "Undergraduate", masters: "Master’s", phd: "PhD" };
            const countryNames = typeof Intl.DisplayNames === "function"
                ? new Intl.DisplayNames(["en"], { type: "region" })
                : null;

            const entries = data.applicants.map((applicant, index) => {
                const { name, student_type, gpa, english_test_score, country_code } = applicant;
                const country = countryNames && /^[A-Z]{2}$/.test(country_code)
                    ? countryNames.of(country_code)
                    : country_code;
                const fragment = template.content.cloneNode(true);
                const [row, details] = fragment.children;
                row.querySelector("[data-name]").textContent = name ?? "—";
                row.querySelector("[data-student-type]").textContent = types[student_type] ?? "—";
                row.querySelector("[data-gpa]").textContent = gpa ?? "—";
                row.querySelector("[data-score]").textContent = english_test_score ?? "—";
                row.querySelector("[data-country]").textContent = country ?? "—";
                details.querySelector("[data-details-title]").textContent = name ? `${name} – Application` : "Application";
                details.id = `hr-applicant-details-${index}`;

                const button = row.querySelector(".view-button");
                button.setAttribute("aria-controls", details.id);
                for (const radio of row.querySelectorAll('input[type="radio"]')) {
                    radio.name = `hr-demo-decision-${index}`;
                }
                rows.append(fragment);
                return { row, details, button, type: student_type };
            });

            for (const entry of entries) {
                entry.button.addEventListener("click", () => {
                    const wasClosed = entry.details.hidden;
                    for (const item of entries) {
                        setDetailsOpen(item, item === entry && wasClosed);
                    }
                });
            }

            const columnCount = table.querySelectorAll("th").length;
            function filterApplicants() {
                const hideAdvisor = filter.value === "masters" || filter.value === "undergrad";
                const visibleColumns = hideAdvisor ? columnCount - 1 : columnCount;
                table.classList.toggle("hide-advisor", hideAdvisor);
                let hasMatches = false;
                for (const entry of entries) {
                    entry.row.hidden = filter.value !== "all" && entry.type !== filter.value;
                    entry.details.cells[0].colSpan = visibleColumns;
                    setDetailsOpen(entry, false);
                    hasMatches ||= !entry.row.hidden;
                }
                table.hidden = !hasMatches;
                status.textContent = hasMatches ? "" : "No applicants match this student type.";
            }

            filterApplicants();
            filter.disabled = false;
            filter.addEventListener("change", filterApplicants);
            initializeDemoSave(review, entries);
        } catch {
            status.textContent = "The applicant list could not be loaded. Reload the page to try again.";
        }
    }

    function setDetailsOpen(entry, isOpen) {
        entry.details.hidden = !isOpen;
        entry.row.classList.toggle("selected", isOpen);
        entry.button.setAttribute("aria-expanded", String(isOpen));
        entry.button.textContent = isOpen ? "Hide" : "View";
    }

    function initializeDemoSave(review, entries) {
        const saveButton = review.querySelector(".save-button");
        const message = review.querySelector("#hr-save-message");
        const reviewed = review.querySelector("#hr-reviewed-count");
        const total = review.querySelector("#hr-total-applicants");
        const remaining = review.querySelector("#hr-remaining-count");
        const percentage = review.querySelector("#hr-progress-percentage");
        const progress = review.querySelector("#hr-progress-fill");
        if (!saveButton || !message || !reviewed || !total || !remaining || !percentage || !progress) {
            return;
        }

        const demoReviews = new Set();
        const initialReviewed = Number(reviewed.textContent);
        const demoTotal = Number(total.textContent);
        saveButton.disabled = false;
        saveButton.addEventListener("click", () => {
            const previousCount = demoReviews.size;
            for (const { row } of entries) {
                if (row.querySelector('input[type="radio"]:checked')) {
                    demoReviews.add(row);
                }
            }
            const newReviews = demoReviews.size - previousCount;
            if (newReviews === 0) {
                message.textContent = "No new application decisions to record.";
                return;
            }

            const reviewedCount = Math.min(initialReviewed + demoReviews.size, demoTotal);
            const percent = demoTotal > 0 ? (reviewedCount / demoTotal) * 100 : 0;
            reviewed.textContent = reviewedCount;
            remaining.textContent = `${demoTotal - reviewedCount} Remaining`;
            percentage.textContent = `${percent.toFixed(1)}% Completed`;
            progress.style.width = `${percent}%`;
            message.textContent = `${newReviews} application decision(s) recorded for this demonstration.`;
        });
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
