(() => {
    "use strict";

    const form = document.querySelector("#admin-cycle-form");
    if (!form) return;

    const config = window.TAASApplicationCycle;
    const home = document.querySelector("#admin-cycle-home");
    if (config?.homeUrl) {
        home.href = config.homeUrl;
        home.hidden = false;
    }

    const loadStatus = document.querySelector("#admin-cycle-load-status");
    if (!config?.endpoint || !config.nonce) {
        loadStatus.textContent = "The cycle editor is unavailable. Reload the page to try again.";
        return;
    }

    const modes = document.querySelector(".admin-cycle-modes");
    const modeButtons = modes.querySelectorAll("[data-cycle-mode]");
    const currentTerm = document.querySelector("#admin-cycle-current-term");
    const activeStage = document.querySelector("#admin-cycle-active-stage");
    const newTerm = form.querySelector("#admin-cycle-new-term");
    const heading = form.querySelector("#admin-cycle-form-title");
    const check = form.querySelector("#admin-cycle-check");
    const save = form.querySelector("#admin-cycle-save");
    const status = form.querySelector("#admin-cycle-status");
    const fields = Array.from(form.querySelectorAll("input, select"));
    const stages = Array.from(form.querySelectorAll("[data-cycle-stage]"), stage => ({
        key: stage.dataset.cycleStage,
        label: stage.querySelector("legend").textContent,
        start: stage.querySelector("[data-start]"),
        end: stage.querySelector("[data-end]"),
    }));
    const eastern = new Intl.DateTimeFormat("en-CA", {
        timeZone: "America/New_York",
        year: "numeric", month: "2-digit", day: "2-digit",
        hour: "2-digit", minute: "2-digit", second: "2-digit",
        hourCycle: "h23",
    });
    const drafts = new Map();
    let cycle = null;
    let mode = "edit";
    let checkedDates = null;
    let saving = false;

    function easternDateTime(timestamp) {
        const parts = Object.fromEntries(eastern.formatToParts(timestamp).map(part => [part.type, part.value]));
        return `${parts.year.padStart(4, "0")}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}`;
    }

    function invalidField(field, message) {
        field.setCustomValidity(message);
        field.reportValidity();
    }

    function readDate(field, savedDate) {
        if (!field.value) return null;
        if (!/^\d{4}-/.test(field.value)) {
            invalidField(field, "Enter a date with a four-digit year.");
            return undefined;
        }

        const entered = field.value.length === 16 ? field.value + ":00" : field.value;
        if (savedDate && easternDateTime(Date.parse(savedDate)) === entered) {
            return savedDate;
        }

        // Try the offsets on either side of a possible clock change, then round-trip the input.
        const wallTime = field.valueAsNumber;
        const offsets = new Set([-86400000, 0, 86400000].map(delta => {
            const sample = wallTime + delta;
            return Date.parse(easternDateTime(sample) + "Z") - sample;
        }));
        const possible = Array.from(offsets, offset => wallTime - offset)
            .filter(timestamp => easternDateTime(timestamp) === entered);

        if (possible.length !== 1) {
            invalidField(field, possible.length
                ? "This time occurs twice in Eastern Time. Choose a time outside the repeated hour."
                : "This time does not exist in Eastern Time. Choose another time.");
            return undefined;
        }
        return new Date(possible[0]).toISOString().replace(".000Z", "Z");
    }

    function invalidateCheck() {
        checkedDates = null;
        save.hidden = true;
        status.textContent = "";
        for (const field of fields) field.setCustomValidity("");
    }

    function showMode(nextMode) {
        mode = nextMode;
        const startingNew = mode === "new";
        for (const button of modeButtons) {
            button.setAttribute("aria-pressed", String(button.dataset.cycleMode === mode));
        }
        newTerm.hidden = !startingNew;
        newTerm.disabled = !startingNew;
        heading.textContent = startingNew ? "New Cycle Schedule" : "Stage Schedule";
        invalidateCheck();
    }

    function showCycle(nextCycle) {
        cycle = nextCycle;
        currentTerm.textContent = cycle ? `${cycle.term} ${cycle.year}` : "No cycle set";
        activeStage.textContent = stages.find(stage => stage.key === cycle?.stage)?.label ?? "No active stage";
        for (const field of fields) field.value = "";
        for (const { key, start, end } of stages) {
            const dates = cycle?.stage_dates[key];
            if (dates?.start_at) start.value = easternDateTime(Date.parse(dates.start_at));
            if (dates?.end_at) end.value = easternDateTime(Date.parse(dates.end_at));
        }
        drafts.delete("edit");
        showMode(cycle ? "edit" : "new");
    }

    function setSaving(busy) {
        saving = busy;
        for (const button of modeButtons) {
            button.disabled = busy || (button.dataset.cycleMode === "edit" && !cycle);
        }
        for (const field of fields) field.disabled = busy;
        check.disabled = busy;
        save.disabled = busy;
    }

    async function requestCycle(method = "GET", body) {
        const headers = { "X-WP-Nonce": config.nonce };
        const options = { method, headers, cache: "no-store" };
        if (body) {
            headers["Content-Type"] = "application/json";
            options.body = JSON.stringify(body);
        }
        const response = await fetch(config.endpoint, options);
        const data = await response.json().catch(() => null);
        if (method === "GET" && response.status === 404 && data?.code === "taas_no_current_cycle") {
            return null;
        }
        if (!response.ok || !data?.stage_dates) {
            throw new Error(data?.data?.params?.stage_dates || data?.message || "The cycle request failed.");
        }
        return data;
    }

    for (const button of modeButtons) {
        button.addEventListener("click", () => {
            const nextMode = button.dataset.cycleMode;
            if (saving || nextMode === mode) return;

            drafts.set(mode, fields.map(field => field.value));
            const draft = drafts.get(nextMode);
            fields.forEach((field, index) => {
                field.value = draft?.[index] ?? "";
            });
            showMode(nextMode);
        });
    }

    form.addEventListener("input", invalidateCheck);
    form.addEventListener("change", invalidateCheck);

    form.addEventListener("submit", event => {
        event.preventDefault();
        if (saving) return;
        invalidateCheck();

        const stageDates = {};
        const scheduled = [];
        for (const { key, start, end } of stages) {
            const saved = mode === "edit" ? cycle.stage_dates[key] : null;
            const startAt = readDate(start, saved?.start_at);
            if (startAt === undefined) return;
            const endAt = readDate(end, saved?.end_at);
            if (endAt === undefined) return;
            stageDates[key] = startAt || endAt ? { start_at: startAt, end_at: endAt } : null;

            if (!startAt || !endAt) continue;
            const startsAt = Date.parse(startAt);
            const endsAt = Date.parse(endAt);
            if (endsAt < startsAt) {
                invalidField(end, "Choose an end date on or after the start date.");
                return;
            }
            scheduled.push({ start, startsAt, endsAt });
        }

        scheduled.sort((a, b) => a.startsAt - b.startsAt);
        for (let index = 1; index < scheduled.length; index++) {
            const { start, startsAt } = scheduled[index];
            if (startsAt <= scheduled[index - 1].endsAt) {
                invalidField(start, "This stage overlaps another stage. Choose a different start time.");
                return;
            }
        }

        checkedDates = stageDates;
        save.hidden = false;
        const term = mode === "new"
            ? `${form.elements.term.value} ${form.elements.year.value}`
            : currentTerm.textContent;
        status.textContent = `Stage dates check passed for ${term}.`;
    });

    save.addEventListener("click", async () => {
        if (saving || !checkedDates || save.hidden) return;

        const startingNew = mode === "new";
        const stageDates = startingNew ? checkedDates : Object.fromEntries(
            Object.entries(checkedDates).filter(([key, dates]) =>
                dates?.start_at !== cycle.stage_dates[key]?.start_at ||
                dates?.end_at !== cycle.stage_dates[key]?.end_at
            )
        );
        if (!startingNew && !Object.keys(stageDates).length) {
            status.textContent = "No stage dates have changed.";
            return;
        }

        const body = { stage_dates: stageDates };
        if (startingNew) {
            body.term = form.elements.term.value;
            body.year = form.elements.year.valueAsNumber;
        }
        setSaving(true);
        status.textContent = "Saving changes…";

        try {
            const latest = await requestCycle();
            if (latest?.term !== cycle?.term || latest?.year !== cycle?.year ||
                JSON.stringify(latest?.stage_dates) !== JSON.stringify(cycle?.stage_dates)) {
                throw new Error("The current cycle changed since this page was loaded. Reload the page before saving.");
            }

            const saved = await requestCycle(startingNew ? "PUT" : "PATCH", body);
            if (startingNew) drafts.clear();
            showCycle(saved);
            status.textContent = startingNew ? `${currentTerm.textContent} cycle started.` : "Stage dates saved.";
        } catch (error) {
            status.textContent = `Could not save changes. ${error.message}`;
        } finally {
            setSaving(false);
        }
    });

    async function initialize() {
        try {
            showCycle(await requestCycle());
            loadStatus.textContent = "";
            setSaving(false);
            modes.hidden = false;
            form.hidden = false;
        } catch (error) {
            loadStatus.textContent = `Could not load the current cycle. ${error.message}`;
        }
    }

    initialize();
})();
