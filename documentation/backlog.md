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
- **Application submission:** add the route, and only accept submissions during `student_application`.
- **Student results:** decide whether students can see their status or match after their stage. They have no access after `student_application`, so the Check Application Status page can't show them results.

## Matching

- **Algorithm:** connect it to `GET /matching/recommendations`, which currently returns `[]`.
  - Decide how recommendations are stored.
  - The algorithm identifies students by UFID, so the route must also look up `application_id` and `student_name`.
  - Courses with enrollment under 10 currently make `taas_run_matching` throw an error.
- **Weekly hours formula:** make it customizable by the admin. It stays in `func-matching.php` until then.

## Students and HR

- **PhD applications:** create all of a PhD student's applications on their behalf, as part of the student layer.
- **HR decisions:** add somewhere to store them. The HR page collects approve and reject decisions, and the matching algorithm assumes them, but the schema has no field for them.

## Auth

- **Multiple roles:** decide whether one person can hold several, such as admin and professor. Until then, an admin who also teaches gets 403 from `/professor` routes. If it's allowed, their professor actions are assumed to follow the `professor_ranking` stage like any other professor's.
- **Nonce:** confirm that requests need an `X-WP-Nonce` header in production, and how pages obtain it.
- **UFID mapping:** confirm that Shibboleth provides the UFID in the `shibboleth_account` user meta. If it provides a GatorLink username or eppn instead, every professor will see no courses and get 404s.

## Deployment

- **PhpSpreadsheet:** XLSX exports need it on the server, but production deploys by git checkout and has no `vendor/` directory.

## Course ingestion

- **Professor records:** decide how they get created, with input from the admin who provides the course XLSX.

## Frontend

- **Closed state:** student, HR and professor pages need one, driven by `GET /application-cycle` and 403 `taas_stage_closed`.
- **Course pages:** drop the Course Type filter and the Number of Sections and Course Level columns, since that data isn't in the course import.
- **Matching page:** replace the Approve Match buttons, the Awaiting Ranking status, and the Top Ranked Student and Top Ranked Course columns.
- **Stop Accepting Applications button:** replace it with a change to `student_application`'s `end_at`.
- **Download Student Data button:** replace it once the exports exist.
