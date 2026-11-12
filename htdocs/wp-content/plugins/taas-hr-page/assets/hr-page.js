(function () {
    "use strict";

    const REQUEST_TIMEOUT_MS = 30000;
    const countryNames = typeof Intl.DisplayNames === "function"
        ? new Intl.DisplayNames(["en"], { type: "region" })
        : null;

    function countryName(code) {
        return countryNames && typeof code === "string" && /^[A-Z]{2}$/.test(code)
            ? countryNames.of(code)
            : code;
    }

    async function request(endpoint, { signal, decisions } = {}) {
        const nonce = window.TAASHrPage?.nonce;
        if (!endpoint || !nonce) {
            throw new Error("Missing HR request configuration.");
        }

        const controller = new AbortController();
        const cancel = () => controller.abort();
        if (signal?.aborted) {
            cancel();
        }
        signal?.addEventListener("abort", cancel, { once: true });
        const timeout = setTimeout(cancel, REQUEST_TIMEOUT_MS);
        try {
            const response = await fetch(endpoint, {
                method: decisions ? "POST" : "GET",
                credentials: "same-origin",
                cache: "no-store",
                signal: controller.signal,
                headers: { "X-WP-Nonce": nonce, ...(decisions ? { "Content-Type": "application/json" } : {}) },
                body: decisions ? JSON.stringify({ decisions }) : undefined
            });
            return { response, data: await response.json() };
        } finally {
            clearTimeout(timeout);
            signal?.removeEventListener("abort", cancel);
        }
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
        const dialog = review.querySelector("#hr-application-details");
        if (!filter || !status || !rows || !template || !dialog) {
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
            if (!response.ok || !validTerm(data) || !Array.isArray(data.applicants) || !validProgress(data.progress)) {
                throw new Error("Invalid applicant list response.");
            }
            cycleTerm.textContent = `${data.term} ${data.year}`;
            renderProgress(review, data.progress);
            if (data.applicants.length === 0) {
                status.textContent = "No applications have been submitted for this cycle.";
                return;
            }

            const types = { undergrad: "Undergraduate", masters: "Master’s", phd: "PhD" };
            const entries = data.applicants.map((applicant, index) => {
                const { submission_id, name, student_type, gpa, english_test_score, country_code, advisor_name, decision, version } = applicant;
                const fragment = template.content.cloneNode(true);
                const row = fragment.firstElementChild;
                row.querySelector("[data-name]").textContent = name ?? "—";
                row.querySelector("[data-student-type]").textContent = types[student_type] ?? "—";
                row.querySelector("[data-gpa]").textContent = gpa ?? "—";
                row.querySelector("[data-score]").textContent = english_test_score ?? "—";
                row.querySelector("[data-country]").textContent = countryName(country_code) ?? "—";
                row.querySelector(".phd-only").textContent = advisor_name || "—";
                const button = row.querySelector(".view-button");
                const radios = Array.from(row.querySelectorAll('input[type="radio"]'));
                for (const radio of radios) {
                    radio.name = `hr-decision-${index}`;
                    radio.checked = radio.value === decision;
                }
                rows.append(fragment);
                return { row, button, name, type: student_type, submissionId: submission_id, decision, version, radios };
            });

            const title = dialog.querySelector("h3");
            let detailsRequest;
            dialog.addEventListener("close", () => {
                if (!dialog.open) {
                    detailsRequest?.abort();
                    dialog.querySelector("[data-details-content]").replaceChildren();
                }
            });
            for (const entry of entries) {
                entry.button.addEventListener("click", () => {
                    detailsRequest?.abort();
                    detailsRequest = new AbortController();
                    title.textContent = entry.name ? `${entry.name} – Application` : "Application details";
                    entry.button.focus();
                    dialog.showModal();
                    loadApplicantDetails(entry.submissionId, dialog, review, cycleStatus, detailsRequest.signal);
                });
            }

            function filterApplicants() {
                const hideAdvisor = filter.value === "masters" || filter.value === "undergrad";
                table.classList.toggle("hide-advisor", hideAdvisor);
                let hasMatches = false;
                for (const entry of entries) {
                    entry.row.hidden = filter.value !== "all" && entry.type !== filter.value;
                    hasMatches ||= !entry.row.hidden;
                }
                table.hidden = !hasMatches;
                status.textContent = hasMatches ? "" : "No applicants match this student type.";
            }

            filterApplicants();
            filter.disabled = false;
            filter.addEventListener("change", filterApplicants);
            initializeReviewSave(review, entries, cycleStatus, dialog);
        } catch {
            status.textContent = "The applicant list could not be loaded. Reload the page to try again.";
        }
    }

    async function loadApplicantDetails(submissionId, dialog, review, cycleStatus, signal) {
        const status = dialog.querySelector("[data-details-status]");
        const content = dialog.querySelector("[data-details-content]");
        status.textContent = "Loading application details…";
        content.replaceChildren();

        try {
            const template = window.TAASHrPage.applicantEndpoint;
            if (typeof submissionId !== "string" || !/^[1-9][0-9]*$/.test(submissionId) || !template) {
                throw new Error("Invalid applicant detail request.");
            }
            const endpoint = template.replace("__submission_id__", submissionId);
            const { response, data } = await request(endpoint, { signal });
            if (signal.aborted) {
                return;
            }
            if (response.status === 403 && data?.code === "taas_stage_closed") {
                dialog.close();
                review.hidden = true;
                cycleStatus.textContent = "HR review is closed.";
                return;
            }
            if (response.status === 404 && data?.code === "taas_not_found") {
                status.textContent = "This application is no longer available in the current cycle. Reload the page to refresh the list.";
                return;
            }
            if (!response.ok || !data?.details?.profile || !Array.isArray(data.details.academic_background)) {
                throw new Error("Invalid applicant detail response.");
            }
            renderApplicantDetails(content, data.details);
            status.textContent = content.childElementCount ? "" : "No application details are available.";
        } catch {
            if (!signal.aborted) {
                status.textContent = "Application details could not be loaded. Close and reopen this application to try again.";
            }
        }
    }

    function renderApplicantDetails(content, details) {
        const { student_name, student_email, profile, academic_background } = details;
        const enrollment = ["Not enrolled", "Undergraduate", "Master’s", "PhD"];
        const eap = [null, "Taken", "Currently enrolled", "Not yet taken", "N/A"];
        const english = [null, "Under 45 SPEAK / Under 23 TOEFL iBT", "45–54 SPEAK / 23–27 TOEFL iBT", "55–60 SPEAK / 28–30 TOEFL iBT", "N/A"];
        const degrees = [null, "AA", "BS", "MS", "PhD"];
        const fws = { yes: "Yes", no: "No", not_applicable: "N/A" };
        const undergraduateFws = profile.enrollment_status === 1 ? [
            ["Federal Work-Study eligibility", fws[profile.fws_eligibility]],
            ["FWS award letter", profile.fws_award_letter_url, true]
        ] : [];

        appendDetailGroup(content, "Student information", [
            ["Name", student_name],
            ["Email", student_email],
            ["Phone", profile.phone],
            ["Country of origin", countryName(profile.country_code)],
            ["Admitted semester", profile.admitted_semester],
            ["Enrollment", enrollment[profile.enrollment_status]],
            ["Available hours per week", profile.available_hours],
            ["SPEAK / TOEFL iBT score", english[profile.english_test_score]],
            ["EAP 5836", eap[profile.eap5836_status]],
            ["EAP 5837", eap[profile.eap5837_status]],
            ["Advisor name", profile.enrollment_status === 3 ? profile.advisor_name || "N/A" : null],
            ...undergraduateFws,
            ["Résumé", profile.resume_url, true],
            ["Additional information", profile.additional_info]
        ]);
        academic_background.forEach((degree, index) => {
            appendDetailGroup(content, academic_background.length === 1 ? "Academic background" : `Academic background ${index + 1}`, [
                ["University", degree.university],
                ["Degree", degrees[degree.degree_type]],
                ["Major", degree.major],
                ["Minor", degree.minor],
                ["Graduation month", typeof degree.grad_date === "string" ? degree.grad_date.slice(0, 7) : null],
                ["GPA", degree.gpa]
            ]);
        });
    }

    function appendDetailGroup(content, title, fields) {
        const list = document.createElement("dl");
        for (const [label, value, isLink] of fields) {
            if ((typeof value !== "string" && typeof value !== "number") || value === "") {
                continue;
            }
            const term = document.createElement("dt");
            const description = document.createElement("dd");
            term.textContent = label;
            description.textContent = value;
            const url = isLink ? applicationLink(value) : null;
            if (url) {
                const link = document.createElement("a");
                link.href = url.href;
                link.target = "_blank";
                link.rel = "noopener noreferrer";
                link.textContent = value;
                description.replaceChildren(link);
            }
            list.append(term, description);
        }
        if (list.childElementCount) {
            const heading = document.createElement("h4");
            heading.textContent = title;
            content.append(heading, list);
        }
    }

    function applicationLink(value) {
        try {
            const url = new URL(value);
            return url.protocol === "https:" || url.protocol === "http:" ? url : null;
        } catch {
            return null;
        }
    }

    function validProgress(progress) {
        return progress && [progress.total, progress.reviewed, progress.approved].every(Number.isSafeInteger)
            && progress.total >= progress.reviewed && progress.reviewed >= progress.approved && progress.approved >= 0;
    }

    function renderProgress(review, { total, reviewed, approved }) {
        const percent = total > 0 ? (reviewed / total) * 100 : 0;
        review.querySelector("#hr-total-applicants").textContent = total;
        review.querySelector("#hr-reviewed-count").textContent = reviewed;
        review.querySelector("#hr-approved-count").textContent = approved;
        review.querySelector("#hr-remaining-count").textContent = `${total - reviewed} Remaining`;
        review.querySelector("#hr-progress-percentage").textContent = `${percent.toFixed(1)}% Completed`;
        review.querySelector("#hr-progress-fill").style.width = `${percent}%`;
        review.querySelector(".hr-progress-bar").setAttribute("aria-valuenow", percent.toFixed(1));
    }

    function applySavedDecision(entry, saved) {
        entry.decision = saved.decision;
        entry.version = saved.version;
        for (const radio of entry.radios) {
            radio.checked = radio.value === saved.decision;
        }
    }

    function initializeReviewSave(review, entries, cycleStatus, dialog) {
        const saveButton = review.querySelector(".save-button");
        const message = review.querySelector("#hr-save-message");
        const byId = new Map(entries.map(entry => [entry.submissionId, entry]));
        const radios = entries.flatMap(entry => entry.radios);

        function pendingDecisions() {
            const decisions = [];
            for (const entry of entries) {
                const selected = entry.radios.find(radio => radio.checked)?.value;
                if (selected && selected !== entry.decision) {
                    decisions.push({ submission_id: entry.submissionId, decision: selected, version: entry.version });
                }
            }
            return decisions;
        }

        function updateSubmitButton() {
            const count = pendingDecisions().length;
            saveButton.textContent = count > 1 ? `Submit ${count} Applications` : "Submit Decision";
            saveButton.disabled = count === 0;
        }

        updateSubmitButton();
        radios.forEach(radio => radio.addEventListener("change", updateSubmitButton));
        saveButton.addEventListener("click", async () => {
            const decisions = pendingDecisions();
            if (!decisions.length) {
                return;
            }

            saveButton.disabled = true;
            radios.forEach(radio => { radio.disabled = true; });
            message.textContent = "Saving decisions…";
            try {
                const { response, data } = await request(window.TAASHrPage.decisionsEndpoint, { decisions });
                if (response.status === 403 && data?.code === "taas_stage_closed") {
                    dialog.close();
                    review.hidden = true;
                    cycleStatus.textContent = "HR review is closed.";
                    return;
                }
                if (response.status === 404 && data?.code === "taas_not_found") {
                    message.textContent = "An application is no longer available in this cycle. Reload the page to refresh the list.";
                    return;
                }
                if (response.status === 409 && data?.code === "taas_review_conflict" && Array.isArray(data.conflicts) && validProgress(data.progress)) {
                    const names = [];
                    for (const saved of data.conflicts) {
                        const entry = byId.get(saved.submission_id);
                        applySavedDecision(entry, saved);
                        const label = { approve: "Approved", reject: "Rejected" }[saved.decision] ?? "Unreviewed";
                        entry.row.querySelector("[data-review-conflict]").textContent = `Current saved decision: ${label}. Review it before making a new selection.`;
                        names.push(`${entry.name || `Submission ${entry.submissionId}`} (${label})`);
                    }
                    renderProgress(review, data.progress);
                    message.textContent = `No decisions were saved. Review the latest decision for ${names.join(", ")} before saving again.`;
                    return;
                }
                if (!response.ok || !Array.isArray(data?.reviews) || !validProgress(data.progress)) {
                    throw new Error("Invalid review save response.");
                }
                for (const saved of data.reviews) {
                    const entry = byId.get(saved.submission_id);
                    applySavedDecision(entry, saved);
                    entry.row.querySelector("[data-review-conflict]").textContent = "";
                }
                renderProgress(review, data.progress);
                message.textContent = decisions.length === 1 ? "Decision saved." : `${decisions.length} decisions saved.`;
            } catch {
                message.textContent = "The save could not be confirmed. Try again to check the latest decisions.";
            } finally {
                radios.forEach(radio => { radio.disabled = false; });
                updateSubmitButton();
            }
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
