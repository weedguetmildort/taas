# Professor Routes

Routes for a signed-in professor to list their courses, review applicants, and submit a ranking plus notes for matching. The base path, sign-in and shared errors are in the [API overview](README.md).

**Access:** every route here needs the `taas_instructor` role, during the `professor_ranking` stage. A signed-in user without it gets 403, even a `taas_admin`; holding it alongside other roles is fine. WordPress administrators hold it, but only see courses where they are the professor. Reads are closed outside the stage too, with 403 `taas_stage_closed`.

**Ownership:** the professor is identified by their session, never by anything in the request. A course or application that doesn't exist, isn't this professor's, or (applications only) was withdrawn all return the same 404 `taas_not_found`, so the API never reveals which ids exist.

**UFIDs:** no route here takes or returns a UFID. Applicants are identified by `application_id`.

## GET: a professor's courses

`GET /professor/courses?term=SummerB&year=2026`

**Paginated:** no, ordered by `course_id`

| Param | Required | Notes |
| --- | --- | --- |
| `term` | yes | There is no "all terms" view, and no default to the current cycle |
| `year` | yes | |

**Response:** 200 OK with `[]` if the professor teaches nothing that term:

```json
[
  {
    "course_id": 12345,
    "course_code": "COP4930",
    "course_name": "Yet Another SWE Course",
    "course_description": "Team-based software engineering project course.",
    "course_notes": "Looking for TAs with React experience.",
    "is_online": false
  },
  {
    "course_id": 54321,
    "course_code": "COP4930",
    "course_name": "Some Other CISE Elective",
    "course_description": "Special topics elective, offered online.",
    "course_notes": null,
    "is_online": true
  }
]
```

| Field | Type | Notes |
| --- | --- | --- |
| `course_description` | string, *nullable* | Up to 1000 characters |
| `course_notes` | string, *nullable* | The professor's saved notes for matching. Also returned by GET: current ranking |

## GET: applicants for a course

`GET /professor/courses/{course_id}/applications`

**Paginated:** no, ordered by student name. Withdrawn applications are left out.

**Response body:**

```json
{
  "course_id": 12345,
  "course_code": "COP4930",
  "course_name": "Yet Another SWE Course",
  "applications": [
    {
      "application_id": 5,
      "student_name": "Jordan Blake",
      "major": "Computer Science",
      "gpa": 3.72,
      "enrollment_status": "Masters",
      "has_taken_course": true,
      "has_ta_for_course": false
    },
    {
      "application_id": 6,
      "student_name": "Priya Raman",
      "major": "Computer Science",
      "gpa": 3.85,
      "enrollment_status": "PhD",
      "has_taken_course": false,
      "has_ta_for_course": false
    }
  ]
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `application_id` | integer | Use it for GET: one applicant's details and in rankings |
| `major` | string, *nullable* | May list several, like `"Computer Science, Business"` |
| `gpa` | number, *nullable* | 0.00 to 4.00 |
| `enrollment_status` | string, *nullable* | The student's current program |
| `has_taken_course` | boolean | Has taken this course |
| `has_ta_for_course` | boolean | Has TA'd this course before |

A student with no profile still appears, with `major`, `gpa` and `enrollment_status` all `null`.

This list doesn't include ranks. Get those from GET: current ranking and merge them in by `application_id`.

## GET: current ranking

`GET /professor/courses/{course_id}/ranking`

This has the same shape as the POST request body, so a page can load the saved state, edit it and send it back.

**Response body:**

```json
{
  "course_id": 12345,
  "ranking": [
    { "rank": 1, "application_id": 5 },
    { "rank": 2, "application_id": 6 }
  ],
  "course_notes": "Looking for TAs with React experience."
}
```

- `ranking` lists only ranked applicants, in rank order. `[]` means nothing is ranked yet.
- Any applicant from GET: applicants for a course who isn't in `ranking` is unranked, and unranked applicants count as equal last. Don't give them an implied order.
- `course_notes` is *nullable*.
- Withdrawn applications are left out, so a withdrawal can leave a gap in the ranks. Submitting the ranking again fixes it.

## POST: submit the ranking

`POST /professor/courses/{course_id}/ranking`

**Request body:**

```json
{
  "ranking": [
    { "rank": 1, "application_id": 6 },
    { "rank": 2, "application_id": 5 }
  ],
  "course_notes": "Optional text, max 1000 characters"
}
```

**Rules for `ranking`:**

- It is required, and is a full replace: the submitted list becomes the whole ranking, and anyone not in it becomes unranked. Send `[]` to clear every rank.
- Ranks must be exactly `1..n`, with no ties, gaps or zero. Entry order in the array doesn't matter.
- Each `application_id` may appear once and must be a non-withdrawn application for this course.
- Each entry has exactly the keys `rank` and `application_id`, both integers.

**Rules for `course_notes`:**

| Value sent | Result |
| --- | --- |
| Omitted | The notes stay as they are |
| `null`, `""` or only whitespace | The notes are cleared |
| A string, up to 1000 characters | Replaces the notes |

Notes are saved as plain text exactly as sent, apart from trimming leading and trailing whitespace, so line breaks, `<` and `&` come back unchanged. Render them as text, not HTML.

The ranking and the notes are saved together: either both are saved or neither is.

**Response:** 200 OK with the saved state, the same body as GET: current ranking.

| Status | `code` | When |
| --- | --- | --- |
| 400 | `rest_missing_callback_param` | `ranking` is missing |
| 400 | `rest_invalid_param` | The shape is wrong, such as `ranking` not being an array, an extra key, `rank` below 1, or `course_notes` over 1000 characters |
| 400 | `taas_invalid_ranking` | The ranking is well formed but breaks a rule. See `data.reason` below |
| 404 | `taas_not_found` | See Ownership at the top of this page |

```json
{
  "code": "taas_invalid_ranking",
  "message": "The ranking is not valid.",
  "data": { "status": 400, "reason": "duplicate_rank" }
}
```

| `reason` | Meaning |
| --- | --- |
| `duplicate_rank` | Two entries share a rank |
| `duplicate_application` | An application appears twice |
| `rank_gap` | The ranks aren't exactly `1..n`, such as 1, 3 |
| `ineligible_application` | An `application_id` that isn't a non-withdrawn application for this course |
| `not_a_list` | `ranking` isn't a list |
| `malformed_entry` | An entry is missing `rank` or `application_id`, or has the wrong type |
| `rank_out_of_range` | A rank is below 1 |

WordPress's own validation usually catches the last three first, and returns `rest_invalid_param` or `rest_missing_callback_param`. Handle both error shapes.

## GET: one applicant's details

`GET /professor/applications/{application_id}`

**Response body:**

```json
{
  "application_id": 6,
  "student_email": "praman@ufl.edu",
  "student_name": "Priya Raman",
  "major": "Computer Science",
  "gpa": 3.85,
  "enrollment_status": "PhD",
  "has_taken_course": false,
  "has_ta_experience": false,
  "has_ta_for_course": false,
  "academic_background": [
    {
      "university": "University of Florida",
      "degree_type": "MS",
      "major": "Computer Science",
      "minor": null,
      "grad_date": "2026-12-01"
    },
    {
      "university": "IIT Madras",
      "degree_type": "BS",
      "major": "Electrical Engineering",
      "minor": null,
      "grad_date": "2024-06-01"
    }
  ],
  "country_code": "IN",
  "eap5836_status": "Taken at UF",
  "eap5837_status": "Currently taking",
  "resume_url": null,
  "speak_score": null,
  "speak_date": null,
  "student_statement": null,
  "toefl_ibt_score": 108,
  "toefl_ibt_date": "2025-09-13"
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `major`, `gpa`, `enrollment_status` | *nullable* | Same as GET: applicants for a course |
| `has_ta_experience` | boolean | Has TA'd any course before. `has_ta_for_course` is this course only |
| `academic_background` | array | Most recent `grad_date` first. May be `[]` |
| `academic_background[].degree_type` | string | |
| `academic_background[].major`, `.minor` | string, *nullable* | |
| `academic_background[].grad_date` | string | `YYYY-MM-DD`, always the 1st of the month. Display it as `MM/YYYY` |
| `country_code` | string, *nullable* | ISO 3166-1 alpha-2 |
| `eap5836_status`, `eap5837_status` | string, *nullable* | PhD students only |
| `resume_url` | string, *nullable* | An external link. Open it in a new tab |
| `speak_score` | integer, *nullable* | International students only |
| `speak_date` | string, *nullable* | `YYYY-MM-DD` |
| `student_statement` | string, *nullable* | Up to 500 characters |
| `toefl_ibt_score` | number, *nullable* | International students only |
| `toefl_ibt_date` | string, *nullable* | `YYYY-MM-DD` |

## Changelog

Changes to these routes, newest first.

| Date | Route | Change |
| --- | --- | --- |
