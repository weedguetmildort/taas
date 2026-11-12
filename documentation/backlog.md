# Backlog

Known work that isn't done yet, and decisions that are still open.

This is a good place to note any half-finished TODOs during implementation: if something has to be left incomplete, add it here so it isn't lost in a code comment or a commit message. Remove an item once it's done or no longer needed.

This is not intended to replace project management and tickets, but rather to note unfinished wiring during simultaneous development, items waiting on open questions, or other similar notes that shouldn't get lost but are too small or temporary for dedicated tickets.

[`documentation/api/`](api/README.md) only describes the API as it works today, so anything planned belongs here until it exists.

## API routes

- **Exports:** add four routes, each returning an `.xlsx` file in the course import format. Their rows and columns are undecided.
  - `GET /exports/student-preferences`, filterable by `?student_ufid=`
  - `GET /exports/instructor-preferences`, filterable by `?course_id=`
  - `GET /exports/assignments-by-course`, filterable by `?course_id=`
  - `GET /exports/assignments-by-student`, filterable by `?student_ufid=`
- **Student route conventions:** decide whether to align [the student routes](api/student.md#how-these-routes-differ-from-the-conventions) with the rest of the API. They send stored values and terms as integers and `course_id` as a string, and most validation errors have no `data.params`.

## Matching

- **Algorithm:** connect it to `GET /matching/recommendations`, which currently returns `[]`.
  - Decide how recommendations are stored.
  - The algorithm identifies students by UFID, so the route must also look up `application_id` and `student_name`.
  - Courses with enrollment under 10 get no TA hours (Professor Rani's cutoff, which stays as is), but they currently make taas_run_matching throw an error. They should be skipped instead.
- **Weekly hours formula:** make it customizable by the admin. It stays in `func-matching.php` until then. If we do not get to this item, ensure the formula location is documented for Dr. Rani for manual editing if ever needed.

## Students and HR

- **PhD applications:** create all of a PhD student's applications on their behalf, as part of the student layer.
- **Re-adding a ranked course:** decide whether a professor's rank is cleared or kept when a student re-adds a course they withdrew from. Until then the edit returns 409 and says the admin must review it, but no admin route can do that, since `PATCH /applications/{application_id}` rejects withdrawn applications.
- **Already applied:** decide what a second submission for the same term should do. It currently returns 409, and students edit through their status page instead, which only works during `student_application`.
- **Use HR decisions in matching:** the HR page saves decisions per term submission. Apply those decisions when selecting matching inputs.

## Auth

- **Nonce:** confirm that requests need an `X-WP-Nonce` header in production, and how pages obtain it.
- **Student affiliation values:** `taas_student` is synced from `eduperson_affiliations`, matching `student` (UF sends `STUDENT$`). Only one student account has been checked. If other students, such as graduate students, arrive with a different value, add it to `TAAS_Shibboleth::STUDENT_AFFILIATIONS`. HR and admin stay assigned in wp-admin.

## Deployment

- **PhpSpreadsheet:** the course import and the XLSX exports need it on the server, but production deploys by git checkout and has no `vendor/` directory. `POST /courses/import` returns 503 `taas_import_unavailable` until it's there. The route loads the autoloader named by `TAAS_COMPOSER_AUTOLOAD`, so production needs that defined in `wp-config.php` too.

## Course ingestion

- **Professor name format:** decide whether to change how `professor_name` is loaded. The course export gives names as `Lastname, First`, and the import stores them as given (only collapsing whitespace), so imported professors are named differently from the seed data (`Dr. Alice Nguyen`). This affects how names are displayed, sorted and searched. Note that an existing professor's name is never updated by the import, so changing the format later won't fix rows already stored.

## Frontend

- **Closed state:** HR and professor pages need one, driven by `GET /application-cycle` and 403 `taas_stage_closed`.
- **Course pages:** drop the Course Type filter and the Number of Sections and Course Level columns, since that data isn't in the course import.
- **Matching page:** replace the Approve Match buttons, the Awaiting Ranking status, and the Top Ranked Student and Top Ranked Course columns.
- **Stop Accepting Applications button:** replace it with a change to `student_application`'s `end_at`.
- **Download Student Data button:** replace it once the exports exist.
