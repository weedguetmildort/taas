# Student application API

Initial submission and student-owned reads live in taas-core. Existing generic
admin endpoints keep their permissions. Routes require ROLE_STUDENT under the
current TAAS_Auth single-role contract, plus an eight-digit university_id.
Production SSO-to-UFID mapping must be verified before release. WordPress cookie
authentication requires the normal REST nonce; a page access check is not enough.

## Provisional application-period contract

Team 2 must confirm this contract and provide its admin interface. No term or
deadline is selected automatically. The WordPress option `taas_application_period`
currently has this shape (values below are illustrative, not deployed settings):

```php
array(
    'semester'  => 2,    // TAAS_Semester: Spring.
    'year'      => 2027,
    'is_open'   => true, // Explicit manual open/closed state.
    'closes_at' => null, // Optional Unix UTC timestamp; no grace period.
)
```

Missing/invalid configuration returns 503 on form loading. A false manual open
flag or a passed deadline closes applications. The effective state and selected
term are checked again when saving. Override/reopening policies remain deferred.
These values are server-owned; no student endpoint writes configuration.

## Routes

| Method | Route under `/wp-json/taas/v1` | Result |
|---|---|---|
| GET | `/student/application` | Configured period, authenticated UFID/email, saved profile/background, already-applied flag, all courses in configured term |
| POST | `/student/application` | Initial submission; 201 on create, 200 for an identical pending retry |
| GET | `/student/applications` | Own submitted academic terms, without loading every JSON snapshot |
| GET | `/student/applications/{semester}/{year}` | Own saved term details and course applications; 404 if no snapshot exists |
| POST/PATCH/PUT | `/student/applications/{semester}/{year}` | Explicit updates to an existing term; requires the revision token returned by GET |

Student-specific successful reads are marked no-store. Course response fields:
course_id, course_code, course_name, instructor_name. Class days/times are not in
the current course schema and are not fabricated.

## Initial submission body

```json
{
  "term": { "semester": 2, "year": 2027 },
  "details": {
    "student_name": "Alex Smith",
    "student_email": "alex@ufl.edu",
    "profile": {
      "phone": "3525550100",
      "country_code": "US",
      "admitted_semester": "Fall 2025",
      "available_hours": 10,
      "enrollment_status": 3,
      "eap5836_status": 1,
      "eap5837_status": 4,
      "resume_url": null,
      "english_test": { "type": "TOEFL", "score": 5.5 },
      "additional_info": ""
    },
    "academic_background": [
      {
        "university": "Example University",
        "degree_type": 2,
        "major": "Computer Science",
        "grad_date": "2025-05-01",
        "gpa": 3.5
      }
    ]
  },
  "course_preferences": [
    { "course_id": "10001", "rank": 1, "has_completed_course": true }
  ]
}
```

No owner identifier is accepted in the body; it comes from TAAS_Auth. No match
status or professor rank is accepted. Unknown fields are rejected. Strings are
bounded to storage limits, not silently truncated. `minor` is optional within a
degree. GPA must be 0–4 or explicit null for not yet available. Future graduation
dates are allowed. Current enrollment is temporarily restricted to MS=3/PhD=4.
Course equivalency declarations remain a team dependency; this contract currently
supports a completion declaration only and does not claim HR verification.

English scores are rounded half up as the temporary whole-number policy. Test
type stays in the snapshot; only TOEFL goes to the shared toefl_ibt_score column.
SPEAK is not mislabeled as TOEFL. No test date is collected or fabricated. A
revised representation remains a high-priority team question.
An existing TOEFL date is preserved when its stored score is unchanged. Changing
the score, switching test type, or removing the test clears the old TOEFL date.

## Persistence and conflicts

Server validation enforces 1–5 distinct same-term courses, unique ranks 1..N, and
self-declared completion. Identity, shared profile, current degree entries, term
snapshot, and pending course application rows save in one transaction. A student
row write serializes concurrent initial submissions for that student.

An identical normalized pending submission is treated as an already-saved retry.
A different existing submission returns 409, preserving existing preferences.
The already-applied recovery policy is still unresolved; this endpoint does not
authorize replacement or duplicates. Existing-term editing uses the separate term
route. A changed/unavailable term or closed period cannot silently save data
to another term.

Snapshots keep all validated submitted details. Current shared degree entries
are refreshed from the submitted list, while older term snapshots remain intact.
Shared profile major/GPA are preserved because there is no agreed rule selecting
one degree to populate those columns. A resume URL snapshot does not preserve
external document contents.

## Existing-term editing

GET the selected term to receive its snapshot, courses, status labels, can_edit,
and revision token. Send the same validated body shape plus `revision` to the
term-specific write route. Zero course preferences is allowed only for editing:
it withdraws all saved active choices. Removed pending rows become withdrawn;
reactivation reuses a withdrawn record as pending. Student updates touch only
student ranking, completion and permitted match status, preserving professor ranks.

The owner is always authenticated identity. Route/body term mismatch is rejected.
Writes lock the student and existing term rows and recheck the open configured
term and concurrency token. A stale token returns 409; reload saved state before
resubmitting. The token is not stored revision history. Details and course changes
save atomically, replacing only this term's snapshot.

Matching/rejection follow application closure, when student editing is disabled.
Finalized rows also remain defensively protected. Reactivating a withdrawn course
with a professor rank still requires review under the unresolved ranking policy.

The status-page layout was approved and is now connected. Select a submitted
academic term, review course/rank/status rows, and open the prefilled form below
the table. Edits stay on check-application-status and save only through Submit
Changes. Closed or guarded applications can be viewed through a read-only form.
Read-only details include pending, matched and rejected choices, retaining their
saved ranks even when withdrawals leave gaps. Withdrawn choices stay unselected.
The status page does not guess submission dates that the schema does not store.

Both pages keep their presentation in plain HTML files, including degree
templates, course containers, form controls and dialogs. PHP does not render those components. The form styles are scoped to avoid changing the
status table or WordPress theme. Separate JavaScript coordinators handle creation
and existing-term editing; each page has its own PHP setup, and the status page has a
separate controller for term selection and table refresh. Editing uses the saved
full-name string instead of guessing how to split it into first and last names.

After saving, the table and saved form reload with the new revision token.
Failures preserve entered data. A stale/closed edit requires explicitly reloading
the saved application before continuing; the UI does not silently discard edits.

## Verification

```sh
docker compose exec -T wordpress php /var/www/taas/tests/integration/student-submissions.php
docker compose exec -T wordpress php /var/www/taas/tests/integration/student-application-rest.php
docker compose exec -T wordpress php /var/www/taas/tests/integration/student-application-edits.php
docker compose exec -T wordpress php /var/www/taas/tests/integration/student-application-profile.php
```

Local fixtures are rolled back. Tests dispatch registered REST routes, exercising
permissions, term filtering, validation, closure, retries/conflicts, and atomic
rollback. Local mocked identity does not verify the production SSO/cookie path.

The initial application form is connected through the student plugin's PHP setup
and external JavaScript. Its visible controls were approved by the user. Shared
authorization protects all three student pages, and form scripts/nonces load only
on the application and status pages. WordPress-generated page/REST URLs support both
plain and pretty permalinks. The admin configuration interface remains pending;
the application period must be configured before use.

Additional HTTP verification:

```sh
docker compose exec -T wordpress php /var/www/taas/tests/integration/student-pages.php
```

Browser verification has been authorized and performed using intercepted responses
and blocked writes. The focused `tests/browser/student-application-status.cjs`
regression verifies finalized read-only preferences and normal pending edits.
The older full browser suite currently fails its final no-page-errors assertion
because the UF theme emits invalid navigation JavaScript; that separate issue remains.

Remaining UI integration limitation: equivalent-course declarations await the
team's HR review policy. Selecting that answer currently explains that HR guidance
is required instead of silently treating it as a completed exact course.


## Editing the student HTML

Frontend developers edit these repository files directly:

- `htdocs/wp-content/plugins/taas-core/pages/student-application.html`
- `htdocs/wp-content/plugins/taas-core/pages/check-application-status.html`
- `htdocs/wp-content/plugins/taas-core/pages/front-page.html`

Repository sources are plain HTML fragments. Saved WordPress pages wrap that
HTML in one standard Custom HTML block so the modern editor remains available.
There are no custom block registrations or PHP presentation renderers. Existing CSS classes are retained to preserve appearance; they do not
create WordPress blocks. Degree templates, course containers, term/notice/dialog,
status controls and navigation markup are frontend-owned. Dynamic course/ranking
rows remain frontend JavaScript. Preserve the named-input/component contracts below.

WordPress's default modern editor handles these pages. Each saved page contains
one standard Custom HTML block; edit its HTML there. `TAAS_Pages` wraps raw HTML
when loading source files for installation or an explicit import. No custom editor
activation, content-formatting overrides or global post-save wrapping hooks are needed.

Saved WordPress content is authoritative. Edit it under **Pages**, then save/update
the page. Repository file edits do not appear automatically. Source files seed new
installations; normal synchronization preserves existing student-page edits.
To deliberately replace selected WordPress content from files, use
`TAAS_Pages::sync_content(true, array('student-application'))` in local development
(or an authorized administrative import). Back up page content before importing.
`TAAS_Pages::export()` exports WordPress content in local dev, removing the single
Custom HTML block wrapper so the repository files remain plain HTML.

WordPress owns routing, page IDs, saved page content, authentication and the
surrounding theme. Frontend JavaScript and the REST contract remain independent of
whether HTML was written in WordPress or imported from a file.

`tests/integration/student-editor.php` checks plain source HTML, modern-editor settings,
preservation of WordPress edits and explicit source imports.
`tests/integration/student-pages.php` checks access/API integration and confirms
source-file changes do not silently override published WordPress content.


### Student homepage

`front-page` uses plain HTML links marked with
`data-student-destination` for application, status and the existing professor page. Its label and notice
wording are editable in its HTML file; `{term}` inserts the configured academic term.
The Apply link remains clickable when an application already exists and shows the
approved visible notice and accessible description. The destination's
existing recovery/duplicate protections still apply.

`pages/front-page.php` in the student plugin reads only the authenticated owner's
configured-term snapshot or course rows. A snapshot or any matching course row,
including withdrawn rows, counts as already applied. Reads are server-side, so the
homepage needs no form bootstrap request or course-list download. PHP passes only
managed URLs and state through `window.TAASStudentHome`; `assets/front-page.js` binds
links and displays the HTML-owned applied/unavailable messages. Those messages live
in `[data-home-applied]` and `[data-home-unavailable]` elements.
Personalized student pages already send no-cache headers through shared access checks.
The existing professor link remains navigation only; it grants no instructor role.
`tests/integration/student-homepage.php` covers ownership, term isolation, withdrawn
records, missing configuration and managed URLs using rolled-back fixtures.
`tests/browser/student-homepage.cjs` checks published links, HTML-owned labels, accessible
feedback, URL safety and unauthorized access.


Status-page presentation retains its original HTML card, table, badge and button
styling through scoped CSS overrides for theme typography and Gutenberg containers.
The term selector, live course/rank/status columns and HTML in-page editor
remain connected. Desktop/mobile visual comparison and editor opening were verified.


Workflow clarification: matching/rejection occur after application closure. Student
edits and withdrawals are allowed only before closure, so matched/rejected student
transitions are outside this portal workflow. Existing closure and defensive status
checks remain; scheduling enforcement by the matching backend is a separate concern.


## Frontend code guide

Start with the coordinator for the page you are changing:

- `assets/student-application.js`: initial form loading, retries and one-time submission.
- `assets/student-application-edit.js`: opening saved details, read-only permissions,
  revision-aware edits and status-page events.

Each coordinator has its own state and contains only its page's workflow. The create
state tracks term, open/closed availability and request phase. The edit state additionally
tracks the saved revision, selected endpoint and temporary external lock.

The remaining responsibilities are separated into five components:

- `application-api-client.js`: authenticated JSON requests, load/save response contracts,
  HTTP errors and a 30-second timeout.
- `application-fields.js`: prefill named HTML inputs and use `FormData` to map their
  values to REST details. It has no dependency on WordPress blocks or layout classes.
- `application-form.js`: mount the degree/course components, assemble their data with
  the field details, and display loading, errors, permissions and success.
- `academic-background.js`: repeatable degree controls, degree validation and collection.
- `course-preferences.js`: course display, saved choices, ranking and completion checks.
  Its read-only empty state is separate from the editable selection instruction.

Each component exposes a small factory through `window.TAASStudent`. WordPress script
dependencies in `includes/assets.php` load components before the selected coordinator; no build
step or frontend framework is required. The public `TAASStudentApplicationForm.open`
and `.lock` methods and `taas:application-*` events remain compatible with the status
controller. The status controller depends on the edit coordinator; each page loads
only its own coordinator. Display/prefill methods and payload collection are separate.

Missing or duplicate contract input names keep the form disabled and display a
configuration error. Extra input names are ignored rather than forwarded to the API. Timeout errors preserve browser entries. A timed-out write may
still have committed, so the user is instructed to check saved status before retrying.

Focused JavaScript checks:

```sh
node --test tests/javascript/application-api-client.test.cjs
node tests/browser/student-application-status.cjs
```

The browser test requires Playwright as before (or `TAAS_PLAYWRIGHT_MODULE`) and a
running local WordPress site. Its API responses are fixtures and all writes are blocked.
It covers finalized results, read-only withdrawal, historical English-score cleanup,
pending edits, repeated saves with fresh revisions, stale-edit recovery and duplicate-field handling. The original initial-form browser test
still checks its existing flows; its final error assertion remains affected by the
separate UF theme errors, which have deliberately not been suppressed.


## Presentation contract

The REST contract above stays authoritative. Saved WordPress HTML supplies the
form; repository HTML can be imported explicitly. No backend changes are needed for new
headings, labels, wrappers, CSS, field order, extra presentation-only inputs, or
additional submit buttons.

- Mount the coordinator on `form[data-application-form]`. The old form ID still works.
- Keep the existing named inputs: `firstName`/`lastName` for creation, `studentName`
  for edits, and `ufEmail`, `phone`, `countryOfOrigin`, `startSemester`, `collegeStatus`,
  `taHours`, `eap5836Status`, `eap5837Status`. Labels/classes/IDs are independent.
- `ufid` is optional display-only; ownership always comes from backend authentication.
  `resumeUrl`, `englishScore`, `additionalInfo` can be omitted. Missing optional
  controls preserve loaded values during edits; an existing empty control clears its value.
- Keep one interactive mount per degree/course component:
  `data-application-ui="degreeHost"`, `degreeTemplate`, `addDegree`, `courseList`,
  and `rankings`. Their existing IDs remain supported for saved pages.
- The repeatable degree template retains its component-local `data-field` attributes;
  course controls retain their local input names. These components own their internal
  markup and expose `prefill`, `validate` and `collect` to the form adapter.
- Term/message/retry/notice/recovery/dialog/link hooks are optional. Use the
  `data-application-ui` keys in `application-form.js` to place them anywhere inside
  `[data-application-root]` (or the existing `.taas-application-form-root`).
  A missing message element is generated accessibly. No dialog is required for success.
  Without a recovery control, the form remains visible when an application exists;
  the API still prevents conflicting creation.
- The adapter locks form controls independently of a particular fieldset layout,
  retaining component-disabled controls such as unavailable GPA inputs.

Adding a newly stored business field still requires updating the API contract and
its validation/storage, plus the field adapter. Extra unrecognized inputs are never
silently persisted. HTML presentation no longer depends on custom WordPress block
definitions or PHP rendering callbacks.

`node tests/browser/student-presentation.cjs` exercises the contract using local HTML,
without WordPress or API requests. It covers redesigned layouts, omitted optional
controls, edit-value preservation, locks and missing required fields.


### Converting existing custom components

Current sources contain plain HTML; saved pages use one standard Custom HTML block. Saved WordPress HTML is
authoritative; repository files are imported only when explicitly requested. For an existing installation, use
`scripts/convert-student-components.php` **before deploying removal of the old block
registration**, while the previous renderer is still installed. It renders legacy
components once into Custom HTML, preserving each page's saved wording separately
from the repository source. It converts complete containers together so their HTML
remains balanced, and turns homepage links into static HTML hooks rather than
freezing personalized state into the page.

Run without arguments to preview. To apply, pass `--apply` and a new writable backup
JSON filename (for example under ignored `real-data/`). The backup contains original
and replacement source/page content. The converter rejects missing legacy renderers
and concurrent edits; already-converted pages are unchanged. The local three pages
have been converted, with their backup in `real-data/student-components-before-20261031.json`.

The API still validates all submitted data and derives ownership from authentication.


### Flattening older standard blocks

`scripts/convert-student-pages-to-html.php` renders remaining standard blocks once
into plain HTML, independently for repository and saved pages. Run without arguments
to preview or with `--apply` and a new backup JSON path to convert. It preserves the
rendered structure and guards against concurrent edits. This historical flattening step was completed before restoring the modern editor;
original/replacement content is backed up under
`real-data/student-plain-html-before-20261031.json`. No block conversion occurs on
normal page requests.

Course presentation uses five ordinary HTML `<template>` elements inside the
form: `data-course-template="header"`, `"row"`, `"ranking"`, `"emptyRankings"`, and `"readOnlyEmptyRankings"`. Edit their layout
in the page's Custom HTML block or the repository HTML before importing it.
Keep `name="selectedCourses"`, `name="courseRank"`, `name="courseHistory"`, and
`data-course-text` bindings intact. JavaScript clones these templates and fills
course text using `textContent`; it does not generate course markup. Labels and empty-state messages are edited directly in these templates;
there is no separate JSON label configuration. Missing templates disable
form setup with the existing accessible configuration error.

For existing local installations, `scripts/add-course-templates.php` adds only
these templates to saved pages and requires a new backup filename when applying.
It preserves the rest of the WordPress content and skips pages already migrated.

For pages with the former JSON label configuration, `scripts/migrate-course-labels.php`
preserves label overrides in HTML, adds empty-state templates, and removes the
obsolete attributes. Applying requires `--apply` and a new backup filename.

Status results use `data-status-template="course"` and `"empty"` HTML templates.
The course row retains `data-status-text="code"`, `"name"`, `"rank"`, and `"status"`
for safe text binding. Edit the templates in WordPress or import the source page.
`scripts/add-status-templates.php --apply <new-backup-path>` adds templates to
existing local pages without replacing other saved content.
Status reads time out after 30 seconds, restore controls, and offer retry.
Term and application response structures are checked before results are shown.

Status-page wording is stored in `template[data-status-messages]` as paragraphs
with stable `data-status-message` keys. Edit the text in WordPress; preserve
`{term}` and `{status}` placeholders where needed. Templates and required message
keys are validated before any API reads. The course-row template must contain
exactly one binding for each of code, name, rank, and status.
For existing local pages, `scripts/add-status-messages.php --apply <new-backup-path>`
adds the message template without replacing other saved page content.

The status controller uses `createApplicationApi().request()` for term/result
reads, sharing authentication, timeout handling, JSON parsing, and HTTP errors
with the application forms. Response validation stays in the status controller.
The client accepts optional `messages` overrides (`connectionUnavailable`,
`invalidResponse`, `requestFailed`, `loadTimeout`) so status wording still comes
from its HTML message template. `requestFailed` supports a `{status}` placeholder;
server-provided error messages take precedence.

Controls disabled solely for initial loading use `data-application-loading-lock`.
The form adapter releases these startup locks once; later lock/unlock cycles
restore each control's previous disabled state. Do not put this attribute on
sections or buttons intended to stay disabled. Existing local saved pages can
be updated with `scripts/mark-form-loading-controls.php --apply <new-backup-path>`.
