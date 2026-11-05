# Admin Routes

Routes for the admin. All of them work in every stage, and a user without the admin role gets 403. WordPress administrators hold every role, admin included. The base path, sign-in and shared errors are in the [API overview](README.md).

The admin also uses the routes in [common.md](common.md), including `GET /application-cycle` and `GET /courses`.

## Application cycle

### PUT: set application cycle

`PUT /application-cycle`

Starts a new cycle, replacing the current one and all of its dates.

**Request body:**

```json
{
  "term": "Spring",
  "year": 2027,
  "stage_dates": {
    "student_application": {
      "start_at": "2026-10-15T04:00:00Z",
      "end_at": "2026-11-16T04:59:59Z"
    },
    "hr_review": null,
    "professor_ranking": null,
    "admin_matching": null
  }
}
```

`term` and `year` are required. `stage_dates` is optional, and with no dates no stage is active.

**Response:** 200 OK, in the same shape as [GET: current application cycle](common.md#get-current-application-cycle). `stage` reflects the new dates straight away, so a `start_at` that has already come starts that stage immediately.

| Status | `code` | When |
| --- | --- | --- |
| 400 | `rest_invalid_param` | See [Date rules](#date-rules) |

### PATCH: change dates

`PATCH /application-cycle`

**Request body:** `stage_dates`, which is required. To schedule HR review:

```json
{
  "stage_dates": {
    "hr_review": {
      "start_at": "2026-11-16T05:00:00Z",
      "end_at": "2026-11-23T04:59:59Z"
    }
  }
}
```

Only the stages named change, and the others keep their dates.

**Response:** 200 OK, in the same shape as [GET: current application cycle](common.md#get-current-application-cycle).

| Status | `code` | When |
| --- | --- | --- |
| 400 | `rest_invalid_param` | See [Date rules](#date-rules) |
| 404 | `taas_no_current_cycle` | No cycle has been set |

**How the admin controls stages:**

| To | Send for that stage |
| --- | --- |
| Schedule it | Future `start_at` and `end_at`. It starts and ends by itself |
| Start it now | `start_at` set to the current time |
| End it early or pause it | `end_at` set to the current time |
| Extend or reopen it | A later `end_at` |
| Go back to it | New dates for it, and later dates for any stage after it, since a later stage that has started stays current |
| Stop it from starting | `null`, or a different `start_at` |

`stage` can't be set directly, and is ignored if sent. Ending a stage early replaces its planned `end_at`, so to resume on the original schedule after a pause, send that date again.

Applications close when `student_application` reaches its `end_at`, which the admin can move earlier.

### Date rules

These apply to `stage_dates` on PUT and PATCH:

- A stage's entry has both `start_at` and `end_at`, either of which can be `null`, or is `null` itself to clear its dates.
- Timestamps must be UTC, like `2026-11-16T04:59:59Z`.
- `end_at` can't be before `start_at`.
- Two stages' dates can't overlap, and a moment shared by two stages counts as overlapping. Only stages with both dates are compared.

## Courses

To read courses, use [GET: course listings](common.md#get-course-listings) and [GET: one course](common.md#get-one-course).

### POST: add a course

`POST /courses`

**Request body:**

```json
{
  "course_code": "COP3502C",
  "course_name": "Programming Fundamentals 1",
  "term": "Spring",
  "year": 2027,
  "professor_id": 3,
  "enrollment": 175,
  "max_enrollment": 200,
  "lab_hours": 2,
  "is_online": false,
  "course_description": null
}
```

| Field | Required | Notes |
| --- | --- | --- |
| `course_code` | yes | Up to 20 characters |
| `course_name` | yes | Up to 255 characters |
| `term`, `year` | yes | |
| `professor_id` | yes | Must be an existing professor, from [GET: professors](#get-professors) |
| `enrollment` | no | Whole number, default 0 |
| `max_enrollment` | no | Whole number, default 0 |
| `lab_hours` | no | 0, 1 or 2, default 0 |
| `is_online` | no | Default `false` |
| `course_description` | no | Up to 1000 characters, or `null` |

`weekly_hours` is calculated and `course_notes` is written by the professor, so neither is accepted here.

**Response:** 201 Created with the new course, in the same shape as one item in [GET: course listings](common.md#get-course-listings).

| Status | `code` | When |
| --- | --- | --- |
| 400 | `rest_invalid_param` | A field is invalid, or `professor_id` isn't an existing professor |
| 409 | `taas_course_exists` | The same term, year, code and name already exists |

### PATCH: edit a course

`PATCH /courses/{course_id}`

**Request body:** any of the fields from POST: add a course. Fields left out keep their values, and `weekly_hours` is recalculated.

**Response:** 200 OK with the updated course, in the same shape as one item in [GET: course listings](common.md#get-course-listings).

| Status | `code` | When |
| --- | --- | --- |
| 400 | `rest_invalid_param` | A field is invalid, or `professor_id` isn't an existing professor |
| 404 | `taas_not_found` | No such course |
| 409 | `taas_course_exists` | The edit would duplicate another course |
| 409 | `taas_course_has_applications` | `term` or `year` changes on a course that has applications |

### DELETE: delete a course

`DELETE /courses/{course_id}`

**Response:** 204 No Content.

| Status | `code` | When |
| --- | --- | --- |
| 404 | `taas_not_found` | No such course |
| 409 | `taas_course_has_applications` | The course has any application, including withdrawn ones |

## Professors

### GET: professors

`GET /professors`

Lists professors, and is used by the course forms to pick one.

**Paginated:** yes, ordered by `professor_id`. With `search`, ordered by name.

**Request params:** `?search=smi` (optional), which matches part of the name or email.

**Response body:**

```json
[
  {
    "professor_id": 3,
    "professor_ufid": "87654321",
    "professor_name": "Dr. Smith",
    "professor_email": "smith@ufl.edu"
  }
]
```

`professor_ufid` is `null` until the professor first signs in. Course import adds professors by email, since the admin does not have their UFIDs.

### GET: one professor

`GET /professors/{professor_id}`

**Response:** one professor, in the same shape as one item in GET: professors, or 404 `taas_not_found`.

## Students

These routes are read-only views of student records.

### GET: students

`GET /students`

**Paginated:** yes, ordered by UFID

**Response body:**

```json
[
  {
    "student_ufid": "20000001",
    "student_name": "Jordan Blake",
    "student_email": "jblake@ufl.edu"
  }
]
```

### GET: one student

`GET /students/{student_ufid}`

**Response:** one student, in the same shape as one item in GET: students, or 404 `taas_not_found`.

### GET: student profiles

`GET /student-profiles`

**Paginated:** yes, ordered by UFID

**Response body:**

```json
[
  {
    "student_ufid": "20000002",
    "phone": "3525550102",
    "admitted_semester": "Spring 2026",
    "available_hours": 10,
    "major": "Computer Science",
    "gpa": 3.85,
    "enrollment_status": "PhD",
    "resume_url": null,
    "speak_score": null,
    "speak_date": null,
    "toefl_ibt_score": 108,
    "toefl_ibt_date": "2025-09-13",
    "has_ta_experience": false,
    "eap5836_status": "Taken at UF",
    "eap5837_status": "Currently taking",
    "country_code": "IN",
    "additional_info": null
  }
]
```

Every field except `student_ufid` and `has_ta_experience` is *nullable*.

| Field | Notes |
| --- | --- |
| `available_hours` | `null` means the student has not provided it, which is different from 0 |
| `major` | May list several, like `"Computer Science, Business"` |
| `gpa` | 0.00 to 4.00 |
| `enrollment_status` | The student's current program |
| `resume_url` | A link to an external document |
| `speak_score`, `toefl_ibt_score` | International students only |
| `has_ta_experience` | Has TA'd any course before |
| `eap5836_status`, `eap5837_status` | PhD students only |
| `country_code` | ISO 3166-1 alpha-2 |
| `additional_info` | The student's notes for anyone reading their profile, up to 1000 characters |

### GET: one student profile

`GET /student-profiles/{student_ufid}`

**Response:** one profile, in the same shape as one item in GET: student profiles, or 404 `taas_not_found`.

### GET: academic backgrounds

`GET /academic-backgrounds`

One row per degree or program a student has completed or is completing, so a student can have several.

**Paginated:** yes, ordered by `background_id`

**Response body:**

```json
[
  {
    "background_id": 1,
    "student_ufid": "20000001",
    "university": "University of Florida",
    "degree_type": "BS",
    "major": "Computer Science",
    "minor": null,
    "grad_date": "2025-05-01",
    "gpa": 3.72,
    "grad_label": "05/2025"
  }
]
```

| Field | Notes |
| --- | --- |
| `major`, `minor`, `gpa` | *Nullable* |
| `grad_date` | `YYYY-MM-DD`, always the 1st of the month |
| `grad_label` | `grad_date` as `MM/YYYY`, for display |

### GET: one academic background

`GET /academic-backgrounds/{background_id}`

**Response:** one row, in the same shape as one item in GET: academic backgrounds, or 404 `taas_not_found`.

## Applications

### GET: applications

`GET /applications`

**Paginated:** yes, ordered by `application_id`

**Response:** a list of applications, each in the same shape as the [PATCH response](#patch-make-change-or-undo-a-match-or-reject-an-applicant). To list one course's applicants, use [GET: a course's applicants](#get-a-courses-applicants).

### GET: one application

`GET /applications/{application_id}`

**Response:** one application, in the same shape as the [PATCH response](#patch-make-change-or-undo-a-match-or-reject-an-applicant), or 404 `taas_not_found`.

### PATCH: make, change or undo a match, or reject an applicant

`PATCH /applications/{application_id}`

**Request body, to make a match or change its hours:**

```json
{
  "match_status": "Matched",
  "assigned_hours": 20
}
```

**Request body, to undo a match:**

```json
{
  "match_status": "Pending"
}
```

**Request body, to reject one applicant:**

```json
{
  "match_status": "Rejected"
}
```

**Response:** 200 OK with the updated application:

```json
{
  "application_id": 1,
  "student_ufid": "12345678",
  "applied_course_id": 101,
  "has_taken_course": true,
  "has_ta_for_course": false,
  "student_statement": null,
  "student_preference_rank": 1,
  "professor_preference_rank": 1,
  "match_status": "Matched",
  "assigned_hours": 20
}
```

**Rules:**

- `match_status` is required, and may be `Matched`, `Pending` or `Rejected`, including on a `Rejected` application, so a rejection can be reversed.
- `assigned_hours` is required, and at least 1, when setting `Matched`.
- Setting `Pending` or `Rejected` resets `assigned_hours` to 0, and any hours sent are ignored.

| Status | `code` | When |
| --- | --- | --- |
| 400 | `rest_missing_callback_param` | `match_status` is missing, or `assigned_hours` is missing when setting `Matched` |
| 400 | `rest_invalid_param` | `match_status` is another value, or `assigned_hours` is under 1 when setting `Matched` |
| 404 | `taas_not_found` | No such application |
| 409 | `taas_application_withdrawn` | The application is withdrawn, so it can't be changed |

The server does not compare a match to the recommendations, rankings, the course's remaining hours or the student's `available_hours`. This route does not send offers or notifications.

## Matching

The admin decides every match. A match is not a separate record. It is an application with `match_status` = `Matched` and `assigned_hours` = the TA hours the admin assigned, so only an applicant to a course can be matched to it. A course can have several matches, because `weekly_hours` is the total TA hours for the course.

Professor rankings inform the admin but never block anything. A course with no rankings can still have its applicants matched.

The admin works in three steps:

1. **Review recommendations** from the matching algorithm. These are only advice.
2. **Make matches** with [PATCH /applications/{application_id}](#patch-make-change-or-undo-a-match-or-reject-an-applicant), recommended or not.
3. **Finalize**, which rejects every application still `Pending`.

### GET: recommended matches

`GET /matching/recommendations`

The matching algorithm is not connected to this route yet, so it currently returns `[]`.

**Paginated:** yes

**Request params (all optional):**

| Param | Notes |
| --- | --- |
| `term`, `year` | Defaults to the current application cycle's term |
| `student_ufid` | Returns only the courses recommending this student, with only that student in each `recommendations` list |

**Response body:**

```json
[
  {
    "course_id": 101,
    "course_code": "COP3502C",
    "course_name": "Programming Fundamentals 1",
    "weekly_hours": 70,
    "unfilled_hours": 30,
    "recommendations": [
      {
        "application_id": 1,
        "student_ufid": "12345678",
        "student_name": "Jane Doe",
        "hours": 20,
        "reason": "professor_preference_1"
      }
    ]
  }
]
```

| Field | Notes |
| --- | --- |
| `unfilled_hours` | How many of the course's `weekly_hours` the recommendations leave uncovered. It does not reflect matches the admin has made |
| `recommendations` | In the order the algorithm chose the students |
| `hours` | How many hours the algorithm recommends for that student |
| `reason` | Why the algorithm chose the student |

| `reason` | Meaning |
| --- | --- |
| `professor_preference_N` | Professor ranked them N and they also ranked the course |
| `student_preference_N` | They ranked this course N |
| `ta_for_course` | Previously TA'd this course |
| `taken_course` | Has taken this course |
| `ta_experience` | Has TA'd another course |
| `applied` | Any other applicant |

To accept a recommendation, send its `application_id` and `hours` to [PATCH /applications/{application_id}](#patch-make-change-or-undo-a-match-or-reject-an-applicant).

### GET: recommended matches for one course

`GET /matching/recommendations/{course_id}`

**Response:** one course, in the same shape as one item in GET: recommended matches, or 404 `taas_not_found`. A course with no recommendations returns 200 with `recommendations: []` and `unfilled_hours` equal to `weekly_hours`.

### GET: a course's applicants

`GET /matching/courses/{course_id}/applicants`

The list the admin chooses from when making a match. Returns 404 `taas_not_found` for an unknown course.

**Paginated:** no

**Response body:**

```json
{
  "course_id": 101,
  "course_notes": "Text from the professor, max 1000 characters",
  "applicants": [
    {
      "application_id": 1,
      "student_ufid": "12345678",
      "student_name": "Jane Doe",
      "professor_preference_rank": 1,
      "student_preference_rank": 1,
      "available_hours": 20,
      "has_taken_course": true,
      "has_ta_for_course": false,
      "match_status": "Matched",
      "assigned_hours": 20
    },
    {
      "application_id": 2,
      "student_ufid": "23456789",
      "student_name": "Sam Lee",
      "professor_preference_rank": null,
      "student_preference_rank": 1,
      "available_hours": null,
      "has_taken_course": false,
      "has_ta_for_course": false,
      "match_status": "Pending",
      "assigned_hours": 0
    }
  ]
}
```

This lists every non-withdrawn applicant. Students the professor ranked come first in rank order, followed by unranked students by name, all tied at the bottom. `student_preference_rank` doesn't affect the order. `available_hours: null` means the student has not provided it, which is different from 0.

### GET: current matches by course

`GET /matching/courses`

**Paginated:** yes, ordered by `course_code`, then `course_name`

**Request params:** `term` and `year` (optional), defaulting to the current application cycle's term

**Response body:**

```json
[
  {
    "course_id": 101,
    "course_code": "COP3502C",
    "course_name": "Programming Fundamentals 1",
    "professor_name": "Dr. Smith",
    "weekly_hours": 70,
    "assigned_hours": 20,
    "matches": [
      {
        "application_id": 1,
        "student_ufid": "12345678",
        "student_name": "Jane Doe",
        "assigned_hours": 20
      }
    ]
  }
]
```

`matches` lists the course's `Matched` applications, and the course's `assigned_hours` is their total. Courses have no match status, so display follows from `matches`, `assigned_hours` and `weekly_hours`.

### GET: current matches for one course

`GET /matching/courses/{course_id}`

**Response:** one course, in the same shape as one item in GET: current matches by course, or 404 `taas_not_found`.

### GET: current matches by student

`GET /matching/students`

**Paginated:** yes, ordered by `student_name`

**Request params:** `term` and `year` (optional), defaulting to the current application cycle's term

**Response body:**

```json
[
  {
    "student_ufid": "12345678",
    "student_name": "Jane Doe",
    "student_email": "jane@ufl.edu",
    "available_hours": 20,
    "assigned_hours": 20,
    "matches": [
      {
        "application_id": 1,
        "course_id": 101,
        "course_code": "COP3502C",
        "assigned_hours": 20
      }
    ]
  }
]
```

This lists every student with a non-withdrawn application that term. A student can have several matches, for example 10 hours on each of two courses, and `assigned_hours` is their total. `matches` is `[]` for a student with no matches.

### GET: current matches for one student

`GET /matching/students/{student_ufid}`

**Request params:** `term` and `year` (optional), defaulting to the current application cycle's term

**Response:** one student, in the same shape as one item in GET: current matches by student. Returns 404 `taas_not_found` if the student has no non-withdrawn application that term.

### GET: matching progress

`GET /matching/progress`

**Request params:** `term` and `year` (optional), defaulting to the current application cycle's term

**Response body:**

```json
{
  "matches_made": 15,
  "applications_pending": 40,
  "hours_assigned": 300,
  "hours_required": 2400
}
```

| Field | Notes |
| --- | --- |
| `matches_made` | `Matched` applications for the term |
| `applications_pending` | `Pending` applications for the term, which is how many finalizing would reject |
| `hours_assigned` | Total `assigned_hours` on `Matched` applications |
| `hours_required` | Total of the term's `weekly_hours` |

For one course's hours, use GET: current matches for one course.

### POST: finalize matches

`POST /matching/finalize`

Sets every `Pending` application in the term to `Rejected`. `Matched`, `Rejected` and `Withdrawn` applications do not change. A matched student's other `Pending` applications become `Rejected`, while their match stays.

**Request params:** `term` and `year` (optional), defaulting to the current application cycle's term

**Request body:** none

**Response:** 200 OK:

```json
{
  "term": "Spring",
  "year": 2027,
  "applications_rejected": 40,
  "matches_made": 15
}
```

| Status | `code` | When |
| --- | --- | --- |
| 404 | `taas_no_current_cycle` | No term was sent, and no cycle has been set |
| 409 | `taas_matching_not_open` | The current stage isn't `admin_matching`, or the term isn't the current cycle's |

`stage` being `null` isn't enough, because it is also `null` between earlier stages, before HR or professors have seen the applications. To finalize after `admin_matching` has ended, first move its `end_at` later.

Only this route rejects applications in bulk. `admin_matching` ending leaves applications as they are, so a date passing never rejects students without the admin deciding to.

Running it again is safe, and rejects only what has become `Pending` since. A rejected application can still be changed with PATCH. This route does not send notifications.

## Changelog

Changes to these routes, newest first.

| Date | Route | Change |
| --- | --- | --- |
