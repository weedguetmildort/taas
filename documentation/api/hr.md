# HR Routes

## GET current cycle applicants

`GET /hr/applicants` is available to HR and admins while `hr_review` is active. The server selects the current term from the application cycle. A request cannot select a different term.

The response contains `term`, `year`, and an `applicants` array. Each row represents a submitted term snapshot and includes `submission_id` (a string), `name`, `student_type` (`undergrad`, `masters`, or `phd`), `gpa`, `english_test_score` (a display label), `country_code`, and `advisor_name`. Student type comes from the submitted enrollment status. GPA comes from the first degree in the submitted academic background. The advisor name is available for PhD applicants with a saved name. Missing summary fields are `null`. A cycle without submissions returns an empty array.

The route returns 403 `taas_stage_closed` when HR review is closed.

Each applicant row also contains `decision` (`approve`, `reject`, or `null`) and `version` (zero until the first save). The response's `progress` object contains `total`, `reviewed`, and `approved` for the whole cycle. Both decisions count as reviewed.

## GET applicant details

`GET /hr/applicants/{submission_id}` uses the same role and stage checks as the list. It returns a `details` object with the submitted name, email, profile, and academic background fields documented in [the student application](student.md). The profile includes a saved `advisor_name` for PhD applicants and FWS eligibility and the award-letter URL for undergraduate applicants. Older snapshots may omit profile and degree fields.

A missing submission or one from another term returns 404 `taas_not_found`. Both list and detail responses are private and non-cacheable.

## POST review decisions

`POST /hr/applicants/decisions` saves one or more decisions in a single transaction. Send only changed decisions and include the version shown in the list for each submission:

```json
{
  "decisions": [
    { "submission_id": "71", "decision": "approve", "version": 0 },
    { "submission_id": "72", "decision": "reject", "version": 1 }
  ]
}
```

While HR review is open, a reviewer may revise an existing decision using its latest version. Each changed decision advances the version by one. Success returns `reviews` with the saved submission IDs, decisions and versions, plus the current `progress`.

Saved decisions live in `hr_reviews`, with one row keyed by `submission_id` per reviewed application.

Each decision can include an optional plain-text `comment` of up to 1,000 characters. Omitting it preserves the saved comment, and sending `null` or blank text clears it. Editing only the comment still requires a decision and advances the version. The HR list, successful saves, and conflict responses return the saved comment to HR and admins.

If any version is stale, the server saves none of the changes and returns HTTP 409 with `code: "taas_review_conflict"`, a `conflicts` array containing the current decisions and versions, and current `progress`. An out-of-cycle or missing submission returns 404. A closed stage returns 403.
