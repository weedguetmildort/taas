# HR Routes

## GET current cycle applicants

`GET /hr/applicants` is available to HR and admins while `hr_review` is active. The server selects the current term from the application cycle. A request cannot select a different term.

The response contains `term`, `year`, and an `applicants` array. Each row represents a submitted term snapshot and includes `submission_id` (a string), `name`, `student_type` (`undergrad`, `masters`, or `phd`), `gpa`, `english_test_score` (a display label), and `country_code`. Student type comes from the submitted enrollment status. GPA comes from the first degree in the submitted academic background. Missing summary fields are `null`. A cycle without submissions returns an empty array.

The route returns 403 `taas_stage_closed` when HR review is closed.

## GET applicant details

`GET /hr/applicants/{submission_id}` uses the same role and stage checks as the list. It returns a `details` object with the submitted name, email, profile, and academic background fields documented in [the student application](student.md). The profile includes a saved `advisor_name` for PhD applicants and FWS eligibility and the award-letter URL for undergraduate applicants. Older snapshots may omit profile and degree fields.

A missing submission or one from another term returns 404 `taas_not_found`. Both list and detail responses are private and non-cacheable.
