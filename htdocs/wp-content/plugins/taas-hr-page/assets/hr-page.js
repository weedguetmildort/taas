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

    function normalizeSearch(text) {
        return text.normalize("NFD").replace(/\p{M}/gu, "").trim().toLowerCase();
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
        const decisionFilter = review.querySelector("#hr-decision-filter");
        const search = review.querySelector("#hr-applicant-search");
        const status = review.querySelector("[data-hr-applicants-status]");
        const table = review.querySelector("[data-hr-applicants-table]");
        const rows = table?.querySelector("tbody");
        const template = review.querySelector("[data-hr-applicant-row]");
        const dialog = review.querySelector("#hr-application-details");
        if (!filter || !decisionFilter || !search || !status || !rows || !template || !dialog) {
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
                const { submission_id, name, student_type, gpa, english_test_score, country_code, advisor_name, decision, version, comment } = applicant;
                const fragment = template.content.cloneNode(true);
                const [row, commentRow] = fragment.children;
                row.querySelector("[data-name]").textContent = name ?? "—";
                row.querySelector("[data-student-type]").textContent = types[student_type] ?? "—";
                row.querySelector("[data-gpa]").textContent = gpa ?? "—";
                row.querySelector("[data-score]").textContent = english_test_score ?? "—";
                row.querySelector("[data-country]").textContent = countryName(country_code) ?? "—";
                row.querySelector(".phd-only").textContent = advisor_name || "—";
                const button = row.querySelector(".view-button");
                const radios = Array.from(row.querySelectorAll('input[type="radio"]'));
                const commentToggle = row.querySelector(".hr-comment-toggle");
                const commentPanel = commentRow.querySelector(".hr-comment-panel");
                const commentInput = commentPanel.querySelector("textarea");
                commentPanel.id = `hr-comment-panel-${index}`;
                commentToggle.setAttribute("aria-controls", commentPanel.id);
                commentInput.value = comment ?? "";
                for (const radio of radios) {
                    radio.name = `hr-decision-${index}`;
                    radio.checked = radio.value === decision;
                }
                const entry = { row, button, name, searchName: normalizeSearch(row.querySelector("[data-name]").textContent), type: student_type, submissionId: submission_id, decision, version, radios, commentRow, commentToggle, commentInput, comment: comment ?? null };
                commentToggle.addEventListener("click", () => {
                    setCommentOpen(entry, commentToggle.getAttribute("aria-expanded") === "false");
                    if (!commentRow.hidden) {
                        commentInput.focus();
                    }
                });
                setCommentOpen(entry, false);
                return entry;
            });

            function orderApplicants() {
                const ordered = [...entries].sort((first, second) =>
                    Number(first.decision !== null) - Number(second.decision !== null));
                let next = rows.firstElementChild;
                for (const { row, commentRow } of ordered) {
                    for (const item of [row, commentRow]) {
                        if (item === next) {
                            next = next.nextElementSibling;
                        } else {
                            rows.insertBefore(item, next);
                        }
                    }
                }
            }

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
                const query = normalizeSearch(search.value);
                const hideAdvisor = filter.value === "masters" || filter.value === "undergrad";
                table.classList.toggle("hide-advisor", hideAdvisor);
                let hasMatches = false;
                for (const entry of entries) {
                    const matchesType = filter.value === "all" || entry.type === filter.value;
                    const matchesDecision = decisionFilter.value === "all" || (entry.decision ?? "none") === decisionFilter.value;
                    const matchesName = entry.searchName.includes(query);
                    entry.row.hidden = !matchesType || !matchesDecision || !matchesName;
                    entry.commentRow.cells[0].colSpan = hideAdvisor ? 7 : 8;
                    entry.commentRow.hidden = entry.row.hidden || entry.commentToggle.getAttribute("aria-expanded") === "false";
                    hasMatches ||= !entry.row.hidden;
                }
                table.hidden = !hasMatches;
                status.textContent = hasMatches ? "" : query
                    ? "No applicants match your search and filters."
                    : "No applicants match the selected filters.";
            }

            function refreshApplicants() {
                orderApplicants();
                filterApplicants();
            }

            refreshApplicants();
            filter.disabled = false;
            decisionFilter.disabled = false;
            search.disabled = false;
            const updateSubmitButton = initializeReviewSave(review, entries, cycleStatus, dialog, refreshApplicants);
            function onFilterChange() {
                filterApplicants();
                updateSubmitButton();
            }

            filter.addEventListener("change", onFilterChange);
            decisionFilter.addEventListener("change", onFilterChange);
            search.addEventListener("input", onFilterChange);
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

    function setCommentOpen(entry, open) {
        entry.commentToggle.setAttribute("aria-expanded", String(open));
        entry.commentToggle.textContent = open ? "Hide Comment" : entry.commentInput.value.trim() ? "Edit Comment" : "Add Comment";
        entry.commentRow.hidden = entry.row.hidden || !open;
    }

    function applySavedDecision(entry, saved) {
        entry.decision = saved.decision;
        entry.version = saved.version;
        entry.comment = saved.comment ?? null;
        entry.commentInput.value = entry.comment ?? "";
        setCommentOpen(entry, entry.commentToggle.getAttribute("aria-expanded") === "true");
        for (const radio of entry.radios) {
            radio.checked = radio.value === saved.decision;
        }
    }

    function initializeReviewSave(review, entries, cycleStatus, dialog, refreshApplicants) {
        const saveButton = review.querySelector(".save-button");
        const message = review.querySelector("#hr-save-message");
        const hiddenNotice = review.querySelector("#hr-hidden-decisions");
        const byId = new Map(entries.map(entry => [entry.submissionId, entry]));
        const radios = entries.flatMap(entry => entry.radios);
        let saving = false;

        function pendingDecisions() {
            const decisions = [];
            for (const entry of entries) {
                const selected = entry.radios.find(radio => radio.checked)?.value;
                const comment = entry.commentInput.value.trim() || null;
                if (selected && (selected !== entry.decision || comment !== entry.comment)) {
                    decisions.push({ submission_id: entry.submissionId, decision: selected, version: entry.version, comment });
                }
            }
            return decisions;
        }

        function warnUnsavedDecisions(event) {
            event.preventDefault();
            event.returnValue = true;
        }

        function updateSubmitButton() {
            const pending = pendingDecisions();
            const count = pending.length;
            const hiddenCount = pending.filter(decision => byId.get(decision.submission_id).row.hidden).length;
            saveButton.textContent = count > 1 ? `Submit ${count} Applications` : "Submit Decision";
            saveButton.disabled = saving || count === 0;
            for (const entry of entries) {
                entry.commentInput.disabled = saving || !entry.radios.some(radio => radio.checked);
                entry.commentToggle.disabled = entry.commentInput.disabled;
            }
            if (hiddenNotice) {
                hiddenNotice.textContent = hiddenCount === 0 ? "" : hiddenCount === 1
                    ? "1 pending decision is hidden by your search or filters."
                    : `${hiddenCount} pending decisions are hidden by your search or filters.`;
            }
            if (count) {
                window.addEventListener("beforeunload", warnUnsavedDecisions);
            } else {
                window.removeEventListener("beforeunload", warnUnsavedDecisions);
            }
        }

        updateSubmitButton();
        radios.forEach(radio => radio.addEventListener("change", updateSubmitButton));
        entries.forEach(entry => entry.commentInput.addEventListener("input", updateSubmitButton));
        saveButton.addEventListener("click", async () => {
            const decisions = pendingDecisions();
            if (saving || !decisions.length) {
                return;
            }

            saving = true;
            updateSubmitButton();
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
                if (!response.ok || !Array.isArray(data?.reviews) || !validProgress(data.progress)) {
                    throw new Error("Invalid review save response.");
                }
                for (const saved of data.reviews) {
                    const entry = byId.get(saved.submission_id);
                    applySavedDecision(entry, saved);
                }
                refreshApplicants();
                renderProgress(review, data.progress);
                message.textContent = decisions.length === 1 ? "Decision saved." : `${decisions.length} decisions saved.`;
            } catch {
                message.textContent = "The save could not be confirmed.";
            } finally {
                saving = false;
                radios.forEach(radio => { radio.disabled = false; });
                updateSubmitButton();
            }
        });
        return updateSubmitButton;
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
