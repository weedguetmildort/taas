# HR Routes

## GET current cycle applicants

`GET /hr/applicants` is available to HR and admins while `hr_review` is active. The server selects the current term from the application cycle. A request cannot select a different term.

The response contains `term`, `year`, and an `applicants` array. Each row represents a submitted term snapshot and includes `submission_id` (a string), `student_ufid` (a string preserving leading zeros), `name`, `student_type` (`undergrad`, `masters`, or `phd`), `gpa`, `english_test_score` (a display label), `country_code`, `advisor_name`, `advisor_email`, and `fws_eligibility` (`yes`, `no`, or `not_applicable` for undergraduates; `null` for other students or missing answers). Student type comes from the submitted enrollment status. GPA comes from the first degree in the submitted academic background. The advisor name is available for PhD applicants with a saved name. Missing summary fields are `null`. A cycle without submissions returns an empty array.

The route returns 403 `taas_stage_closed` when HR review is closed.

Each applicant row also contains `decision` (`approve`, `reject`, or `null`) and `version` (zero until the first save). The response's `progress` object contains `total`, `reviewed`, and `approved` for the whole cycle. Both decisions count as reviewed.

## GET applicant details

`GET /hr/applicants/{submission_id}` uses the same role and stage checks as the list. It returns a `details` object with the submission owner’s `student_ufid` and the submitted name, email, profile, and academic background fields documented in [the student application](student.md). The profile includes saved `advisor_name` and `advisor_email` values for PhD applicants and FWS eligibility and the award-letter URL for undergraduate applicants. The details also include `reviewed_by_email`, the latest saved reviewer’s email, or `null` when unreviewed or unavailable. The popup displays this at the top under “Reviewed by”, using `-` when unavailable. Older snapshots may omit profile and degree fields.

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

Saved decisions live in `hr_reviews`, with one row keyed by `submission_id` per reviewed application. Each saved change stores the authenticated reviewer’s WordPress user ID, email, and UTC date/time. The email is captured from authentication, never from the request body. Older reviews without a saved email use the linked WordPress account’s email when available.

Each decision can include an optional plain-text `comment` of up to 1,000 characters. Omitting it preserves the saved comment, and sending `null` or blank text clears it. Editing only the comment still requires a decision and advances the version. The HR list, successful saves, and conflict responses return the saved comment to HR and admins.

If any version is stale, the server saves none of the changes and returns HTTP 409 with `code: "taas_review_conflict"`, a `conflicts` array containing the current decisions and versions, and current `progress`. An out-of-cycle or missing submission returns 404. A closed stage returns 403.

## POST advisor correction

`POST /hr/applicants/{submission_id}/advisor` is available to HR and admins while HR review is open, for PhD applicants in the current term. Send `advisor_name` (1–255 characters on one line), `advisor_email` (a required valid email of at most 255 bytes), and `expected_advisor_name` (the previously loaded string or `null`). The popup also sends `expected_advisor_email` to detect email-only concurrent changes. Names and emails are trimmed; blank values and NUL/newline characters are rejected. Professor IDs are not accepted.

The route updates the selected term’s submitted advisor contact and the student’s shared profile in one transaction, preserving other fields and other term snapshots. It returns `{ "advisor_name": "Dr. Example", "advisor_email": "advisor@example.com" }`. Stale values return 409 `taas_advisor_conflict`; missing or other-term submissions return 404; non-PhD applicants and invalid input return 400. Edit buttons in the table and application popup open a name/email form with Save and Cancel controls. Emails are displayed only in the popup.

Advisor contact details remain specific to the student. Student application edits preserve the saved advisor email while the advisor name is unchanged; changing the advisor clears the previous email.
