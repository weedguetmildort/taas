# HR Routes

## GET current cycle applicants

`GET /hr/applicants` is available to HR and admins while `hr_review` is active. The server selects the current term from the application cycle. A request cannot select a different term.

The response contains `term`, `year`, and an `applicants` array. Each row represents a submitted term snapshot and includes `name`, `student_type` (`undergrad`, `masters`, or `phd`), `gpa`, `english_test_score` (a display label), and `country_code`. Student type and GPA come from the first degree in the submitted snapshot. Missing fields are `null`. A cycle without submissions returns an empty array.

The route returns 403 `taas_stage_closed` when HR review is closed.
