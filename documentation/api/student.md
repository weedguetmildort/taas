# Student Routes

Routes for a signed-in student to apply for TA positions in the current term, check their applications, and edit them while applications are open. The base path, sign-in, stage access and shared errors are in the [API overview](README.md).

**Access:** every route here needs the `taas_student` role. A signed-in user without it gets 403 `taas_forbidden`, even a `taas_admin`; holding it alongside other roles is fine. WordPress administrators hold it, so with a UFID saved they can use these routes as themselves. The caller also needs an 8-digit UFID on their account, or every route returns 403 `taas_student_identity_unavailable`.

**Stages:** the GET routes stay open in every stage, so a student can check their status and see a "closed" message after applying ends. The writes only work during `student_application`, and return 403 `taas_stage_closed` outside it.

**Ownership:** the student is identified by their session, never by anything in the request. A term the student has no application for returns 404 `taas_not_found`.

**Caching:** every successful response has `Cache-Control: no-store`.

## How these routes differ from the conventions

These routes were written separately from the rest of the API, and don't follow some of the conventions in the [API overview](README.md#conventions):

| | Here |
| --- | --- |
| Terms | An integer `semester` and a `year`, in the path and in bodies, never `?term=`. `semester` is `1` Fall, `2` Spring, `3` Summer A, `4` Summer B, `5` Summer C. Responses add a `label` such as `"Fall 2026"` |
| Stored values | Sent and received as integers, not labels. The values are listed with [POST: submit an application](#post-submit-an-application) |
| `course_id` | A string of digits, such as `"1234"`, in both requests and responses |
| `match_status` | An integer, with the label alongside it in `status_label` |
| Pagination | None. Every list is returned in full |
| Validation errors | Most are 400 `taas_invalid_application`, with the reason in `message` and no `data.params` |

## Application period

The period is the current [application cycle](common.md#application-cycle), seen from the student's side. Students can only apply for the cycle's term, and only while `student_application` is the active stage.

```json
{
  "semester": 1,
  "year": 2026,
  "label": "Fall 2026",
  "is_open": true,
  "closes_at": 1794805199
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `semester`, `year` | integer | The cycle's term |
| `label` | string | The term for display |
| `is_open` | boolean | Whether `student_application` is the active stage. It agrees with the stage check, so `false` means the writes will return `taas_stage_closed` |
| `closes_at` | integer, *nullable* | `student_application`'s `end_at`, as a Unix timestamp in seconds. `1794805199` is `2026-11-16T04:59:59Z`. Still sent after it has passed. `null` means no end is set |

If no cycle has been set, GET: application form returns 503 `taas_period_unconfigured`.

## GET: application form

`GET /student/application`

Everything the application page needs: the period, the student's identity, their saved details for prefilling, and the courses they can choose.

**Request params:** none

**Response body:**

```json
{
  "period": {
    "semester": 1,
    "year": 2026,
    "label": "Fall 2026",
    "is_open": true,
    "closes_at": null
  },
  "identity": {
    "ufid": "12345678",
    "email": "test.student@ufl.edu"
  },
  "already_applied": true,
  "replacement": null,
  "profile": {
    "student_ufid": "12345678",
    "phone": "3525550100",
    "admitted_semester": "Fall 2025",
    "available_hours": 10,
    "major": "Computer Science",
    "gpa": 3.5,
    "enrollment_status": 2,
    "resume_url": null,
    "english_test_score": 2,
    "has_ta_experience": 0,
    "eap5836_status": 1,
    "eap5837_status": 3,
    "country_code": "US",
    "additional_info": ""
  },
  "english_test_score": 2,
  "academic_background": [
    {
      "background_id": 37,
      "student_ufid": "12345678",
      "university": "University of Florida",
      "degree_type": 2,
      "major": "Computer Science",
      "minor": null,
      "grad_date": "2025-05-01",
      "gpa": 3.5
    }
  ],
  "courses": [
    {
      "course_id": "5678",
      "course_code": "CDA3101",
      "course_name": "Introduction to Computer Organization",
      "instructor_name": "Dr. Marcus Reed"
    },
    {
      "course_id": "1234",
      "course_code": "COP3502",
      "course_name": "Programming Fundamentals 1",
      "instructor_name": "Dr. Alice Nguyen"
    }
  ]
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `period` | object | See [Application period](#application-period) |
| `identity` | object | The UFID and email from the session. The submitted `student_email` must match `email` |
| `already_applied` | boolean | The student already has an application for the period's term, including one whose choices were all withdrawn. A conflicting POST to the creation endpoint returns 409 |
| `replacement` | object, *nullable* | When the application is editable, contains the term edit `endpoint`, current `revision`, saved `student_name`, and pending `course_preferences` to prefill the replacement form. Use the endpoint and revision to submit with `replace_existing: true` after the student chooses Start a new form. Omitted course applications are deleted for that term. |
| `profile` | object, *nullable* | The student's shared profile as stored, which every submission or edit replaces. `null` if they have no profile yet. `has_ta_experience` is `0` or `1` here, not a boolean |
| `english_test_score` | integer, *nullable* | Latest application category (1–4), or null if unanswered |
| `academic_background` | array | The student's shared degrees, in the order they last submitted them. `[]` if they have none. `minor` is *nullable* |
| `courses` | array | Every course in the period's term, ordered by `course_code`, then `course_id` |

`english_test_score` prefills the latest application category, falling back to the shared profile when there is no application. A cleared or unanswered category is `null`.

| Status | `code` | When |
| --- | --- | --- |
| 503 | `taas_period_unconfigured` | No application cycle has been set |

## POST: submit an application

`POST /student/application`

Submits the student's first application for the period's term. Edits to an existing application go to [PATCH: edit an application](#patch-edit-an-application) instead.

**Request body:**

```json
{
  "term": { "semester": 1, "year": 2026 },
  "details": {
    "student_name": "Test Student",
    "student_email": "test.student@ufl.edu",
    "profile": {
      "phone": "3525550100",
      "country_code": "US",
      "admitted_semester": "Fall 2025",
      "available_hours": 10,
      "enrollment_status": 2,
      "eap5836_status": 1,
      "eap5837_status": 3,
      "resume_url": null,
      "english_test_score": 2,
      "additional_info": ""
    },
    "academic_background": [
      {
        "university": "University of Florida",
        "degree_type": 2,
        "major": "Computer Science",
        "minor": "",
        "grad_date": "2025-05-01",
        "gpa": 3.5
      }
    ]
  },
  "course_preferences": [
    { "course_id": "1234", "rank": 1, "has_completed_course": true, "has_ta_for_course": false },
    { "course_id": "5678", "rank": 2, "has_completed_course": true }
  ]
}
```

**General rules:**

- Any field not listed below returns 400 `taas_invalid_application`, at every level of the body.
- Text is trimmed, then checked against its maximum length. Text over the limit is rejected, never shortened.
- Integers and numbers must be JSON numbers. A string such as `"10"` is rejected.

**`term`:** must be the period's term, sent back as it came from GET: application form. Anything else returns 409, so a page that was open across a change of cycle can't apply for the wrong term.

**`details`:**

| Field | Required | Rules |
| --- | --- | --- |
| `student_name` | yes | Up to 255 characters, stored as one string |
| `student_email` | yes | Must match the session's email, ignoring case. The session's value is the one saved |

**`details.profile`:**

| Field | Required | Rules |
| --- | --- | --- |
| `phone` | yes | Up to 15 characters. The format isn't checked |
| `country_code` | yes | A two-letter ISO 3166-1 code from the form's country list, any case. Saved in upper case |
| `admitted_semester` | yes | `Spring`, `Summer`, `Summer A`, `Summer B`, `Summer C` or `Fall`, then a space and a 4-digit year, such as `Fall 2025` |
| `available_hours` | yes | `10` or `20` |
| `enrollment_status` | yes | `1` Undergraduate, `2` Masters, `3` PhD |
| `eap5836_status`, `eap5837_status` | yes | `1` Taken, `2` Currently Enrolled, `3` Not Yet Taken, `4` N/A. Required for every student |
| `resume_url` | no | An `http` or `https` URL, up to 255 characters. Omitted, `null` and `""` are all saved as `null` |
| `fws_eligibility` | undergraduates | `yes`, `no`, or `not_applicable`; an explicit answer is required. Graduate saves normalize this to `no`. The profile column is non-nullable and defaults to `no`. |
| `fws_award_letter_url` | when FWS is `yes` | Separate FWS award letter link: complete HTTP/HTTPS URL, maximum 255 characters. The column is nullable; omitted, `null`, and empty values become `null` unless eligibility is `yes`. Graduate saves always clear it to `null`. |
| `additional_info` | no | Up to 1000 characters. Saved as `""` when omitted |
| `english_test_score` | yes | Integer category 1–4; see the labels below |

FWS fields are stored in both the shared student profile and the submitted term snapshot. Saving graduate status sets eligibility to `no` and clears the award letter URL for that profile and term only; earlier terms remain unchanged. Existing snapshots without FWS fields remain readable, but undergraduates must answer eligibility when saving edits. The form preserves FWS entries while toggling college status before saving; a successful graduate save clears those entries.

One `english_test_score` category is required for every submission and edit, regardless of college status. Send an integer, not the label:

| Value | Display label |
| --- | --- |
| 1 | Under 45 SPEAK / Under 23 TOEFL iBT |
| 2 | 45–54 SPEAK / 23–27 TOEFL iBT |
| 3 | 55–60 SPEAK / 28–30 TOEFL iBT |
| 4 | N/A |

Missing, null, string, or out-of-range values return 400. `4` is an explicit N/A answer; `null` means an existing application has not answered yet. The migration clears retired raw scores from profiles and snapshots without choosing a category. Students must choose one before saving. Raw scores, legacy `english_test`, and exam dates are no longer accepted.

**`details.academic_background`:** at least one degree, with no maximum. Put the current or most recent degree first: its `major` and `gpa` become the student's shared `major` and `gpa`.

| Field | Required | Rules |
| --- | --- | --- |
| `university` | yes | Up to 255 characters |
| `degree_type` | yes | `1` AA, `2` BS, `3` MS, `4` PhD |
| `major` | yes | Up to 255 characters |
| `minor` | no | Up to 255 characters. Saved as `""` when omitted |
| `grad_date` | yes | The 1st of the month, `YYYY-MM-01`. It may be in the future |
| `gpa` | yes, but may be `null` | 0 to 4, rounded to two decimal places. `null` means no GPA yet. The key must be present |

**`course_preferences`:** Exactly 5 courses for PhD students and 1 to 5 for other students.

| Field | Required | Rules |
| --- | --- | --- |
| `course_id` | yes | A `course_id` from `courses` in GET: application form, as a string. The course must be in the period's term |
| `rank` | yes | The student's preference, where 1 is their first choice. The ranks must be exactly `1..n` for `n` courses, with no ties or gaps |
| `has_completed_course` | yes | `true` for a completed course or equivalent, `false` for a course the student has not completed |
| `course_completion` | no | Integer code: `0` = no, `1` = completed this course, `2` = completed an equivalent. Must agree with `has_completed_course`. When omitted, the boolean maps to `0` or `1`. |
| `has_ta_for_course` | no | Whether the student has TA'd this course before. Defaults to `false` |

Each `course_id` may appear once.

**What is saved:** all in one transaction, so either everything is saved or nothing is:

- one application per course, each `Pending`
- the student's shared profile and degrees, replacing what was there before. Degrees get new `background_id`s on every save
- a copy of `details` for this term, which GET: one application returns as it was submitted

**Response:** 201 Created:

```json
{
  "submission_id": 40,
  "already_saved": false,
  "message": "Your application was successfully submitted.",
  "status_url": "http://localhost:8080/check-application-status/",
  "home_url": "http://localhost:8080/"
}
```

| Field | Notes |
| --- | --- |
| `submission_id` | The id of this term's saved details |
| `already_saved` | `true` when this exact application was already saved, so a retry after a timeout is safe. The response is then 200 OK instead of 201 |
| `status_url`, `home_url` | The student's status page and homepage |

A retry only counts as `already_saved` if every field matches the saved application and every choice is still `Pending`. Anything else returns 409.

| Status | `code` | When |
| --- | --- | --- |
| 400 | `taas_invalid_application` | The body isn't a JSON object, or breaks a rule above. `message` says which |
| 400 | `taas_student_email_mismatch` | `student_email` isn't the session's email |
| 400 | `taas_application_not_saved` | A course isn't in the period's term |
| 403 | `taas_student_email_unavailable` | The session has no `@ufl.edu` email |
| 403 | `taas_application_not_saved` | Applying closed while the request was being handled |
| 409 | `taas_application_not_saved` | `term` isn't the period's term, or the student already applied this term with different details |

## GET: a student's terms

`GET /student/application/terms`

Every term the student has applied for, to choose from on their status page.

**Request params:** none

**Response body:** `terms` is `[]` if the student has never applied. Ordered by `year`, newest first, then by most recently created, so terms within one year aren't in calendar order.

```json
{
  "terms": [
    {
      "submission_id": 40,
      "semester": 1,
      "year": 2026,
      "label": "Fall 2026"
    }
  ]
}
```

## GET: one application

`GET /student/application/{semester}/{year}`

The student's saved application for one term, with each course's status. Use it to show their status, and to fill the form for editing.

`semester` is 1 to 5 and `year` is 1901 to 2155. A `semester` outside 1 to 5 doesn't match the route, and returns 404 `rest_no_route`.

**Response body:**

```json
{
  "submission_id": 40,
  "term": {
    "semester": 1,
    "year": 2026,
    "label": "Fall 2026"
  },
  "details": {
    "student_name": "Test Student",
    "student_email": "test.student@ufl.edu",
    "profile": {
      "phone": "3525550100",
      "country_code": "US",
      "admitted_semester": "Fall 2025",
      "available_hours": 10,
      "enrollment_status": 2,
      "eap5836_status": 1,
      "eap5837_status": 3,
      "resume_url": null,
      "additional_info": "",
      "english_test_score": 2
    },
    "academic_background": [
      {
        "university": "University of Florida",
        "degree_type": 2,
        "major": "Computer Science",
        "minor": "",
        "grad_date": "2025-05-01",
        "gpa": 3.5
      }
    ]
  },
  "identity": {
    "ufid": "12345678",
    "email": "test.student@ufl.edu"
  },
  "can_edit": true,
  "revision": "d894cb81d47f3a0e6d378e21c15475e8cd0c275c586ed25c54f8a74f0f64b112",
  "courses": [
    {
      "course_id": "5678",
      "course_code": "CDA3101",
      "course_name": "Introduction to Computer Organization",
      "instructor_name": "Dr. Marcus Reed"
    },
    {
      "course_id": "1234",
      "course_code": "COP3502",
      "course_name": "Programming Fundamentals 1",
      "instructor_name": "Dr. Alice Nguyen"
    }
  ],
  "course_preferences": [
    {
      "application_id": 21,
      "course_id": "1234",
      "course_code": "COP3502",
      "course_name": "Programming Fundamentals 1",
      "rank": 1,
      "match_status": 4,
      "status_label": "Withdrawn",
      "has_completed_course": true,
      "course_completion": 1,
      "has_ta_for_course": false
    },
    {
      "application_id": 22,
      "course_id": "5678",
      "course_code": "CDA3101",
      "course_name": "Introduction to Computer Organization",
      "rank": 1,
      "match_status": 1,
      "status_label": "Pending",
      "has_completed_course": true,
      "course_completion": 1,
      "has_ta_for_course": true
    }
  ]
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `details` | object | The details as submitted for this term, in the shape of the POST body's `details`. Editing the shared profile in another term doesn't change it. Key order isn't preserved. Migrated applications have an unanswered `profile.english_test_score` |
| `identity` | object | Same as GET: application form |
| `can_edit` | boolean | Whether PATCH: edit an application will be accepted. See below |
| `revision` | string | 64 hex characters. Send it back with an edit |
| `courses` | array | Every course in this term, in the same shape and order as GET: application form |
| `course_preferences` | array | All remaining course applications for the term, including rows withdrawn through ordinary edits, ordered by `rank` then `application_id` |
| `course_preferences[].rank` | integer, *nullable* | The student's preference. A withdrawn application keeps its last rank, so ranks are only unique among the ones that aren't withdrawn |
| `course_preferences[].match_status` | integer | `1` Pending, `2` Matched, `3` Rejected, `4` Withdrawn. `status_label` has the label |

`can_edit` is `true` only when all of these hold:

- the term is the period's term
- `student_application` is the active stage
- every application is `Pending` or `Withdrawn`, so none has been matched or rejected yet

| Status | `code` | When |
| --- | --- | --- |
| 400 | `rest_invalid_param` | `year` is outside 1901 to 2155 |
| 404 | `taas_not_found` | The student has no application for this term |

## PATCH: edit an application

`PATCH /student/application/{semester}/{year}`

`POST` and `PUT` do the same thing. Saves an edit or an explicit replacement for one term. Only works when GET: one application returns `can_edit: true`.

**Request body:** the same body as POST: submit an application, plus the `revision` from GET: one application:

```json
{
  "revision": "d894cb81d47f3a0e6d378e21c15475e8cd0c275c586ed25c54f8a74f0f64b112",
  "term": { "semester": 1, "year": 2026 },
  "details": { "...": "same as POST" },
  "course_preferences": [
    { "course_id": "5678", "rank": 1, "has_completed_course": true, "has_ta_for_course": true }
  ]
}
```

The rules are the same as POST, except:

- `term` must match `{semester}` and `{year}` in the path.
- Edits and replacements require exactly 5 courses for PhD students and 1 to 5 for other students. An empty list on an ordinary edit withdraws every course application. A replacement (`replace_existing: true`) deletes omitted course applications for that term.
- `revision` is required. If the application has changed since it was read, for example in another tab, the edit returns 409. Read it again before retrying.

**What changes:** all course and application details are saved in one transaction.

| Course | Result |
| --- | --- |
| In the list, and the student already had an application for it | Set to `Pending` with the submitted rank, completion answer, and prior TA declaration. An eligible withdrawn application can be reactivated this way |
| In the list, and new | A new `Pending` application |
| Omitted from an ordinary edit | A `Pending` application becomes `Withdrawn`. Its last rank and declarations are kept |
| Omitted from a replacement | The course application row for this term is deleted |

`details` replaces this term's saved details and the shared profile and degrees, in the same way as POST. Other terms' saved details don't change.

A withdrawn application that a professor had already ranked can't come back this way, and the edit returns 409. The admin has to review it.

**Response:** 200 OK with the new `revision`. Use it for the next edit:

```json
{
  "submission_id": 40,
  "revision": "3f0c2a9e5b7d14c86a2e9f0b1d4c7a6e8b3f5d2c9a1e4b7f0c6d8a2e5b9f1c3d",
  "message": "Your application changes were saved."
}
```

| Status | `code` | When |
| --- | --- | --- |
| 400 | `taas_invalid_revision` | `revision` is missing or isn't 64 lower-case hex characters |
| 400 | `taas_invalid_application` | The body breaks a rule from POST: submit an application |
| 400 | `taas_invalid_term` | `term` doesn't match the path |
| 400 | `taas_student_email_mismatch` | Same as POST |
| 400 | `taas_application_not_saved` | A course isn't in this term |
| 400 | `rest_invalid_param` | `year` is outside 1901 to 2155 |
| 403 | `taas_student_email_unavailable` | Same as POST |
| 403 | `taas_application_not_saved` | `can_edit` would be `false` |
| 404 | `taas_application_not_saved` | The student has no application for this term. This is not `taas_not_found` |
| 409 | `taas_application_not_saved` | The application changed since `revision` was read, or a withdrawn course a professor ranked was sent again |

## Changelog

Changes to these routes, newest first.

| Date | Route | Change |
| --- | --- | --- |
