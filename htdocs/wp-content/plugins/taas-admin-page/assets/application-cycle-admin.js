(() => {
    "use strict";

    const form = document.querySelector("#admin-cycle-form");
    if (!form) return;

    const home = document.querySelector("#admin-cycle-home");
    const homeUrl = window.TAASApplicationCycle?.homeUrl;
    if (homeUrl) {
        home.href = homeUrl;
        home.hidden = false;
    }

    const modeButtons = document.querySelectorAll("[data-cycle-mode]");
    const newTerm = form.querySelector("#admin-cycle-new-term");
    const heading = form.querySelector("#admin-cycle-form-title");
    const check = form.querySelector("#admin-cycle-check");
    const save = form.querySelector("#admin-cycle-save");
    const status = form.querySelector("#admin-cycle-status");
    const fields = Array.from(form.querySelectorAll("input, select"));
    const stages = Array.from(form.querySelectorAll("[data-cycle-stage]"), stage => ({
        start: stage.querySelector("[data-start]"),
        end: stage.querySelector("[data-end]"),
    }));
    const drafts = new Map();
    let mode = "edit";

    function invalidateCheck() {
        save.hidden = true;
        status.textContent = "";
        for (const field of fields) field.setCustomValidity("");
    }

    for (const button of modeButtons) {
        button.addEventListener("click", () => {
            const nextMode = button.dataset.cycleMode;
            if (nextMode === mode) return;

            drafts.set(mode, fields.map(field => field.value));
            const draft = drafts.get(nextMode);
            fields.forEach((field, index) => {
                field.value = draft?.[index] ?? "";
            });

            mode = nextMode;
            const startingNew = mode === "new";
            for (const option of modeButtons) {
                option.setAttribute("aria-pressed", String(option === button));
            }
            newTerm.hidden = !startingNew;
            newTerm.disabled = !startingNew;
            heading.textContent = startingNew ? "New Cycle Schedule" : "Stage Schedule";
            invalidateCheck();
        });
    }

    form.addEventListener("input", invalidateCheck);

    form.addEventListener("submit", event => {
        event.preventDefault();
        save.hidden = true;
        status.textContent = "";
        const scheduled = [];
        for (const { start, end } of stages) {
            if (!start.value || !end.value) continue;
            if (end.valueAsNumber < start.valueAsNumber) {
                end.setCustomValidity("Choose an end date on or after the start date.");
                end.reportValidity();
                return;
            }
            scheduled.push({ start, end });
        }

        scheduled.sort((a, b) => a.start.valueAsNumber - b.start.valueAsNumber);
        for (let index = 1; index < scheduled.length; index++) {
            const { start } = scheduled[index];
            if (start.valueAsNumber <= scheduled[index - 1].end.valueAsNumber) {
                start.setCustomValidity("This stage overlaps another stage. Choose a different start time.");
                start.reportValidity();
                return;
            }
        }

        const term = mode === "new"
            ? `${form.elements.term.value} ${form.elements.year.value}`
            : document.querySelector("#admin-cycle-current-term").textContent;
        save.hidden = false;
        status.textContent = `Stage dates check passed for ${term}.`;
    });

    save.addEventListener("click", () => {
        if (!save.hidden) status.textContent = "This is a preview. Changes were not saved.";
    });

    check.disabled = false;
})();
