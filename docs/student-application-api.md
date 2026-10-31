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

Both pages use core-managed WordPress content blocks rendered by the student
plugin; repeatable degree controls use a shared partial. The form styles are scoped to avoid changing the
status table or WordPress theme. The shared JavaScript runs in separate create
and edit modes; each page has its own PHP setup, and the status page has a
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


## Editing the student pages in WordPress

Student Application, Check Application Status and Student Home Page use editable
WordPress blocks. Application sections use native Heading/Paragraph/Group blocks and
TAAS blocks for integrated fields, repeated degrees, course selection and confirmation.
Edit wording and grouping under **Pages**; select a TAAS block to edit labels/options
in its settings. The original HTML's frontend styling is retained through scoped CSS
that neutralizes extra Group containers and theme typography overrides.

Live courses, saved details, authentication, validation and submission stay in
PHP/REST/JavaScript. Keep required controls and backend field identifiers intact;
an incomplete application form disables submission. WordPress's block editor is
enabled for the application page again.

The source HTML files seed new installations. Normal `TAAS_Pages::sync_content()`
preserves all three student pages, including Student Home Page, so WordPress edits
survive plugin updates. Other teams' pages keep their existing synchronization.
For an intentional import in local development, use
`TAAS_Pages::sync_content(true, array('student-application', 'check-application-status'))`.
This replaces those pages' editor content, so back it up first. Outside local
mode an explicit import requires `manage_options`. Export editor changes with the
existing local `TAAS_Pages::export()` workflow when they should be version controlled.
Existing shortcode pages remain supported for compatibility, but new source pages
use editable blocks instead of a full-page form shortcode. Existing installs
need an explicit import to convert; custom editor content is never migrated silently.

Non-browser checks: `tests/integration/student-editor.php` covers raw HTML/block rendering,
label escaping, stable field names, and preserving editor changes during sync.
`tests/integration/student-pages.php` checks published markup, access, and API connections.


### Student homepage

`front-page` uses native content blocks and the `taas/homepage-link` block for
managed application, status and existing professor-page links. Its label and notice
wording are editable in WordPress; `{term}` inserts the configured academic term.
The Apply link remains clickable when an application already exists and shows the
approved visible notice and accessible description. The destination's
existing recovery/duplicate protections still apply.

`pages/front-page.php` in the student plugin reads only the authenticated owner's
configured-term snapshot or course rows. A snapshot or any matching course row,
including withdrawn rows, counts as already applied. Reads are server-side, so the
homepage needs no form bootstrap request, course-list download or form JavaScript.
Personalized student pages already send no-cache headers through shared access checks.
The existing professor link remains navigation only; it grants no instructor role.
`tests/integration/student-homepage.php` covers ownership, term isolation, withdrawn
records, missing configuration, labels and managed links using rolled-back fixtures.


Status-page presentation retains its original HTML card, table, badge and button
styling through scoped CSS overrides for theme typography and Gutenberg containers.
The term selector, live course/rank/status columns and block-based in-page editor
remain connected. Desktop/mobile visual comparison and editor opening were verified.


Workflow clarification: matching/rejection occur after application closure. Student
edits and withdrawals are allowed only before closure, so matched/rejected student
transitions are outside this portal workflow. Existing closure and defensive status
checks remain; scheduling enforcement by the matching backend is a separate concern.


## Frontend code guide

Start with `assets/student-application.js`. Its job is to coordinate initial
loading, opening saved applications, saving, and the existing status-page events.
The single state object holds the term, edit permission, revision, endpoint, phase
and external lock. Named conditions distinguish permission from loading/saving state.

The remaining responsibilities are separated into four components:

- `application-api.js`: authenticated JSON requests, load/save response contracts,
  HTTP errors and a 30-second timeout.
- `application-form.js`: validate unique fields/blocks, prefill profile details, collect
  the payload, and display initial/saved form feedback. Generated historical English-score options
  are removed before prefilling another saved application.
- `academic-background.js`: repeatable degree controls, degree validation and collection.
- `course-preferences.js`: course display, saved choices, ranking and completion checks.
  Its read-only empty state is separate from the editable selection instruction.

Each component exposes a small factory through `window.TAASStudent`. WordPress script
dependencies in `includes/form.php` load components before the coordinator; no build
step or frontend framework is required. The public `TAASStudentApplicationForm.open`
and `.lock` methods and `taas:application-*` events remain compatible with the status
controller. Display/prefill methods and payload collection are separate.

Duplicate required names or singleton IDs keep the form disabled and display a
configuration error. Timeout errors preserve browser entries. A timed-out write may
still have committed, so the user is instructed to check saved status before retrying.

Focused JavaScript checks:

```sh
node --test tests/javascript/application-api.test.cjs
node tests/browser/student-application-status.cjs
```

The browser test requires Playwright as before (or `TAAS_PLAYWRIGHT_MODULE`) and a
running local WordPress site. Its API responses are fixtures and all writes are blocked.
It covers finalized results, read-only withdrawal, historical English-score cleanup,
pending edits and duplicate-field handling. The original initial-form browser test
still checks its existing flows; its final error assertion remains affected by the
separate UF theme errors, which have deliberately not been suppressed.
